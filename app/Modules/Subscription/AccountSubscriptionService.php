<?php

namespace App\Modules\Subscription;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Subscription\Models\{AccountSubscription, PlanVersion, SubscriptionQuote, SubscriptionCycle};
use App\Modules\Workspace\{OwnerQuota, WorkspaceLifecycle};
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;

/** Opt-in authority for reconciled/new accounts, never inferred from legacy workspace periods. */
final class AccountSubscriptionService
{
    public function current(int $owner): ?AccountSubscription
    {
        return AccountSubscription::with('planVersion')->where('owner_id',$owner)->whereNotNull('authority_enabled_at')->first();
    }

    public function plan(string $key,bool $renewal=false): PlanVersion
    {
        $plan=app(PlanCatalog::class)->selectable($key,$renewal);abort_unless($plan,409,'Gói chưa được phát hành hoặc đã ngừng tiếp nhận yêu cầu này.');return $plan;
    }

    public function trial(User $actor): AccountSubscription
    {
        abort_unless(config('subscriptions.account_authority_enabled') && app(Authorization::class)->personal($actor,'workspace.create'),403);
        return app(OwnerQuota::class)->transaction($actor->id,function()use($actor){
            abort_if(AccountSubscription::where('owner_id',$actor->id)->exists() || Workspace::where('owner_id',$actor->id)->exists(),409,'Tài khoản đã có lịch sử gói. Cần đối chiếu trước khi chuyển đổi.');
            $plan=$this->plan('trial');
            $s=AccountSubscription::create(['owner_id'=>$actor->id,'plan_version_id'=>$plan->id,'status'=>'trial','trial_started_at'=>now(),'expires_at'=>$this->ending(now(),$plan),'authority_enabled_at'=>now()]);
            app(Journal::class)->record($actor->id,'subscription.account.trial_started',$s);
            return $s->fresh();
        });
    }

    public function quote(User $actor,string $plan,string $reference): SubscriptionQuote
    {
        abort_unless(app(Authorization::class)->personal($actor,'workspace.create'),403);
        $this->requireLifecycleSchema();
        return app(OwnerQuota::class)->transaction($actor->id,function()use($actor,$plan,$reference){
            $s=$this->current($actor->id);abort_unless($s,409,'Chưa có gói cấp tài khoản đã được đối chiếu.');
            abort_if($s->status==='suspended',409,'Cần giải quyết hồ sơ đối soát đang bị đình chỉ trước khi phát hành báo giá mới.');
            $p=$this->plan($plan,$s->planVersion->plan===$plan);$price=$p->snapshot['price'];
            $this->requireRenewalCompatibility($s,$p);
            abort_unless($plan!=='trial' && is_int($price) && $price>0,409,'Giá gói chưa được phát hành. Chưa thể tạo yêu cầu thanh toán.');
            $old=SubscriptionQuote::where('reference',$reference)->first();
            if($old){abort_unless($old->account_subscription_id===$s->id && $old->plan_version_id===$p->id,409);return $old;}
            $payee=app(PlatformPayee::class)->details();
            abort_unless($payee,409,'Chưa có tài khoản nhận tiền dịch vụ hợp lệ. Vui lòng liên hệ hỗ trợ trước khi thanh toán.');
            $bankLink=\Illuminate\Support\Facades\Schema::hasColumn('subscription_quotes','receiving_account_id')?['receiving_account_id'=>$payee['receiving_account_id']??null]:[];
            $q=SubscriptionQuote::create($bankLink+['account_subscription_id'=>$s->id,'plan_version_id'=>$p->id,'amount'=>$price,'currency'=>$p->snapshot['currency'],'reference'=>$reference,'expected_version'=>$s->version,'expires_at'=>now()->addHours(config('subscriptions.quote_hours')),'payee_snapshot'=>$payee,'transfer_reference'=>'THP'.strtoupper(bin2hex(random_bytes(7)))]);
            \App\Support\SimulationOrigin::inherit('users',$actor->id,'subscription_quotes',$q->id);
            app(Journal::class)->record($actor->id,'subscription.quote.created',$q);return $q->fresh();
        });
    }

