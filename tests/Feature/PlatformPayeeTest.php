<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Billing\Adapters\VietQrAdapter;
use App\Modules\Subscription\PlatformPayee;
use App\Modules\Workspace\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlatformPayeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['subscriptions.platform_payee' => ['bank_bin' => '970422', 'bank_name' => 'Ngân hàng kiểm thử', 'account_no' => '0000012345', 'account_holder_name' => 'NGUOI NHAN KIEM THU']]);
        Http::preventStrayRequests();
    }

    public function test_account_number_retains_leading_zero_and_summary_is_masked(): void
    {
        $service = new PlatformPayee;
        $this->assertSame('0000012345', $service->details()['account_no']);
        $summary = $service->summary();
        $this->assertSame('•••• 2345', $summary['masked_number']);
        $this->assertArrayNotHasKey('account_no', $summary);
        config(['subscriptions.platform_payee.account_no' => null]);
        $this->assertNull($service->details());
        $this->assertNull($service->summary());
    }

    public function test_legacy_subscription_has_no_qr_when_no_payable_amount_is_established(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('email', 'owner@trohub.test')->firstOrFail();
        $workspace = Workspace::where('owner_id', $owner->id)->firstOrFail();
        $before = DB::table('subscription_orders')->get()->toJson();
        $response = $this->actingAs($owner)->get('/ui/subscription?context=workspace&workspace_id='.$workspace->id)->assertOk();
        $response->assertSee('NGUOI NHAN KIEM THU')->assertSee('•••• 2345')->assertDontSee('0000012345');
        $this->assertSame($before, DB::table('subscription_orders')->get()->toJson());
        $this->assertDatabaseCount('receiving_accounts', 0);
        $this->assertDatabaseCount('receiving_account_defaults', 0);
        $this->assertDatabaseCount('payment_requests', 0);
        $staff = User::where('email', 'staff@trohub.test')->firstOrFail();
        $this->actingAs($staff)->get('/ui/subscription?context=workspace&workspace_id='.$workspace->id)->assertForbidden();
    }

    public function test_qr_generation_uses_declared_payee_without_marking_payments_received(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
        Http::fake(['img.vietqr.io/*' => Http::response($svg, 200, ['Content-Type' => 'image/svg+xml'])]);
        config(['vietqr.driver' => 'quicklink']);
        $bytes = (new VietQrAdapter)->image((new PlatformPayee)->details(), 123000, 'THPREVIEW000001');
        $this->assertStringContainsString('<svg', $bytes);
        Http::assertSent(fn ($r) => str_contains($r->url(), '970422-0000012345-') && (string) $r['amount'] === '123000' && $r['addInfo'] === 'THPREVIEW000001');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_request_transactions', 0);
        $this->assertDatabaseCount('subscription_cycles',0);
    }
}
