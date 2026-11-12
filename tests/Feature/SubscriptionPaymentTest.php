<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\Subscription\AccountSubscriptionService;
use App\Modules\Subscription\Models\SubscriptionCycle;
use App\Modules\Subscription\PlanCatalog;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubscriptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed(DatabaseSeeder::class);
        $this->freezeTime();
        config(['subscriptions.account_authority_enabled' => true, 'subscriptions.platform_payee' => ['bank_bin' => '970422', 'bank_name' => 'Ngân hàng kiểm thử', 'account_no' => '000000999', 'account_holder_name' => 'PLATFORM TEST']]);
        $admin = User::where('email', 'supreme@trohub.test')->firstOrFail();
        $owner = User::factory()->create(['access_group_id' => AccessGroup::where('key', 'ordinary')->value('id')]);
        $catalog = app(PlanCatalog::class);
        foreach (['trial', 'monthly'] as $key) {
            $data = ['plan' => $key, 'label' => 'Gói kiểm thử '.$key, 'price' => $key === 'trial' ? 0 : 100000, 'duration_count' => $key === 'trial' ? 14 : 1, 'duration_unit' => $key === 'trial' ? 'days' : 'months', 'features' => array_keys(config('subscriptions.feature_labels')), 'reason' => 'Giá và hạn mức chỉ thuộc fixture kiểm thử'];
            foreach (['workspaces' => 3, 'properties' => 3, 'rooms' => 10, 'staff' => 2] as $kind => $value) {
                $data[$kind.'_mode'] = 'limited';
                $data['max_'.$kind] = $value;
            }
            $plan = $catalog->save($admin, $data);
            $catalog->publish($admin, $plan->id);
        }
        $service = app(AccountSubscriptionService::class);
        $account = $service->trial($owner);
        $quote = $service->quote($owner, 'monthly', 'PLATFORM-TEST-QUOTE');

        return [$admin, $owner, $account, $quote];
    }

    public function test_two_step_confirmation_does_not_activate_from_payer_report_and_cannot_be_bypassed(): void
    {
        [$admin, $owner, $account, $quote] = $this->fixture();
        $data = ['context' => 'platform-admin', 'id' => $quote->id, 'amount' => 100000, 'reference' => 'PLATFORM-BANK-ONE', 'note' => 'Đã kiểm tra khoản nhận trong fixture', 'confirm' => 1];
        $this->actingAs($admin)->post('/actions/account.subscription.confirm', $data)->assertConflict();
        $this->assertSame('trial', $account->fresh()->status);
        $this->actingAs($admin)->post('/actions/account.payment.report', ['context' => 'personal', 'id' => $quote->id, 'reference' => 'FOREIGN', 'confirm' => 1])->assertForbidden();
        $this->actingAs($owner)->post('/actions/account.payment.report', ['context' => 'personal', 'id' => $quote->id, 'reference' => 'PAYER-ONE', 'confirm' => 1])->assertSessionHasNoErrors();
        $this->assertSame('trial', $account->fresh()->status);
        $this->assertSame(0, SubscriptionCycle::where('subscription_quote_id', $quote->id)->count());
        $this->actingAs($owner)->post('/actions/account.subscription.confirm', $data)->assertForbidden();
        $this->actingAs($admin)->post('/actions/account.subscription.confirm', $data)->assertSessionHasNoErrors();
        $this->post('/actions/account.subscription.confirm', $data)->assertSessionHasNoErrors();
        $this->assertSame('active', $account->fresh()->status);
        $this->assertSame(1, SubscriptionCycle::where('subscription_quote_id', $quote->id)->count());
        $this->assertDatabaseHas('user_notifications', ['user_id' => $owner->id, 'title' => 'Đã xác nhận thanh toán gói dịch vụ']);
    }

    public function test_qr_uses_immutable_payee_exact_quote_and_private_scope(): void
    {
        [$admin, $owner, $account, $quote] = $this->fixture();
        Http::preventStrayRequests();
        Http::fake(['img.vietqr.io/*' => Http::response('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>', 200, ['Content-Type' => 'image/svg+xml'])]);
        config(['subscriptions.platform_payee.account_no' => '111111111']);
        $url = '/thanh-toan-goi/'.$quote->id.'/ma-qr?context=personal';
        $this->actingAs($admin)->get($url)->assertForbidden();
        $this->actingAs($owner)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($request) => str_contains($request->url(), '000000999') && (int) $request['amount'] === 100000 && $request['addInfo'] === $quote->transfer_reference);
        $this->assertStringNotContainsString('000000999', DB::table('subscription_quotes')->where('id', $quote->id)->value('payee_snapshot'));
        $this->travelTo($quote->expires_at);
        $this->get($url)->assertConflict();
        $this->post('/actions/account.payment.report', ['context' => 'personal', 'id' => $quote->id, 'reference' => 'TOO-LATE', 'confirm' => 1])->assertConflict();
        $this->assertSame('trial', $account->fresh()->status);
    }

    public function test_report_retry_is_idempotent_and_unavailable_qr_does_not_change_entitlement(): void
    {
        [$admin, $owner, $account, $quote] = $this->fixture();
        Http::fake(['img.vietqr.io/*' => Http::response('Unavailable', 503)]);
        $this->actingAs($owner)->get('/thanh-toan-goi/'.$quote->id.'/ma-qr?context=personal')->assertStatus(503);
        $data = ['context' => 'personal', 'id' => $quote->id, 'reference' => 'PAYER-RETRY', 'confirm' => 1];
        $this->post('/actions/account.payment.report', $data)->assertSessionHasNoErrors();
        $this->post('/actions/account.payment.report', $data)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'subscription.payment.reported')->where('resource_id', $quote->id)->count());
        $this->assertSame('trial', $account->fresh()->status);
        $this->assertSame(0, SubscriptionCycle::where('subscription_quote_id', $quote->id)->count());
    }
}