    public function openPaid(User $actor,string $plan,string $reference): SubscriptionQuote
    {
        abort_unless(config('subscriptions.account_authority_enabled')&&app(Authorization::class)->personal($actor,'workspace.create'),403);
        $this->requireLifecycleSchema();
        return app(OwnerQuota::class)->transaction($actor->id,function()use($actor,$plan,$reference){
            if($this->current($actor->id))return $this->quote($actor,$plan,$reference);
            $history=AccountSubscription::where('owner_id',$actor->id)->lockForUpdate()->first();
            abort_if($history&&($history->authority_enabled_at||!\App\Support\SimulationOrigin::contains('account_subscriptions',$history->id)),409,'Hồ sơ gói hiện có cần được đối chiếu trước khi đăng ký mới.');
            app(LegacySubscriptionReview::class)->requireEnded($actor->id);
            $legacy = app(LegacySubscriptionReview::class)->report($actor->id);
            $version=$this->plan($plan);abort_unless($plan!=='trial'&&is_int($version->snapshot['price'])&&$version->snapshot['price']>0,409,'Gói trả phí chưa được phát hành đầy đủ.');
            $account=$history??new AccountSubscription(['owner_id'=>$actor->id]);
            $account->fill(['plan_version_id'=>$version->id,'status'=>'pending','expires_at'=>now(),'authority_enabled_at'=>now(),'current_cycle_id'=>null,'version'=>$history?$history->version+1:1])->save();
            app(Journal::class)->record($actor->id,'subscription.account.requested',$account,null,['legacy_periods'=>$legacy['workspace_periods'],'legacy_source_hash'=>$legacy['source_hash'],'legacy_money_changed'=>false]);
            return $this->quote($actor,$plan,$reference);
        });
    }

    public function scheduled(AccountSubscription $account): ?SubscriptionCycle
    {
        if(!\Illuminate\Support\Facades\Schema::hasColumn('subscription_cycles','application_state'))return null;
        return SubscriptionCycle::with('quote')->where('account_subscription_id',$account->id)->where('application_state','scheduled')->whereNull('reversed_at')->orderBy('starts_at')->first();
    }

    public function cancelQuote(User $actor,SubscriptionQuote $quote,string $reason): void
    {
        $this->authorizePayer($actor,$quote);abort_unless(trim($reason)!==''&&mb_strlen($reason)<=1000,422);
        $this->requireLifecycleSchema();
        app(OwnerQuota::class)->transaction($actor->id,function()use($actor,$quote,$reason){
            $current=SubscriptionQuote::whereKey($quote->id)->lockForUpdate()->firstOrFail();$this->authorizePayer($actor,$current);
            if($current->status==='cancelled')return;
            abort_unless($current->status==='pending'&&!$current->payer_reported_at,409,'Yêu cầu đã báo chuyển tiền hoặc đã xác nhận cần được đối soát; không tự hủy khoản tiền.');
            $current->update(['status'=>'cancelled','cancelled_at'=>now(),'cancellation_reason'=>$reason]);
            app(Journal::class)->record($actor->id,'subscription.quote.cancelled',$current,null,['reason'=>$reason]);
        });
    }

    public function payable(SubscriptionQuote $quote): bool
    {
        $subscription=$quote->accountSubscription;
        return $quote->status==='pending' && $quote->expires_at->isFuture() && $quote->amount>0
            && $quote->currency==='VND' && $quote->payee_snapshot && $quote->transfer_reference
            && $subscription?->authority_enabled_at && $subscription->status!=='suspended'
            && $subscription->version===$quote->expected_version;
    }

    public function authorizePayer(User $actor,SubscriptionQuote $quote): void
    {
        abort_unless($quote->accountSubscription->owner_id===$actor->id && app(Authorization::class)->personal($actor,'workspace.create'),403);
    }

    public function report(User $actor,SubscriptionQuote $quote,string $reference,?string $note=null): void
    {
        $this->authorizePayer($actor,$quote);
        $data=\Illuminate\Support\Facades\Validator::make(['reference'=>$reference,'note'=>$note],['reference'=>'required|string|max:100','note'=>'nullable|string|max:1000'])->validate();
        app(OwnerQuota::class)->transaction($actor->id,function()use($actor,$quote,$data){
            $q=SubscriptionQuote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $this->authorizePayer($actor,$q);
            abort_unless($this->payable($q),409,'Báo giá đã thay đổi hoặc hết hạn. Nếu đã chuyển tiền, vui lòng liên hệ hỗ trợ để đối soát.');
            if($q->payer_reported_at){abort_unless($q->payer_bank_reference===$data['reference'],409,'Thông tin đã gửi đang chờ đối soát. Vui lòng liên hệ hỗ trợ nếu cần điều chỉnh.');return;}
            $q->update(['payer_reported_at'=>now(),'payer_bank_reference'=>$data['reference'],'payer_note'=>$data['note']??null]);
            app(Journal::class)->record($actor->id,'subscription.payment.reported',$q);
        });
    }

