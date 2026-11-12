<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\Analytics\AnalyticsService;
use App\Modules\Billing\BillingService;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\ServiceRate;
use App\Modules\Connection\ConnectionService;
use App\Modules\DataPlatform\Models\DomainEvent;
use App\Modules\DataPlatform\ProjectionManager;
use App\Modules\IoT\Models\Device;
use App\Modules\Listing\ListingService;
use App\Modules\Listing\Models\Listing;
use App\Modules\Maintenance\MaintenanceService;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\Models\RoomReservation;
use App\Modules\Rental\RentalRequestService;
use App\Modules\Rental\RentalService;
use App\Modules\Roommate\Models\RoommateNeed;
use App\Modules\Roommate\Models\RoommateRequest;
use App\Modules\Subscription\Models\SubscriptionOrder;
use App\Modules\Subscription\SubscriptionService;
use App\Modules\Trust\Models\Report;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\WorkspaceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_projection_replay_rebuilds_without_duplicate_rows_or_business_changes(): void
    {
        $workspace = Workspace::first();
        app(\App\Modules\DataPlatform\InteractionEvents::class)->record('listing.viewed', $workspace, 1);
        $manager = app(ProjectionManager::class);
        $this->assertSame(1, $manager->drain(100, $workspace->id));
        $count = DB::table('projection_records')->count();
        $this->assertSame(1, $manager->replay($workspace->id, today()->toDateTimeString(), now()->addMinute()->toDateTimeString()));
        $this->assertSame(1, $manager->drain(100, $workspace->id));
        $this->assertSame($count, DB::table('projection_records')->count());
        $this->assertSame($workspace->status, $workspace->fresh()->status);
    }

    public function test_viewing_proposal_requires_other_party_and_confirmation_cannot_change_time(): void
    {
        $listing = app(ListingService::class)->available()->whereHas('room.property.workspace', fn ($q) => $q->where('owner_id', $this->u('owner')->id))->first();
        $service = app(ConnectionService::class);
        $connection = $service->create($this->u('tenant'), $listing->id, 'Tôi muốn xem phòng');
        $service->transition($this->u('owner'), $connection, 'contacted');
        $time = now()->addDays(2)->format('Y-m-d H:i:s');
        $service->transition($this->u('tenant'), $connection->fresh(), 'viewing_scheduled', $time);
        $this->actingAs($this->u('tenant'))->post('/actions/connection.update', ['context' => 'personal', 'id' => $connection->id, 'status' => 'viewing_confirmed'])->assertForbidden();
        $service->transition($this->u('owner'), $connection->fresh(), 'viewing_confirmed', now()->addMonth()->toDateTimeString());
        $this->assertSame($time, $connection->fresh()->viewing_at);
    }

    public function test_maintenance_notes_belong_to_reporter_or_authorized_manager(): void
    {
        $service = app(MaintenanceService::class);
        $issue = $service->create($this->u('tenant'), ['room_id' => Rental::first()->room_id, 'title' => 'Rò nước', 'description' => 'Cần kiểm tra vòi nước']);
        $this->actingAs($this->u('other'))->post('/actions/maintenance.comment', ['context' => 'personal', 'id' => $issue->id, 'note' => 'Không thuộc phạm vi'])->assertForbidden();
        $this->actingAs($this->u('tenant'))->post('/actions/maintenance.comment', ['context' => 'personal', 'id' => $issue->id, 'note' => 'Nước rò ở đầu nối'])->assertSessionHasNoErrors()->assertRedirect();
        $this->get('/ui/maintenance?context=personal')->assertOk()->assertSee('Nước rò ở đầu nối');
    }

    public function test_unpriced_registration_remains_pending_without_granting_paid_access(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/actions/subscription.start', ['context' => 'personal', 'name' => 'Nhà mới', 'plan' => 'monthly', 'reference' => 'DIRECT-PAID'])->assertSessionHasNoErrors()->assertRedirect();
        $workspace = Workspace::where('owner_id', $user->id)->firstOrFail();
        $this->assertSame('pending', $workspace->effective_status);
        $this->assertFalse(app(AccessService::class)->allowed($user, 'property.manage', $workspace));
        $this->actingAs($this->u('platform'))->post('/actions/subscription.confirm', ['context'=>'platform-admin','id'=>SubscriptionOrder::where('workspace_id', $workspace->id)->value('id')])->assertConflict();
        $this->assertSame('pending', $workspace->fresh()->status);
        $this->assertFalse(app(AccessService::class)->allowed($user, 'property.manage', $workspace->fresh()));
    }

    public function test_membership_update_cannot_cross_workspace_and_takes_effect_immediately(): void
    {
        $workspace = Workspace::where('owner_id', $this->u('owner')->id)->first();
        $member = Membership::where('workspace_id', $workspace->id)->where('user_id', $this->u('staff')->id)->first();
        $group = AccessGroup::where('name', 'Kế toán')->first();
        $this->actingAs($this->u('owner'))->post('/actions/member.update', ['context' => 'workspace', 'id' => $member->id, 'access_group_id' => $group->id, 'permissions' => $group->permissions, 'property_ids' => [$workspace->properties()->first()->id], 'valid_until' => now()->addDay()->toDateTimeString()])->assertSessionHasNoErrors()->assertRedirect();
        $access = app(AccessService::class);
        $this->assertFalse($access->allowed($this->u('staff'), 'iot.manage', $workspace));
        $this->assertTrue($access->allowed($this->u('staff'), 'invoice.manage', $workspace, $workspace->properties()->first()->id));
        $this->actingAs($this->u('other'))->post('/actions/member.update', ['context' => 'workspace', 'workspace_id' => Workspace::where('owner_id', $this->u('other')->id)->value('id'), 'id' => $member->id, 'access_group_id' => $group->id])->assertForbidden();
    }

    public function test_payment_reminders_are_idempotent_and_stop_after_payment(): void
    {
        $invoice = Invoice::first();
        $invoice->update(['due_at' => today()->subDay()]);
        $service = app(BillingService::class);
        $this->assertSame(1, $service->remindDueInvoices());
        $this->assertSame(0, $service->remindDueInvoices());
        $service->pay($this->u('owner'), $invoice, ['amount' => $invoice->outstanding, 'reference' => 'REMINDER-PAID', 'method' => 'cash']);
        $this->travel(1)->days();
        $this->assertSame(0, $service->remindDueInvoices());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function u(string $name): User
    {
        return User::where('email', $name.'@trohub.test')->firstOrFail();
    }

    public function test_login_returns_to_pending_invitation(): void
    {
        $url = url('/loi-moi/pending-token');
        $this->withSession(['url.intended' => $url])->post('/dang-nhap', ['email' => 'tenant@trohub.test', 'password' => 'Abcd@1234'])->assertRedirect($url);
    }

    public function test_roommate_acceptance_shares_contact_only_after_agreement(): void
    {
        $need = RoommateNeed::create(['user_id' => $this->u('owner')->id, 'district' => 'Bình Thạnh', 'budget' => 2000000, 'move_in' => today()->addWeek()->toDateString(), 'lifestyle' => 'Yên tĩnh', 'expires_at' => now()->addMonth()]);
        $this->actingAs($this->u('tenant'))->post('/actions/roommate.connect', ['context' => 'personal', 'id' => $need->id])->assertSessionHasNoErrors()->assertRedirect();
        $request = RoommateRequest::first();
        $this->get('/ui/roommate-discovery?context=personal')->assertOk()->assertDontSee('owner@trohub.test');
        $this->actingAs($this->u('owner'))->post('/actions/roommate.respond', ['context' => 'personal', 'id' => $request->id, 'status' => 'accepted'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('matched', $need->fresh()->status);
        $this->actingAs($this->u('tenant'))->get('/ui/roommate-discovery?context=personal')->assertOk()->assertSee('owner@trohub.test')->assertSee('Đã đồng ý');
    }

    public function test_platform_can_lock_ordinary_accounts_but_cannot_lock_system_administrators(): void
    {
        $normal = User::factory()->create(['access_group_id' => AccessGroup::where('name', 'Người dùng')->value('id')]);
        $this->actingAs($this->u('platform'))->post('/actions/account.status', ['context' => 'platform-admin', 'id' => $normal->id, 'status' => 'locked', 'reason' => 'Đã xác minh vi phạm'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('locked', $normal->fresh()->status);
        $this->post('/actions/account.status', ['context' => 'platform-admin', 'id' => $this->u('system')->id, 'status' => 'locked', 'reason' => 'Không đúng phạm vi'])->assertForbidden();
        $this->assertSame('active', $this->u('system')->status);
    }

    public function test_report_resolution_is_audited_and_only_available_to_moderators(): void
    {
        $listing = Listing::where('status', 'published')->first();
        $this->actingAs($this->u('tenant'))->post('/actions/report.create', ['context' => 'personal', 'target_type' => 'listing', 'target_id' => $listing->id, 'reason' => 'Nội dung cần được kiểm tra'])->assertSessionHasNoErrors()->assertRedirect();
        $report = Report::first();
        $this->post('/actions/report.resolve', ['context' => 'personal', 'id' => $report->id, 'status' => 'under_review', 'resolution' => 'Đang xem xét'])->assertForbidden();
        $this->actingAs($this->u('platform'))->post('/actions/report.resolve', ['context' => 'platform-admin', 'id' => $report->id, 'status' => 'under_review', 'resolution' => 'Đã tiếp nhận'])->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/actions/report.resolve', ['context' => 'platform-admin', 'id' => $report->id, 'status' => 'resolved', 'resolution' => 'Đã kiểm tra và liên hệ chủ trọ'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('resolved', $report->fresh()->status);
        $this->assertDatabaseHas('audit_entries', ['action' => 'report.resolve', 'actor_id' => $this->u('platform')->id]);
    }

    public function test_new_user_has_only_personal_context_and_email_is_normalized(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->post('/dang-ky', ['name' => 'Khách mới', 'email' => 'NEW@EXAMPLE.TEST', 'password' => 'StrongPass@2026', 'password_confirmation' => 'StrongPass@2026'])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'new@example.test', 'status' => 'active']);
        $this->assertGuest();
        $newUser = User::where('email', 'new@example.test')->firstOrFail();
        $otp = \Illuminate\Support\Facades\Mail::sent(\App\Modules\Identity\AuthEmail::class)->last()->contentData['otp'];
        $this->post('/xac-minh-email/otp', ['otp' => $otp])->assertRedirect('/dang-nhap');
        $this->post('/dang-nhap', ['email' => 'new@example.test', 'password' => 'StrongPass@2026'])->assertRedirect();
        $this->get('/ui/personal-dashboard?context=personal')->assertOk();
        $this->get('/ui/properties?context=workspace')->assertForbidden();
    }

    public function test_pending_resident_claims_own_identity_through_single_use_invitation(): void
    {
        $owner = $this->u('owner');
        $room = Room::where('status', 'vacant')->first();
        $this->actingAs($owner)->post('/actions/rental.create', ['context' => 'workspace', 'room_id' => $room->id, 'tenant_email' => 'newtenant@example.test', 'tenant_name' => 'Người thuê mới', 'starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'rent' => 3000000, 'deposit' => 0, 'terms' => 'Điều khoản'])->assertSessionHasNoErrors()->assertRedirect();
        $r = Rental::where('room_id', $room->id)->first();
        $this->assertNull($r->tenant_id);
        $token = app(WorkspaceService::class)->inviteTenant($owner, $r);
        $tenant = User::factory()->create(['email' => 'newtenant@example.test']);
        app(WorkspaceService::class)->accept($tenant, $token);
        $this->assertEquals($tenant->id, $r->fresh()->tenant_id);
        $this->actingAs($tenant)->post('/loi-moi/'.$token)->assertNotFound();
        $this->get('/ui/rentals?context=personal')->assertOk()->assertSee('Điều khoản');
    }

    public function test_deposit_reserves_room_and_cancel_retains_history(): void
    {
        $owner = $this->u('owner');
        $room = Room::where('status', 'vacant')->first();
        $service = app(RentalService::class);
        $r = $service->create($owner, ['room_id' => $room->id, 'tenant_id' => $this->u('tenant')->id, 'starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'rent' => 3000000, 'deposit' => 1000000, 'terms' => 'Điều khoản']);
        $service->act($this->u('tenant'), $r, 'confirm');
        app(RentalRequestService::class)->reserveManual($owner, $room->property->workspace, $r->fresh());
        $service->act($owner, $r, 'deposit', ['amount' => 1000000, 'reference' => 'CASH-ACCEPTANCE', 'confirm' => 1]);
        $this->assertSame('reserved', $room->fresh()->status);
        $this->assertSame('paused', $room->listings()->first()->status);
        $hold = RoomReservation::where('rental_id', $r->id)->firstOrFail();
        app(RentalRequestService::class)->reservationAction($owner, $room->property->workspace, $hold, 'release', $hold->version, ['reason' => 'Hai bên thống nhất hủy và đối soát hoàn cọc riêng']);
        $this->assertSame('vacant', $room->fresh()->status);
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertNull($r->deposits()->first()->settled_at);
    }

    public function test_move_out_requires_final_readings_settlement_and_preserves_financial_history(): void
    {
        $owner = $this->u('owner');
        $r = Rental::first();
        $billing = app(BillingService::class);
        $service = app(RentalService::class);
        $invoice = Invoice::first();
        $billing->pay($owner, $invoice, ['amount' => $invoice->total, 'reference' => 'FINAL-PAY', 'method' => 'cash']);
        foreach (ServiceRate::where('property_id', $r->room->property_id)->whereIn('kind', ['electricity', 'water'])->get() as $rate) {
            $billing->reading($owner, ['room_id' => $r->room_id, 'service_rate_id' => $rate->id, 'period' => today()->format('Y-m'), 'current' => 0]);
        }
        $service->act($this->u('tenant'), $r, 'request_move_out', ['move_out_at' => today()->toDateString()]);
        $service->act($owner, $r, 'settle', ['deduction' => 0, 'reason' => 'Hoàn đủ cọc']);
        $service->act($owner, $r, 'close', ['handover_complete' => true]);
        $this->assertSame('closed', $r->fresh()->status);
        $this->assertSame('vacant', $r->room->fresh()->status);
        $this->assertNotNull($invoice->fresh());
        $this->actingAs($this->u('tenant'))->get('/ui/my-invoices?context=personal')->assertOk()->assertSee('Đã thanh toán');
        $this->get('/ui/room-safety?context=personal')->assertOk()->assertDontSee(Device::first()->name);
    }

    public function test_unpublished_listing_cannot_be_saved_to_leak_private_draft(): void
    {
        $draft = Listing::first();
        $draft->update(['status' => 'draft']);
        $this->actingAs($this->u('tenant'))->post('/actions/favorite.toggle', ['context' => 'personal', 'id' => $draft->id])->assertNotFound();
    }

    public function test_device_ingestion_requires_token_and_survives_duplicate_http_delivery(): void
    {
        $data = ['event_id' => 'HTTP-E1', 'event_time' => now()->toIso8601String(), 'schema_version' => 1, 'config_version' => 1, 'risk' => 'danger', 'health' => 'healthy', 'reason' => 'Cảnh báo kiểm thử'];
        $this->postJson('/api/iot/events', $data)->assertUnauthorized();
        $headers = ['Authorization' => 'Bearer development-device-token-change-before-deployment'];
        $this->postJson('/api/iot/events', $data, $headers)->assertOk();
        $this->postJson('/api/iot/events', $data, $headers)->assertOk();
        $this->assertDatabaseCount('safety_events', 1);
    }

    public function test_forecast_has_real_history_gate_and_temporal_validation(): void
    {
        config(['features.analytics' => true]);
        $workspace = Workspace::first();
        $service = app(AnalyticsService::class);
        $this->assertSame('insufficient_data', $service->forecast($workspace->id, today()->toDateTimeString(), today()->addDay()->toDateTimeString())['status']);
        for ($day = 1; $day <= 20; $day++) {
            for ($n = 0; $n < 2; $n++) {
                DomainEvent::create(['event_id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'source' => 'web', 'event_type' => 'listing.viewed', 'schema_version' => 1, 'event_time' => today()->subDays($day), 'ingest_time' => now(), 'provenance' => $workspace->provenance, 'payload' => ['resource_id' => 1]]);
            }
        }
        $result = $service->forecast($workspace->id, today()->toDateTimeString(), today()->addDay()->toDateTimeString());
        $this->assertSame('ready', $result['status']);
        $this->assertEquals(2, $result['value']);
        $this->assertArrayHasKey('dataset_version', $result);
        $this->assertSame('invalid_time_split', $service->forecast($workspace->id, today()->toDateTimeString(), today()->toDateTimeString())['status']);
    }
}
