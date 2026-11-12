<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Subscription\AccountSubscriptionService;
use App\Modules\Subscription\Models\AccountSubscription;
use App\Modules\Subscription\Models\PlanVersion;
use App\Modules\Subscription\Models\SubscriptionQuote;
use App\Modules\Workspace\Models\Workspace;

/** Declared, already-reconciled test initial state. This is not a production cutover algorithm. */
final class SubscriptionFixture
{
    public static function renew(Workspace $workspace, User $reviewer, string $target = 'monthly'): void
    {
        $workspace = $workspace->fresh();
        $plan = static function (string $key): PlanVersion {
            $snapshot = ['plan' => $key, 'label' => 'Gói kiểm thử đã đối chiếu '.$key, 'price' => $key === 'trial' ? 0 : 100000, 'currency' => 'VND', 'duration_count' => $key === 'trial' ? 14 : 1, 'duration_unit' => $key === 'trial' ? 'days' : ($key === 'yearly' ? 'years' : 'months'), 'billing_cycle' => $key === 'trial' ? null : $key, 'features' => config('subscriptions.plans.'.$key.'.features'), 'limits' => ['workspaces' => 10, 'properties' => 20, 'rooms' => 1000, 'staff' => 100]];

            return PlanVersion::firstOrCreate(['fingerprint' => hash('sha256', json_encode($snapshot))], ['plan' => $key, 'snapshot' => $snapshot, 'published_at' => now(), 'availability' => 'public']);
        };
        AccountSubscription::firstOrCreate(['owner_id' => $workspace->owner_id], ['plan_version_id' => $plan($workspace->plan)->id, 'status' => $workspace->plan === 'trial' ? 'trial' : 'active', 'expires_at' => $workspace->expires_at, 'authority_enabled_at' => now()]);
        $plan($target);
        config(['subscriptions.platform_payee' => ['bank_bin' => '970422', 'bank_name' => 'Ngân hàng kiểm thử', 'account_no' => '000000999', 'account_holder_name' => 'PLATFORM TEST']]);
        $service = app(AccountSubscriptionService::class);
        $reference = 'FIXTURE-'.$workspace->id.'-'.SubscriptionQuote::count();
        $quote = $service->quote($workspace->owner, $target, $reference);
        $service->report($workspace->owner, $quote, 'PAYER-'.$reference);
        $service->confirm($reviewer, $quote->id, $quote->amount, 'BANK-'.$reference, 'Đã đối soát giao dịch của dữ liệu kiểm thử đã xác lập');
    }
}