    public function confirm(User $actor,int $id,int $amount,string $bankReference,string $note): void
    {
        abort_unless(app(BusinessAccess::class)->can($actor,'payments.verify') && trim($note)!=='',403);
        $this->requireLifecycleSchema();
        $quote=SubscriptionQuote::findOrFail($id);$owner=AccountSubscription::findOrFail($quote->account_subscription_id)->owner_id;
        app(OwnerQuota::class)->transaction($owner,function()use($actor,$quote,$amount,$bankReference,$note,$owner){
            $s=AccountSubscription::where('owner_id',$owner)->lockForUpdate()->firstOrFail();$q=SubscriptionQuote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
            if($q->status==='confirmed'){
                $c=SubscriptionCycle::where('subscription_quote_id',$q->id)->firstOrFail();abort_unless($c->bank_reference===$bankReference && $c->verified_amount===$amount,409);return;
            }
            abort_unless($this->payable($q) && $q->payer_reported_at,409,'Cần báo giá còn hiệu lực và thông tin người mua đã chuyển tiền trước khi đối soát. Báo giá cũ hoặc hết hạn cần xử lý riêng.');
            abort_unless($q->amount===$amount && $amount>0 && trim($bankReference)!=='' && !SubscriptionCycle::where('bank_reference',$bankReference)->exists(),409,'Số tiền hoặc mã giao dịch đối soát không phù hợp.');
            $p=PlanVersion::findOrFail($q->plan_version_id);$cycle=$p->snapshot['billing_cycle'];abort_unless(in_array($cycle,['monthly','yearly'],true),409);
            $this->requireRenewalCompatibility($s,$p);
            $workspaces=Workspace::where('owner_id',$owner)->orderBy('id')->lockForUpdate()->get();
            $previousLive=$workspaces->mapWithKeys(fn($w)=>[$w->id=>app(PropertyCloud::class)->active($w)])->all();
            // Preserve the current paid/trial remainder; no proration or legacy period merge.
            $from=$s->expires_at->isFuture()?$s->expires_at->copy():now();$until=$this->ending($from,$p);
            $deferred=$s->status==='active'&&$s->expires_at->isFuture()&&!$this->sameBenefits($s,$p);
            $c=SubscriptionCycle::create(['account_subscription_id'=>$s->id,'subscription_quote_id'=>$q->id,'verified_amount'=>$amount,'bank_reference'=>$bankReference,'verified_by'=>$actor->id,'verification_note'=>$note,'starts_at'=>$from,'ends_at'=>$until,'verified_at'=>now(),'application_state'=>$deferred?'scheduled':'applied','applied_at'=>$deferred?null:now()]);
            \App\Support\SimulationOrigin::inherit('subscription_quotes',$q->id,'subscription_cycles',$c->id);
            if($deferred){
                $s->update(['version'=>$s->version+1]);$q->update(['status'=>'confirmed']);
                app(Journal::class)->record($actor->id,'subscription.change.scheduled',$c,null,['starts_at'=>$from->toIso8601String(),'ends_at'=>$until->toIso8601String()]);
                app(Journal::class)->notify($owner,'Đã xác nhận gói cho kỳ tiếp theo','Gói hiện tại được giữ đến hết kỳ. Gói mới bắt đầu từ '.$from->format('d/m/Y H:i').'.');
                abort_unless(app(BusinessAccess::class)->can($actor,'payments.verify'),403);return;
            }
            $s->update(['plan_version_id'=>$p->id,'status'=>'active','expires_at'=>$until,'current_cycle_id'=>$c->id,'version'=>$s->version+1,'epoch'=>$s->expires_at->isFuture()?$s->epoch:$s->epoch+1]);
            $q->update(['status'=>'confirmed']);
            foreach($workspaces as $w){
                $oldLive=$previousLive[$w->id];
                $w->update(['plan'=>$p->plan,'expires_at'=>$until,'status'=>$w->lifecycle==='archived'?'archived':'active','cloud_epoch'=>$oldLive?$w->cloud_epoch:$w->cloud_epoch+1,'cloud_resumed_at'=>$oldLive?$w->cloud_resumed_at:now()]);
            }
            app(Journal::class)->record($actor->id,'subscription.account.verified',$c,null,['quote_id'=>$q->id,'amount'=>$amount,'bank_reference'=>$bankReference]);
            app(Journal::class)->notify($owner,'Đã xác nhận thanh toán gói dịch vụ','Khoản thanh toán gói dịch vụ đã được đối soát. Bạn có thể kiểm tra thời hạn sử dụng trong mục gói dịch vụ.');
            abort_unless(app(BusinessAccess::class)->can($actor,'payments.verify'),403);
        });
    }

