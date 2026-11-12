<?php
namespace App\Modules\Subscription;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Subscription\Models\SubscriptionOrder;
final class LegacySubscriptionReview
{
    public function requireEnded(int $owner,bool $manualClosure=false):void
    {
        $workspaces=Workspace::where('owner_id',$owner)->whereNull('purged_at')->where('status','!=','pending')->get();
        abort_if($workspaces->contains(fn($workspace)=>$workspace->expires_at&&$workspace->expires_at->isFuture()),409,'Kỳ gói cũ vẫn còn hiệu lực. Tiếp tục sử dụng đến hết kỳ trước khi đăng ký theo cơ chế mới.');
        if(!$manualClosure&&$workspaces->contains(fn($workspace)=>!$workspace->expires_at)){
            $latest=\App\Modules\Subscription\Models\LegacySubscriptionReview::where('owner_id',$owner)->latest('id')->first();
            abort_unless($latest?->decision==='closed',409,'Kỳ gói chưa rõ cần được quản trị viên ghi nhận đối chiếu trước khi đăng ký mới.');
        }
    }

    public function originalPeriods(int $owner): array
    {
        $account = app(AccountSubscriptionService::class)->current($owner);
        if (!$account) return [];
        $entry = \App\Modules\DataPlatform\Models\AuditEntry::where('action', 'subscription.account.requested')->where('actor_id', $owner)->where('resource_type', $account->getMorphClass())->where('resource_id', $account->id)->oldest('id')->first();
        return $entry?->changes['legacy_periods'] ?? [];
    }

    public function record(\App\Models\User $actor,int $owner,string $decision,string $expectedHash,string $note):void
    {
        abort_unless(app(\App\Contracts\Authorization::class)->global($actor,'subscription.approve'),403);
        abort_unless(in_array($decision,['retained','unresolved','closed'],true)&&mb_strlen(trim($note))>=5&&mb_strlen($note)<=4000,422);
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('legacy_subscription_reviews'),503,'Đối chiếu gói cũ đang chờ cập nhật dữ liệu an toàn.');
        app(\App\Modules\Workspace\OwnerQuota::class)->transaction($owner,function()use($actor,$owner,$decision,$expectedHash,$note){
            \App\Models\User::findOrFail($owner);$report=$this->report($owner);
            abort_unless(hash_equals($report['source_hash'],$expectedHash),409,'Hồ sơ gói cũ đã thay đổi. Vui lòng kiểm tra lại.');
            if($decision==='closed')$this->requireEnded($owner,true);
            $review=\App\Modules\Subscription\Models\LegacySubscriptionReview::create(['owner_id'=>$owner,'reviewed_by'=>$actor->id,'decision'=>$decision,'source_hash'=>$report['source_hash'],'snapshot'=>$report,'note'=>$note]);
            app(\App\Modules\DataPlatform\Journal::class)->record($actor->id,'subscription.legacy.reviewed',$review,null,['owner_id'=>$owner,'decision'=>$decision,'legacy_money_or_periods_changed'=>false]);
            abort_unless(app(\App\Contracts\Authorization::class)->global($actor,'subscription.approve'),403);
        });
    }

    public function report(int $owner): array
    {
        $workspaces=Workspace::where('owner_id',$owner)->orderBy('id')->get();
        $periods=$workspaces->map(fn($w)=>$w->only(['id','plan','status','lifecycle','created_at','expires_at','purged_at']))->all();
        $orders=SubscriptionOrder::whereIn('workspace_id',$workspaces->pluck('id'))->get();
        $classification=app(AccountSubscriptionService::class)->current($owner)?'ACCOUNT_MODE':($workspaces->count()>1?'MULTIPLE_CONFLICTING':($workspaces->count()===1&&$workspaces->first()->plan==='trial'&&$orders->isEmpty()?'UNAMBIGUOUS':($workspaces->isEmpty()?'NO_LEGACY':'MISSING_EVIDENCE')));
        return ['owner_id'=>$owner,'classification'=>$classification,'workspace_periods'=>$periods,'order_count'=>$orders->count(),'confirmed_order_count'=>$orders->where('status','confirmed')->count(),'price_evidence'=>'Legacy orders do not establish verified amount/quote version','source_hash'=>hash('sha256',json_encode([$periods,$orders->map->only(['id','plan','status','confirmed_by','created_at'])->all()])),'cutover_performed'=>false,'action'=>$classification==='ACCOUNT_MODE'?'Controlled authority already exists':'Review source periods and verified evidence; no automatic merge or new Trial'];
    }
}
