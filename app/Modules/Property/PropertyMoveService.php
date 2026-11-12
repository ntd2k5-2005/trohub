<?php
namespace App\Modules\Property;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Billing\Models\{Invoice,PaymentRequest,ReceivingAccount};
use App\Modules\DataPlatform\Journal;
use App\Modules\Property\Models\{Property,PropertyMove};
use App\Modules\Rental\Models\{Rental,RentalRequest,RoomReservation};
use App\Modules\Workspace\{OwnerQuota,WorkspaceLifecycle};
use App\Modules\Workspace\Models\{Membership,Workspace,WorkAssignment};
use Illuminate\Support\Facades\DB;

final class PropertyMoveService
{
    private function authorize(User $actor,Property $p,Workspace $target):void
    {
        abort_unless($p->workspace_id!==$target->id&&$p->workspace->owner_id===$target->owner_id,403,'Chỉ chuyển nhà giữa hai khu của cùng chủ trọ.');
        app(Authorization::class)->authorize($actor,'property.move',$p->workspace,$p->id);
        app(Authorization::class)->authorize($actor,'property.create',$target);
        app(WorkspaceLifecycle::class)->requireAdmission($target);
    }
    public function snapshot(Property $p,Workspace $target):array
    {
        $rooms=$p->rooms()->orderBy('id')->get();$rentals=Rental::whereIn('room_id',$rooms->pluck('id'))->orderBy('id')->get();
        $accountId=DB::table('receiving_account_defaults')->where('workspace_id',$target->id)->where('scope_key','workspace')->value('receiving_account_id');
        $payee=$accountId?ReceivingAccount::find($accountId):null;
        $requests=PaymentRequest::whereIn('rental_id',$rentals->pluck('id'))->orderBy('id')->get();$blockers=[];
        if($p->retired_at)$blockers[]='Nhà đã ngừng khai thác.';
        if(RoomReservation::whereIn('room_id',$rooms->pluck('id'))->whereNotNull('active_room_id')->exists())$blockers[]='Cần xử lý giữ phòng đang mở trước khi chuyển.';
        if($rentals->whereIn('status',['active','move_out_pending','draft'])->isNotEmpty()||$requests->isNotEmpty()){
            if(!$payee||$payee->workspace_id!==$target->id||$payee->status!=='active'||$payee->verification_status!=='verified')$blockers[]='Khu nhận chưa có tài khoản nhận tiền đã xác minh.';
            foreach($requests as$r){
                $old=$r->payee_snapshot;
                if(!$payee||$old['bank_bin']!==$payee->bank_bin||strtoupper($old['account_no'])!==strtoupper($payee->account_no)||mb_strtoupper(trim($old['account_holder_name']))!==mb_strtoupper(trim($payee->account_holder_name)))$blockers[]='Thông tin người nhận tiền phải giữ nguyên theo chứng từ.';
            }
        }
        foreach($rooms as$room){$override=DB::table('receiving_account_defaults')->where('workspace_id',$target->id)->whereIn('scope_key',['room:'.$room->id,'property:'.$p->id])->exists();if($override)$blockers[]='Cần kiểm tra thiết lập nhận tiền riêng trước khi chuyển.';}
        return ['property'=>$p->only(['id','workspace_id','version','retired_at']),'workspaces'=>Workspace::whereIn('id',[$p->workspace_id,$target->id])->orderBy('id')->get()->map->only(['id','owner_id','lifecycle','version','status','expires_at','cloud_epoch'])->all(),
            'rooms'=>$rooms->map->only(['id','version','status','retired_at'])->all(),'rentals'=>$rentals->map->only(['id','status','confirmed_at','tenant_id','deposit','rent'])->all(),
            'requests'=>$requests->map(fn($r)=>['id'=>$r->id,'workspace_id'=>$r->workspace_id,'version'=>$r->version,'status'=>$r->status,'amount'=>$r->amount,'payee_hash'=>hash('sha256',json_encode($r->payee_snapshot))])->all(),
            'work'=>$this->work($p)->orderBy('id')->get()->map->only(['id','version','workspace_id','membership_id','actor_id','resource_type','resource_id','status','handover_id'])->all(),
            'invoices'=>Invoice::whereIn('rental_id',$rentals->pluck('id'))->orderBy('id')->get()->map(fn($i)=>['id'=>$i->id,'status'=>$i->status,'total'=>$i->total,'paid'=>$i->paid])->all(),
            'members'=>Membership::whereIn('workspace_id',[$p->workspace_id,$target->id])->orderBy('id')->get()->map->only(['id','version','status','property_ids','room_ids','source_revision','valid_until'])->all(),
            'payee'=>$payee?->only(['id','version','account_fingerprint','verification_status','status']), 'limits'=>app(OwnerQuota::class)->limits($target->owner_id),'blockers'=>array_values(array_unique($blockers))];
    }
    public function propose(User $actor,Property $property,Workspace $target,string $reference,string $reason):PropertyMove
    {
        return app(OwnerQuota::class)->transaction($property->workspace->owner_id,function()use($actor,$property,$target,$reference,$reason){
            Workspace::whereIn('id',[$property->workspace_id,$target->id])->orderBy('id')->lockForUpdate()->get();$p=$property->fresh();$target=$target->fresh();$this->authorize($actor,$p,$target);
            abort_unless(trim($reason)!==''&&trim($reference)!=='',422);
            $old=PropertyMove::where('reference',$reference)->first();if($old){abort_unless($old->proposer_id===$actor->id&&$old->property_id===$p->id&&$old->target_workspace_id===$target->id,409);return$old;}
            $snapshot=$this->snapshot($p,$target);$move=PropertyMove::create(['property_id'=>$p->id,'source_workspace_id'=>$p->workspace_id,'target_workspace_id'=>$target->id,'proposer_id'=>$actor->id,'reference'=>$reference,'reason'=>$reason,'snapshot'=>$snapshot,'snapshot_hash'=>hash('sha256',json_encode($snapshot)),'blockers'=>$snapshot['blockers'],'status'=>$snapshot['blockers']?'blocked':'pending']);
            app(Journal::class)->record($actor->id,'property.move.proposed',$move,$p->workspace_id,['reason'=>$reason,'target_workspace_id'=>$target->id,'blockers'=>$snapshot['blockers']]);return$move;
        });
    }
    public function commit(User $actor,int $id):void
    {
        $move=PropertyMove::findOrFail($id);$source=Workspace::findOrFail($move->source_workspace_id);
        app(OwnerQuota::class)->transaction($source->owner_id,function()use($actor,$move){
            Workspace::whereIn('id',[$move->source_workspace_id,$move->target_workspace_id])->orderBy('id')->lockForUpdate()->get();$move=PropertyMove::whereKey($move->id)->lockForUpdate()->firstOrFail();
            if($move->status==='completed'){abort_unless($actor->id===$move->proposer_id&&User::find($actor->id)?->status==='active',403);return;}
            abort_unless($move->status==='pending',409,'Hồ sơ chuyển nhà còn điều kiện chưa được xử lý.');
            $p=Property::whereKey($move->property_id)->lockForUpdate()->firstOrFail();$target=Workspace::findOrFail($move->target_workspace_id);$this->authorize($actor,$p,$target);
            abort_unless($p->workspace_id===$move->source_workspace_id,409);
            $snapshot=$this->snapshot($p,$target);abort_unless(hash_equals($move->snapshot_hash,hash('sha256',json_encode($snapshot)))&&!$snapshot['blockers'],409,'Dữ liệu hoặc quyền đã đổi. Hãy tạo bản xem trước mới.');
            app(OwnerQuota::class)->admit($target->owner_id,'properties',0);app(OwnerQuota::class)->admit($target->owner_id,'rooms',0);
            $rooms=$p->rooms()->pluck('id');$rentals=Rental::whereIn('room_id',$rooms)->pluck('id');
            DB::table('domain_events')->where('workspace_id',$move->source_workspace_id)->whereNull('projected_at')->update(['projected_at'=>now(),'last_error'=>'RC12_SUPPRESSED']);
            DB::table('user_notifications')->where('workspace_id',$move->source_workspace_id)->whereNull('suppressed_at')->update(['suppressed_at'=>now()]);
            DB::table('listings')->whereIn('room_id',$rooms)->where('status','published')->update(['status'=>'paused']);
            $p->update(['workspace_id'=>$target->id]);
            PaymentRequest::whereIn('rental_id',$rentals)->update(['workspace_id'=>$target->id,'version'=>DB::raw('version+1')]);RentalRequest::whereIn('room_id',$rooms)->update(['workspace_id'=>$target->id,'version'=>DB::raw('version+1')]);
            $work=$this->work($p,$move->source_workspace_id)->lockForUpdate()->get();
            foreach($work as$a)$a->update(['workspace_id'=>$target->id,'status'=>'handover_pending','current_key'=>null,'version'=>$a->version+1]);
            $move->update(['status'=>'completed','completed_at'=>now()]);
            app(Journal::class)->record($actor->id,'property.moved',$p,$target->id,['move_id'=>$move->id,'source_workspace_id'=>$move->source_workspace_id,'target_workspace_id'=>$target->id,'reason'=>$move->reason,'legal_or_bank_change'=>false]);
            app(Authorization::class)->authorize($actor,'property.create',$target);
        });
    }

    private function work(Property $property,?int $workspace=null)
    {
        $rooms=$property->rooms()->pluck('id');
        return WorkAssignment::where('workspace_id',$workspace??$property->workspace_id)->whereIn('status',['open','handover_pending'])->where(function($q)use($rooms){
            foreach(config('people.work_resources')as$type=>$spec)$q->orWhere(fn($sub)=>$sub->where('resource_type',$type)->whereIn('resource_id',$spec['model']::whereHas($spec['room_relation'],fn($r)=>$r->whereIn('id',$rooms))->select('id')));
        });
    }
}