    public function activateDue(): int
    {
        if(!\Illuminate\Support\Facades\Schema::hasColumn('subscription_cycles','application_state'))return 0;
        $count=0;
        foreach(SubscriptionCycle::where('application_state','scheduled')->whereNull('reversed_at')->where('starts_at','<=',now())->orderBy('starts_at')->get()as$candidate){
            $owner=AccountSubscription::findOrFail($candidate->account_subscription_id)->owner_id;
            $count+=app(OwnerQuota::class)->transaction($owner,function()use($candidate,$owner){
                $account=AccountSubscription::where('owner_id',$owner)->lockForUpdate()->firstOrFail();$cycle=SubscriptionCycle::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if($cycle->application_state!=='scheduled'||$cycle->reversed_at||$cycle->starts_at->isFuture()||$account->status==='suspended')return 0;
                $quote=SubscriptionQuote::findOrFail($cycle->subscription_quote_id);$plan=PlanVersion::findOrFail($quote->plan_version_id);
                $workspaces=Workspace::where('owner_id',$owner)->whereNull('purged_at')->orderBy('id')->lockForUpdate()->get();
                $account->update(['plan_version_id'=>$plan->id,'status'=>$cycle->ends_at->isFuture()?'active':'expired','expires_at'=>$cycle->ends_at,'current_cycle_id'=>$cycle->id,'version'=>$account->version+1]);
                foreach($workspaces as$workspace){
                    // A paid consecutive change is not an outage or an order
                    // to hide existing resources when a smaller quota starts.
                    $workspace->update(['plan'=>$plan->plan,'expires_at'=>$cycle->ends_at,'status'=>$workspace->lifecycle==='archived'?'archived':($cycle->ends_at->isFuture()?'active':'suspended')]);
                }
                $cycle->update(['application_state'=>'applied','applied_at'=>now()]);
                app(Journal::class)->record(null,'subscription.change.applied',$cycle);
                app(Journal::class)->notify($owner,'Gói dịch vụ đã chuyển kỳ','Gói '.$plan->snapshot['label'].' đã được áp dụng theo kỳ đã xác nhận.');return 1;
            });
        }
        return $count;
    }

    public function expireAccounts(): int
    {
        $count=0;
        foreach(AccountSubscription::whereIn('status',['trial','active'])->whereNotNull('authority_enabled_at')->where('expires_at','<=',now())->get()as$candidate){
            $count+=app(OwnerQuota::class)->transaction($candidate->owner_id,function()use($candidate){
                $current=AccountSubscription::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if(!in_array($current->status,['trial','active'],true)||$current->expires_at->isFuture())return 0;
                // Expiry does not change an outstanding quote's immutable terms.
                $current->update(['status'=>'expired']);app(Journal::class)->record(null,'subscription.account.expired',$current);return 1;
            });
        }
        return $count;
    }

    public function restrict(User $actor,int $owner,string $cause,string $reason): int
    {
        abort_unless(app(Authorization::class)->global($actor,'system.configure') && trim($reason)!=='',403);
        return app(OwnerQuota::class)->transaction($owner,function()use($actor,$owner,$cause,$reason){
            $key=$owner.':'.$cause;$old=DB::table('account_restrictions')->where('active_key',$key)->first();if($old)return $old->id;
            $id=DB::table('account_restrictions')->insertGetId(['owner_id'=>$owner,'cause'=>$cause,'active_key'=>$key,'reason'=>$reason,'created_by'=>$actor->id,'created_at'=>now(),'updated_at'=>now()]);
            foreach(Workspace::where('owner_id',$owner)->orderBy('id')->lockForUpdate()->get() as $w){$w->update(['cloud_epoch'=>$w->cloud_epoch+1,'cloud_cutoff_at'=>now()]);app(WorkspaceLifecycle::class)->suppressPending($w);}
            app(Journal::class)->record($actor->id,'account.restricted',User::findOrFail($owner),null,['restriction_id'=>$id,'reason'=>$reason]);return $id;
        });
    }

    public function release(User $actor,int $id,string $reason): void
    {
        abort_unless(app(Authorization::class)->global($actor,'system.configure') && trim($reason)!=='',403);
        $r=DB::table('account_restrictions')->find($id);abort_unless($r,404);
        app(OwnerQuota::class)->transaction($r->owner_id,function()use($actor,$r,$reason){
            DB::table('account_restrictions')->where('id',$r->id)->whereNull('released_at')->update(['released_at'=>now(),'released_by'=>$actor->id,'active_key'=>null,'updated_at'=>now()]);
            app(Journal::class)->record($actor->id,'account.restriction.released',User::findOrFail($r->owner_id),null,['restriction_id'=>$r->id,'reason'=>$reason]);
        });
    }

