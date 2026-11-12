<?php
namespace App\Modules\Workspace;
use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\AccessControl\GroupAssignments;
use App\Modules\AccessControl\GrantGraph;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\DataPlatform\Journal;
use App\Modules\Subscription\AccountSubscriptionService;
use App\Modules\Subscription\PropertyCloud;
use App\Modules\Workspace\Models\{Workspace,OwnerTransferCase,OwnerTransferEvidence};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Hash,Storage};

/** Internal management handover; financial documents and their original actors stay intact. */
final class OwnerTransferService
{
    public function visible(User$actor)
    {
        abort_unless(User::whereKey($actor->id)->where('status','active')->exists(),403);
        $q=OwnerTransferCase::query();
        if(!app(Authorization::class)->global($actor,'workspace.transfer.review'))$q->where(fn($x)=>$x->where('source_owner_id',$actor->id)->orWhere('recipient_id',$actor->id));
        return$q;
    }
    private function current(User$actor,int$id,int$version):OwnerTransferCase
    {
        $case=$this->visible($actor)->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless($case->version===$version&&!in_array($case->status,['cancelled','rejected','completed'],true)&&$case->expires_at->isFuture(),409,'Hồ sơ đã thay đổi, hết hạn hoặc được kết thúc.');
        $w=Workspace::whereKey($case->workspace_id)->lockForUpdate()->firstOrFail();
        abort_unless($w->owner_id===$case->source_owner_id&&$w->version===$case->workspace_version,409,'Thông tin khu quản lý đã đổi. Cần lập và xác nhận hồ sơ mới.');return$case;
    }
    private function transaction(OwnerTransferCase$case,callable$operation):mixed
    {
        return \App\Support\SqlTransactions::run(function()use($case,$operation){User::whereIn('id',[$case->source_owner_id,$case->recipient_id])->orderBy('id')->lockForUpdate()->get();return$operation();});
    }
    public function submit(User$actor,Workspace$w,string$email,string$reference,string$reason):OwnerTransferCase
    {
        abort_unless($w->owner_id===$actor->id&&app(AccessService::class)->canManageLifecycle($actor,$w),403);
        $recipient=User::where('email',mb_strtolower(trim($email)))->where('status','active')->firstOrFail();abort_if($recipient->id===$actor->id,422,'Người nhận phải là tài khoản khác.');
        return app(OwnerQuota::class)->transaction($actor->id,function()use($actor,$w,$recipient,$reference,$reason){
            $w=Workspace::whereKey($w->id)->lockForUpdate()->firstOrFail();abort_unless($w->owner_id===$actor->id&&app(AccessService::class)->canManageLifecycle($actor,$w),403);
            $old=OwnerTransferCase::where('reference',$reference)->first();if($old){abort_unless($old->source_owner_id===$actor->id&&$old->workspace_id===$w->id&&$old->recipient_id===$recipient->id,409);return$old;}
            $case=OwnerTransferCase::create(['workspace_id'=>$w->id,'source_owner_id'=>$actor->id,'recipient_id'=>$recipient->id,'reference'=>$reference,'reason'=>$reason,'workspace_version'=>$w->version,'consents'=>[],'reviews'=>[],'blockers'=>[],'terms_hash'=>hash('sha256',json_encode([$w->id,$w->version,$actor->id,$recipient->id,$reason])),'expires_at'=>now()->addHours(config('ownership.consent_hours'))]);
            $case->update(['consents'=>[(string)$actor->id=>$this->confirmation($actor,$case)]]);
            app(Journal::class)->record($actor->id,'ownership.submitted',$case,null,['workspace_id'=>$w->id,'recipient_id'=>$recipient->id]);return$case->fresh();
        });
    }
    public function consent(User$actor,int$id,int$version,string$password):void
    {
        $initial=OwnerTransferCase::findOrFail($id);
        $this->transaction($initial,function()use($actor,$id,$version,$password){
            $case=$this->current($actor,$id,$version);abort_unless(in_array($actor->id,[$case->source_owner_id,$case->recipient_id],true)&&User::find($actor->id)?->status==='active',403);
            abort_unless(Hash::check($password,User::findOrFail($actor->id)->password),422,'Mật khẩu xác nhận không đúng.');
            $consents=$case->consents;$consents[(string)$actor->id]=$this->confirmation(User::findOrFail($actor->id),$case);
            $case->update(['consents'=>$consents,'status'=>count($consents)===2?'pending_review':'pending_consent','version'=>$case->version+1,'reviews'=>[]]);
            app(Journal::class)->record($actor->id,'ownership.consented',$case,null,['terms_hash'=>$case->terms_hash]);
            if(count($consents)===2){
                $blockers=$this->blockers($case);
                if($blockers)$case->update(['status'=>'blocked','blockers'=>$blockers]);
                else $this->complete($actor,$case);
            }
        });
    }
    private function confirmation(User $actor,OwnerTransferCase $case):array
    {
        return ['hash'=>$case->terms_hash,'at'=>now()->toIso8601String(),'credential'=>hash_hmac('sha256',$actor->password,config('app.key'))];
    }
    public function evidence(User$actor,int$id,int$version,UploadedFile$file):void
    {
        $initial=OwnerTransferCase::findOrFail($id);$path=null;
        try{$this->transaction($initial,function()use($actor,$id,$version,$file,&$path){
            $case=$this->current($actor,$id,$version);abort_unless(in_array($actor->id,[$case->source_owner_id,$case->recipient_id],true),403);
            $path=$file->store('ownership-evidence','local');
            $e=OwnerTransferEvidence::create(['owner_transfer_case_id'=>$id,'uploaded_by'=>$actor->id,'version'=>(int)OwnerTransferEvidence::where('owner_transfer_case_id',$id)->max('version')+1,'path'=>$path,'filename'=>basename($file->getClientOriginalName()),'mime'=>$file->getMimeType(),'bytes'=>$file->getSize(),'checksum'=>hash_file('sha256',Storage::disk('local')->path($path))]);
            $case->update(['version'=>$case->version+1,'terms_hash'=>hash('sha256',$case->terms_hash.':'.$e->checksum),'consents'=>[],'reviews'=>[],'status'=>'pending_consent']);
            app(Journal::class)->record($actor->id,'ownership.evidence_added',$e,null,['case_id'=>$id,'version'=>$e->version]);
        });}catch(\Throwable$e){if($path&&str_starts_with($path,'ownership-evidence/'))Storage::disk('local')->delete($path);throw$e;}
    }
    public function blockers(OwnerTransferCase$case):array
    {
        $out=[];
        if(count($case->consents)!==2||collect($case->consents)->contains(fn($c)=>$c['hash']!==$case->terms_hash))$out[]='Cần xác nhận hợp lệ từ cả hai bên.';
        foreach($case->consents as$id=>$consent){$u=User::find($id);if(!$u||$u->status!=='active'||!hash_equals($consent['credential']??'',hash_hmac('sha256',$u->password,config('app.key'))))$out[]='Một xác nhận không còn hợp lệ sau thay đổi bảo mật tài khoản.';}
        $s=app(AccountSubscriptionService::class)->current($case->recipient_id);
        if(!$s||!in_array($s->status,['active','trial'],true)||$s->expires_at->lte(now()))$out[]='Người nhận chưa có gói dịch vụ cấp tài khoản đủ điều kiện.';
        if(DB::table('account_restrictions')->where('owner_id',$case->recipient_id)->whereNull('released_at')->exists()||DB::table('workspace_restrictions')->where('workspace_id',$case->workspace_id)->whereNull('released_at')->exists())$out[]='Cần xử lý các hạn chế đang có.';
        $workspace=Workspace::findOrFail($case->workspace_id);
        if($workspace->purged_at||!in_array($workspace->lifecycle,['active','paused'],true)||$workspace->status==='pending')$out[]='Khu quản lý chưa ở trạng thái có thể bàn giao.';
        try{
            $quota=app(OwnerQuota::class);$quota->admit($case->recipient_id,'workspaces');
            $quota->admit($case->recipient_id,'properties',$workspace->properties()->whereNull('retired_at')->count());
            $quota->admit($case->recipient_id,'rooms',\App\Modules\Property\Models\Room::whereIn('property_id',$workspace->properties()->select('id'))->whereNull('retired_at')->count());
            $staff=\App\Modules\Workspace\Models\Membership::where('workspace_id',$workspace->id)->where('status','active')->where('user_id','!=',$case->recipient_id)->pluck('user_id')->unique()->all();
            $quota->admit($case->recipient_id,'staff',count(array_diff($staff,app(StaffCapacity::class)->activeAccounts($case->recipient_id))));
        }catch(\Symfony\Component\HttpKernel\Exception\HttpException$e){$out[]='Gói của người nhận chưa xác định hoặc chưa đủ hạn mức khu quản lý, nhà, phòng và nhân sự.';}
        return$out;
    }
    private function complete(User $actor,OwnerTransferCase $case):void
    {
        abort_unless(\Illuminate\Support\Facades\Schema::hasColumns('owner_transfer_cases',['completed_at','completion_snapshot']),503,'Bàn giao đang chờ cập nhật dữ liệu an toàn.');
        $workspace=Workspace::whereKey($case->workspace_id)->lockForUpdate()->firstOrFail();
        abort_unless($workspace->owner_id===$case->source_owner_id&&$workspace->version===$case->workspace_version,409,'Khu quản lý vừa được thay đổi.');
        $subscription=app(AccountSubscriptionService::class)->current($case->recipient_id);
        $before=$workspace->only(['owner_id','plan','expires_at','status','lifecycle','cloud_epoch','version']);
        // Stored receiving snapshots remain valid for obligations already issued.
        // Future requests require the new manager to deliberately select a payee.
        $defaults=DB::table('receiving_account_defaults')->where('workspace_id',$workspace->id)->get();
        DB::table('receiving_account_defaults')->where('workspace_id',$workspace->id)->delete();
        GroupAssignment::where('workspace_id',$workspace->id)->where('user_id',$case->source_owner_id)->where('origin','ownership')->whereNull('revoked_at')->update(['revoked_at'=>now()]);
        app(WorkspaceLifecycle::class)->suppressPending($workspace);
        $workspace->update(['owner_id'=>$case->recipient_id,'plan'=>$subscription->planVersion->plan,'expires_at'=>$subscription->expires_at,'status'=>$subscription->status==='trial'?'trial':'active','version'=>$workspace->version+1,'cloud_epoch'=>$workspace->cloud_epoch+1,'cloud_cutoff_at'=>now(),'cloud_resumed_at'=>now()]);
        app(GroupAssignments::class)->owner($workspace->fresh());
        app(GrantGraph::class)->reconcile();
        $case->update(['status'=>'completed','completed_at'=>now(),'blockers'=>[],'completion_snapshot'=>['before'=>$before,'after'=>$workspace->fresh()->only(array_keys($before)),'cleared_receiving_defaults'=>$defaults->map->only(['scope_key','receiving_account_id'])->all(),'financial_records_rewritten'=>false],'version'=>$case->version+1]);
        OwnerTransferCase::where('workspace_id',$workspace->id)->whereKeyNot($case->id)->whereIn('status',['pending_consent','pending_review','blocked'])->update(['status'=>'cancelled','version'=>DB::raw('version+1')]);
        app(Journal::class)->record($actor->id,'ownership.completed',$case,null,['workspace_id'=>$workspace->id,'source_owner_id'=>$case->source_owner_id,'recipient_id'=>$case->recipient_id,'historical_actors_changed'=>false]);
        foreach([$case->source_owner_id,$case->recipient_id]as$person)app(Journal::class)->notify($person,'Đã bàn giao quyền quản lý',$workspace->name.' đã được bàn giao. Hồ sơ thuê, chứng từ và lịch sử được giữ nguyên.');
        abort_unless(app(Authorization::class)->allowed(User::findOrFail($case->recipient_id),'workspace.view',$workspace->fresh()),403);
    }
    public function review(User$actor,int$id,int$version,string$decision,string$note):void
    {
        abort_unless(app(Authorization::class)->global($actor,'workspace.transfer.review'),403);$initial=OwnerTransferCase::findOrFail($id);
        $this->transaction($initial,function()use($actor,$id,$version,$decision,$note){
            $case=$this->current($actor,$id,$version);abort_if(in_array($actor->id,[$case->source_owner_id,$case->recipient_id],true),403,'Người thẩm tra phải độc lập với hai bên.');
            abort_unless(in_array($decision,['review','reject'],true)&&trim($note)!=='',422);
            $reviews=$case->reviews;$reviews[(string)$actor->id]=['hash'=>$case->terms_hash,'decision'=>$decision,'note'=>$note,'at'=>now()->toIso8601String()];
            $case->update(['reviews'=>$reviews,'blockers'=>$this->blockers($case),'status'=>$decision==='reject'?'rejected':'blocked','version'=>$case->version+1]);
            app(Journal::class)->record($actor->id,'ownership.reviewed',$case,null,['decision'=>$decision,'note'=>$note,'owner_changed'=>false]);
            abort_unless(app(Authorization::class)->global($actor,'workspace.transfer.review'),403);
        });
    }
    public function cancel(User$actor,int$id,int$version,string$reason):void
    {
        $initial=OwnerTransferCase::findOrFail($id);$this->transaction($initial,function()use($actor,$id,$version,$reason){$case=$this->current($actor,$id,$version);abort_unless($actor->id===$case->source_owner_id&&trim($reason)!=='',403);$case->update(['status'=>'cancelled','version'=>$case->version+1]);app(Journal::class)->record($actor->id,'ownership.cancelled',$case,null,['reason'=>$reason]);});
    }
    public function file(User$actor,int$caseId,int$evidenceId):OwnerTransferEvidence
    {
        $this->visible($actor)->findOrFail($caseId);$file=OwnerTransferEvidence::where('owner_transfer_case_id',$caseId)->findOrFail($evidenceId);
        abort_unless(Storage::disk('local')->exists($file->path)&&hash_equals($file->checksum,hash_file('sha256',Storage::disk('local')->path($file->path))),409,'Tài liệu không còn khớp với bản đã ghi nhận.');return$file;
    }
}
