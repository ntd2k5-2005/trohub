<?php
namespace App\Modules\Subscription;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\RootProtection;
use App\Modules\DataPlatform\Journal;
use App\Modules\Subscription\Models\PlanVersion;
use App\Modules\Workspace\OwnerQuota;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\{DB,Validator};

final class PlanCatalog
{
    public function latest(string $key): ?PlanVersion {$q=PlanVersion::where('plan',$key)->whereNotNull('published_at');\App\Support\SimulationOrigin::filter($q,'plan_versions','operational');return $q->latest('id')->first();}

    public function selectable(string $key,bool $renewal=false):?PlanVersion
    {
        // The latest published decision controls admission; do not fall back to an older open version.
        $p=$this->latest($key);
        return $p&&in_array($p->availability,$renewal?['public','renewal_only']:['public'],true)?$p:null;
    }

    public function availability(User $actor,int $id,string $expected,string $state,string $reason):void
    {
        app(BusinessAccess::class)->authorize($actor,'plans.publish');
        abort_unless(in_array($state,['public','renewal_only','closed','archived'],true)&&trim($reason)!=='',422);
        app(RootProtection::class)->mutation(function()use($actor,$id,$expected,$state,$reason){
            $p=PlanVersion::whereNotNull('published_at')->lockForUpdate()->findOrFail($id);abort_unless($p->availability===$expected,409,'Trạng thái gói đã thay đổi.');
            abort_if(\App\Support\SimulationOrigin::contains('plan_versions',$p->id),409,'Phiên bản này đã được chốt cho lịch sử. Vui lòng soạn gói mới để mở đăng ký.');
            $p->update(['availability'=>$state]);app(Journal::class)->record($actor->id,'plan.availability.changed',$p,null,['before'=>$expected,'after'=>$state,'reason'=>$reason,'existing_quotes_changed'=>false]);
            app(BusinessAccess::class)->authorize($actor,'plans.publish');
        });
    }