    public function reverse(User $actor,int $cycle,string $evidence): void
    {
        abort_unless(app(BusinessAccess::class)->can($actor,'payments.verify') && trim($evidence)!=='',403);
        $c=SubscriptionCycle::findOrFail($cycle);$s=AccountSubscription::findOrFail($c->account_subscription_id);
        app(OwnerQuota::class)->transaction($s->owner_id,function()use($actor,$c,$s,$evidence){
            $s=$s->fresh();$c=SubscriptionCycle::whereKey($c->id)->lockForUpdate()->firstOrFail();if($c->reversed_at)return;
            $c->update(['reversed_at'=>now(),'reversed_by'=>$actor->id,'reversal_evidence'=>$evidence]);
            if($s->current_cycle_id===$c->id){
                $s->update(['status'=>'suspended','epoch'=>$s->epoch+1,'version'=>$s->version+1]);
                foreach(Workspace::where('owner_id',$s->owner_id)->orderBy('id')->lockForUpdate()->get() as $w){$w->update(['cloud_epoch'=>$w->cloud_epoch+1,'cloud_cutoff_at'=>now()]);app(WorkspaceLifecycle::class)->suppressPending($w);}
            }
            app(Journal::class)->record($actor->id,'subscription.account.verified_reversal',$c,null,['evidence'=>$evidence,'current_cycle'=>$s->current_cycle_id===$c->id,'refund_created'=>false]);
            abort_unless(app(BusinessAccess::class)->can($actor,'payments.verify'),403);
        });
    }

    public function createWorkspace(User $actor,string $name): Workspace
    {
        abort_unless(app(Authorization::class)->personal($actor,'workspace.create'),403);
        return app(OwnerQuota::class)->transaction($actor->id,function()use($actor,$name){
            $s=$this->current($actor->id);abort_unless($s && in_array($s->status,['active','trial'],true) && $s->expires_at->isFuture(),409,'Cần gói cấp tài khoản còn hiệu lực.');
            abort_unless(in_array('workspace',$s->planVersion->snapshot['features'],true),403,'Gói dịch vụ không bao gồm tạo khu quản lý.');
            abort_if(DB::table('account_restrictions')->where('owner_id',$actor->id)->whereNull('released_at')->exists(),409);
            app(OwnerQuota::class)->admit($actor->id,'workspaces');
            $w=Workspace::create(['owner_id'=>$actor->id,'name'=>$name,'lifecycle'=>'draft','status'=>$s->planVersion->plan==='trial'?'trial':'active','plan'=>$s->planVersion->plan,'expires_at'=>$s->expires_at]);
            app(\App\Modules\AccessControl\GroupAssignments::class)->owner($w);
            app(Journal::class)->record($actor->id,'workspace.created',$w,$w->id,['lifecycle'=>'draft']);return $w->fresh();
        });
    }

    private function ending(\Illuminate\Support\Carbon $from,PlanVersion $p): \Illuminate\Support\Carbon
    {
        $from=$from->copy();$count=$p->snapshot['duration_count'];
        return match($p->snapshot['duration_unit']){'days'=>$from->addDays($count),'months'=>$from->addMonthsNoOverflow($count),'years'=>$from->addYearsNoOverflow($count)};
    }

    private function sameBenefits(AccountSubscription $current,PlanVersion $next):bool
    {
        $before=$current->planVersion->snapshot;$after=$next->snapshot;
        return $current->planVersion->plan===$next->plan&&$before['limits']==$after['limits']&&!array_diff($before['features'],$after['features'])&&!array_diff($after['features'],$before['features']);
    }

    private function requireRenewalCompatibility(AccountSubscription $current,PlanVersion $next):void
    {
        abort_if($this->scheduled($current)!==null,409,'Đã có gói kỳ tiếp theo được xác nhận. Hãy kiểm tra lịch áp dụng trước khi tạo yêu cầu khác.');
    }

    private function requireLifecycleSchema():void
    {
        abort_unless(\Illuminate\Support\Facades\Schema::hasColumns('subscription_quotes',['payee_snapshot','transfer_reference','payer_reported_at','payer_bank_reference','payer_note','cancelled_at','cancellation_reason'])&&\Illuminate\Support\Facades\Schema::hasColumns('subscription_cycles',['application_state','applied_at']),503,'Thanh toán gói dịch vụ đang chờ cập nhật dữ liệu an toàn. Vui lòng liên hệ hỗ trợ.');
    }
}
