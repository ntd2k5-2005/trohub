<?php

namespace Tests\Feature;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\ReceivingAccount;
use App\Modules\Billing\PaymentRequestService;
use App\Modules\Billing\ReceivingAccountService;
use App\Modules\Workspace\Models\Membership;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake([
            'api.vietqr.io/v2/banks' => Http::response(['code' => '00', 'data' => [['bin' => '970415', 'name' => 'Ngân hàng kiểm thử', 'shortName' => 'Kiểm thử', 'transferSupported' => 1]]]),
            'img.vietqr.io/*' => Http::response('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>', 200, ['Content-Type' => 'image/svg+xml']),
        ]);
        $this->seed(DatabaseSeeder::class);
    }

    private function invoice(): Invoice
    {
        return Invoice::firstOrFail();
    }

    private function request()
    {
        $invoice = $this->invoice();
        $workspace = $invoice->rental->room->property->workspace;
        $owner = $workspace->owner;
        $account = app(ReceivingAccountService::class)->save($owner, $workspace, ['bank_bin' => '970415', 'account_no' => '000000123456', 'account_holder_name' => 'TAI KHOAN KIEM THU', 'label' => 'Tài khoản thử nghiệm', 'declaration' => '1']);
        app(ReceivingAccountService::class)->setDefault($owner, $workspace, $account->id, null);

        return app(PaymentRequestService::class)->issue($owner, $workspace, 'invoice', $invoice->id);
    }

    public function test_cash_record_permission_cannot_mark_an_unverified_transfer_as_paid(): void
    {
        $invoice = $this->invoice();
        $room = $invoice->rental->room;
        $workspace = $room->property->workspace;
        $staff = User::factory()->create();
        Membership::create(['workspace_id' => $workspace->id, 'user_id' => $staff->id, 'status' => 'active', 'permissions' => ['workspace.view', 'invoice.view', 'payment.view', 'payment.record'], 'property_ids' => [$room->property_id]]);
        $this->assertTrue(app(Authorization::class)->allowed($staff, 'payment.record', $workspace, $room->property_id, $room->id));
        $this->assertFalse(app(Authorization::class)->allowed($staff, 'payment.verify', $workspace, $room->property_id, $room->id));
        $this->actingAs($staff)->post('/actions/payment.create', ['context' => 'workspace', 'workspace_id' => $workspace->id, 'id' => $invoice->id, 'amount' => 1000, 'method' => 'transfer', 'reference' => 'UNVERIFIED-TRANSFER'])->assertSessionHasErrors('method');
        $this->assertSame(0, $invoice->fresh()->paid);
        $this->assertDatabaseMissing('payments', ['reference' => 'UNVERIFIED-TRANSFER']);
    }

    public function test_sender_report_does_not_pay_invoice_and_receiver_confirmation_is_idempotent(): void
    {
        $request = $this->request();
        $invoice = $request->invoice;
        $tenant = $request->rental->tenant;
        $workspace = $request->rental->room->property->workspace;
        $this->actingAs($tenant)->post('/thanh-toan/'.$request->id.'/bao-chuyen-khoan', ['context' => 'personal', 'reference' => 'RECEIVED-ONE'])->assertSessionHasNoErrors();
        $this->assertSame('verification_pending', $request->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->paid);
        $this->actingAs(User::factory()->create())->post('/thanh-toan/'.$request->id.'/bao-chuyen-khoan', ['context' => 'personal', 'reference' => 'FOREIGN-REPORT'])->assertForbidden();
        $data = ['context' => 'workspace', 'workspace_id' => $workspace->id, 'reference' => 'RECEIVED-ONE', 'amount' => $request->amount, 'confirm' => '1'];
        $this->actingAs($workspace->owner)->post('/thanh-toan/'.$request->id.'/doi-soat', $data)->assertSessionHasNoErrors();
        $this->post('/thanh-toan/'.$request->id.'/doi-soat', $data)->assertSessionHasNoErrors();
        $this->assertSame('verified', $request->fresh()->status);
        $this->assertSame($invoice->total, $invoice->fresh()->paid);
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame(1, DB::table('payment_request_transactions')->where('payment_request_id', $request->id)->count());
    }

    public function test_partial_receipt_regenerates_qr_for_remaining_amount_instead_of_reusing_total(): void
    {
        $request = $this->request();
        $tenant = $request->rental->tenant;
        $workspace = $request->rental->room->property->workspace;
        $url = '/thanh-toan/'.$request->id.'/ma-qr?context=personal';
        $this->actingAs($tenant)->get($url)->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'img.vietqr.io') && (int) $r['amount'] === $request->amount);
        app(PaymentRequestService::class)->report($tenant, $request, 'PARTIAL-ONE', null, null);
        app(PaymentRequestService::class)->verify($workspace->owner, $workspace, $request, 'PARTIAL-ONE', 1000);
        $this->assertSame('partial', $request->fresh()->status);
        $this->actingAs($tenant)->get($url)->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'img.vietqr.io') && (int) $r['amount'] === $request->amount - 1000);
        $this->assertSame(1000, $request->invoice->fresh()->paid);
    }

    public function test_room_receiving_default_is_labeled_with_its_room_in_the_bank_list(): void
    {
        $request = $this->request();
        $workspace = $request->rental->room->property->workspace;
        $room = $request->rental->room;
        $account = ReceivingAccount::where('workspace_id', $workspace->id)->firstOrFail();
        app(ReceivingAccountService::class)->setDefault($workspace->owner, $workspace, $account->id, $room->property_id, $room->id);
        $response = $this->actingAs($workspace->owner)->get('/ui/workspace-settings?context=workspace&workspace_id='.$workspace->id.'&section=banking')->assertOk();
        $facts = $response->viewData('screen')['records'][0]['facts'];
        $this->assertStringContainsString($room->name,$facts['Mặc định cho']);
    }
}