    public function save(User $actor,array $input): PlanVersion
    {
        app(BusinessAccess::class)->authorize($actor,'plans.edit');
        $rules=['plan'=>'required|in:'.implode(',',array_keys(config('subscriptions.plans'))),'label'=>'required|string|max:100','price'=>'nullable|integer|min:0','duration_count'=>'required|integer|min:1|max:1200','duration_unit'=>'required|in:days,months,years','features'=>'present|array','features.*'=>'string|in:'.implode(',',array_keys(config('subscriptions.feature_labels'))),'reason'=>'required|string|min:5|max:2000'];
        foreach(['workspaces','properties','rooms','staff'] as $kind){$rules[$kind.'_mode']='required|in:unspecified,limited,unlimited';$rules['max_'.$kind]='required_if:'.$kind.'_mode,limited|nullable|integer|min:0|max:2147483647';}
        $rules+=['id'=>'nullable|integer|min:1','expected_fingerprint'=>'required_with:id|string|size:64'];
        $d=Validator::make($input,$rules)->validate();
        return app(RootProtection::class)->mutation(function()use($actor,$d){
            app(BusinessAccess::class)->authorize($actor,'plans.edit');
            $limits=[];foreach(['workspaces','properties','rooms','staff'] as $kind)$limits[$kind]=match($d[$kind.'_mode']){'unlimited'=>'unlimited','limited'=>(int)$d['max_'.$kind],default=>null};
            $snapshot=['plan'=>$d['plan'],'label'=>$d['label'],'price'=>isset($d['price'])?(int)$d['price']:null,'currency'=>config('subscriptions.currency'),'duration_count'=>(int)$d['duration_count'],'duration_unit'=>$d['duration_unit'],'billing_cycle'=>config('subscriptions.plans.'.$d['plan'].'.billing_cycle'),'features'=>array_values(array_unique($d['features'])),'limits'=>$limits];
            $hash=hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR));
            if(!empty($d['id'])) {
                $row=PlanVersion::lockForUpdate()->findOrFail($d['id']);
                abort_if($row->published_at,409,'Chỉ bản nháp chưa phát hành mới được sửa.');
                abort_unless($row->plan===$d['plan']&&hash_equals($row->fingerprint,$d['expected_fingerprint']),409,'Bản nháp đã thay đổi. Hãy tải lại trước khi lưu.');
                abort_if(PlanVersion::where('fingerprint',$hash)->whereKeyNot($row->id)->exists(),409,'Đã có phiên bản chứa cấu hình này.');
                $row->update(['snapshot'=>$snapshot,'fingerprint'=>$hash]);$event='plan.version.updated';
            } else {
                $row=PlanVersion::firstOrCreate(['fingerprint'=>$hash],['plan'=>$d['plan'],'snapshot'=>$snapshot,'predecessor_id'=>$this->latest($d['plan'])?->id]);$event='plan.version.created';
            }
            app(Journal::class)->record($actor->id,$event,$row,null,['reason'=>$d['reason'],'limits'=>$limits]);app(BusinessAccess::class)->authorize($actor,'plans.edit');return $row;
        });
    }

    public function publish(User $actor,int $id): void
    {
        app(BusinessAccess::class)->authorize($actor,'plans.publish');
        app(RootProtection::class)->mutation(function()use($actor,$id){
            $row=PlanVersion::whereKey($id)->lockForUpdate()->firstOrFail();if($row->published_at)return;
            abort_unless($row->predecessor_id===$this->latest($row->plan)?->id,409,'Đã có phiên bản gói khác được phát hành. Hãy kiểm tra lại.');
            foreach(['workspaces','properties','rooms','staff'] as $key)abort_unless(array_key_exists($key,$row->snapshot['limits']) && $row->snapshot['limits'][$key]!==null,409,'Cần cấu hình rõ tất cả hạn mức trước khi phát hành.');
            abort_unless($row->plan==='trial'?$row->snapshot['price']===0:is_int($row->snapshot['price'])&&$row->snapshot['price']>0,409,'Cần cấu hình giá hợp lệ trước khi phát hành.');
            $row->update(['published_at'=>now(),'published_by'=>$actor->id]);
            app(Journal::class)->record($actor->id,'plan.version.published',$row,null,['limits'=>$row->snapshot['limits'],'existing_subscriptions_changed'=>false]);
            app(BusinessAccess::class)->authorize($actor,'plans.publish');
        });
    }

    public function assignLegacyQuota(User $actor,int $owner,int $version,string $reason): void
    {
        app(BusinessAccess::class)->authorize($actor,'subscription.plan.manage');abort_unless(trim($reason)!=='',422);
        app(OwnerQuota::class)->transaction($owner,function()use($actor,$owner,$version,$reason){
            app(BusinessAccess::class)->authorize($actor,'subscription.plan.manage');
            abort_if(app(AccountSubscriptionService::class)->current($owner),409,'Gói cấp tài khoản đã có phiên bản riêng. Hãy dùng báo giá thay đổi gói.');
            $plan=PlanVersion::whereNotNull('published_at')->findOrFail($version);
            abort_if(\App\Support\SimulationOrigin::contains('plan_versions',$plan->id),409,'Phiên bản này không áp dụng cho hạn mức hiện tại. Vui lòng chọn gói khác.');
            $keys=Workspace::where('owner_id',$owner)->whereNull('purged_at')->distinct()->pluck('plan');
            abort_unless($keys->count()===1&&$keys->first()===$plan->plan,409,'Các kỳ gói cũ chưa thống nhất. Cần đối chiếu trước khi gán hạn mức.');
            $old=DB::table('owner_quota_versions')->where('active_owner_id',$owner)->first();if($old?->plan_version_id===$version)return;
            DB::table('owner_quota_versions')->where('active_owner_id',$owner)->update(['active_owner_id'=>null,'ended_at'=>now(),'updated_at'=>now()]);
            DB::table('owner_quota_versions')->insert(['owner_id'=>$owner,'active_owner_id'=>$owner,'plan_version_id'=>$version,'assigned_by'=>$actor->id,'reason'=>$reason,'created_at'=>now(),'updated_at'=>now()]);
            app(Journal::class)->record($actor->id,'plan.quota.adopted',User::findOrFail($owner),null,['before_version'=>$old?->plan_version_id,'after_version'=>$version,'reason'=>$reason,'payment_periods_changed'=>false]);
        });
    }
}
