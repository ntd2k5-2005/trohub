<?php

namespace Tests\Feature;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Billing\BillingService;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\Connection\ConnectionService;
use App\Modules\Connection\ConversationService;
use App\Modules\DataPlatform\InteractionEvents;
use App\Modules\DataPlatform\Journal;
use App\Modules\DataPlatform\Models\AuditEntry;
use App\Modules\DataPlatform\Models\DomainEvent;
use App\Modules\DataPlatform\ProjectionManager;
use App\Modules\IoT\Models\Device;
use App\Modules\IoT\Models\DeviceConfig;
use App\Modules\IoT\Models\SafetyEvent;
use App\Modules\Listing\ListingService;
use App\Modules\Listing\Models\Favorite;
use App\Modules\Listing\Models\Listing;
use App\Modules\Notification\Models\UserNotification;
use App\Modules\Notification\NotificationService;
use App\Modules\Notification\WorkflowReminders;
use App\Modules\Rental\Models\Occupant;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\RentalService;
use App\Modules\Subscription\Models\SubscriptionOrder;
use App\Modules\Subscription\PropertyCloud;
use App\Modules\Subscription\SubscriptionService;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Support\ScopedRepository;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PolicyCutoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@trohub.test')->firstOrFail();
    }

    private function workspace(): Workspace
    {
        return Workspace::where('owner_id', $this->user('owner')->id)->firstOrFail();
    }

    private function listing(): Listing
    {
        return app(ListingService::class)->available()->whereHas('room.property', fn ($q) => $q->where('workspace_id', $this->workspace()->id))->firstOrFail();
    }

    private function renew(Workspace $workspace): void
    {
        \Tests\Support\SubscriptionFixture::renew($workspace, $this->user('platform'));
    }

    private function forbidden(callable $operation, int $status = 403): void
    {
        try {
            $operation();
            $this->fail('Thao tác phải bị chặn.');
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_expiry_boundary_blocks_cloud_without_scheduler_and_preserves_renewal(): void
    {
        $workspace = $this->workspace();
        $listing = $this->listing();
        Favorite::create(['user_id' => $this->user('tenant')->id, 'listing_id' => $listing->id]);
        $workspace->update(['expires_at' => now()->addMinute()]);
        $this->travelTo($workspace->fresh()->expires_at);
        $access = app(Authorization::class);
        foreach (['owner', 'staff', 'accountant', 'system'] as $role) {
            foreach (['property.view', 'invoice.view', 'invoice.issue', 'payment.verify', 'chat.view', 'iot.view'] as $permission) {
                $this->assertFalse($access->allowed($this->user($role), $permission, $workspace, $listing->room->property_id, $listing->room_id));
            }
        }
        $this->assertTrue($access->allowed($this->user('owner'), 'subscription.manage', $workspace));
        $this->assertSame('suspended', $workspace->fresh()->effective_status);
        $this->assertFalse(app(ListingService::class)->available()->whereKey($listing->id)->exists());
        $this->get('/ui/listing-detail?context=public&id='.$listing->id)->assertNotFound();
        $this->actingAs($this->user('tenant'))->get('/ui/favorites?context=personal')->assertOk()->assertDontSee($listing->title);
        $this->actingAs($this->user('owner'))->get('/ui/subscription?context=workspace&workspace_id='.$workspace->id)->assertOk();
    }

    public function test_suspension_and_owner_lock_block_public_cloud_immediately(): void
    {
        $listing = $this->listing();
        $workspace = $this->workspace();
        $workspace->update(['status' => 'suspended']);
        $this->assertFalse(app(PropertyCloud::class)->active($workspace));
        $this->assertFalse(app(ListingService::class)->available()->whereKey($listing->id)->exists());
        $workspace->update(['status' => 'active']);
        $this->user('owner')->forceFill(['status' => 'locked'])->save();
        $this->assertFalse(app(PropertyCloud::class)->active($workspace));
        $this->assertFalse(app(ListingService::class)->available()->whereKey($listing->id)->exists());
    }

    public function test_tenant_read_exception_is_own_confirmed_documents_only(): void
    {
        $rental = Rental::where('tenant_id', $this->user('tenant')->id)->firstOrFail();
        $occupant = User::factory()->create();
        app(\App\Modules\AccessControl\GroupAssignments::class)->tenant($occupant);
        Occupant::create(['rental_id' => $rental->id, 'user_id' => $occupant->id, 'relationship' => 'other', 'starts_at' => today()]);
        $this->workspace()->update(['expires_at' => now()]);
        $this->assertSame([], app(ScopedRepository::class)->currentRoomIds($this->user('tenant')));
        $this->assertFalse(app(ScopedRepository::class)->rentals($occupant)->whereKey($rental->id)->exists());
        $this->actingAs($this->user('tenant'))->get('/ui/rental-detail?context=personal&id='.$rental->id)->assertOk()->assertSee('Hợp đồng đã xác nhận')->assertDontSee('Yêu cầu trả phòng')->assertDontSee('Cảm biến');
        $this->get('/ui/my-invoices?context=personal')->assertOk()->assertSee('Hóa đơn');
        $this->post('/actions/rental.request_move_out', ['context' => 'personal', 'id' => $rental->id, 'move_out_at' => today()->toDateString()])->assertForbidden();
        $this->forbidden(fn () => app(RentalService::class)->act($this->user('tenant'), $rental, 'request_move_out', ['move_out_at' => today()->toDateString()]));
        $this->actingAs($occupant)->get('/ui/rental-detail?context=personal&id='.$rental->id)->assertNotFound();
        $rental->update(['confirmed_at' => null]);
        $this->assertTrue(app(ScopedRepository::class)->rentals($this->user('tenant'))->whereKey($rental->id)->exists());
        $unfunded = Rental::create(['room_id' => \App\Modules\Property\Models\Room::where('status', 'vacant')->firstOrFail()->id, 'tenant_id' => $this->user('tenant')->id, 'starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'rent' => 1000000, 'deposit' => 0, 'terms' => 'Chưa xác nhận', 'status' => 'draft']);
        $this->assertFalse(app(ScopedRepository::class)->rentals($this->user('tenant'))->whereKey($unfunded->id)->exists());
    }

    public function test_expiry_keeps_history_and_notifications_do_not_resurrect_after_renewal(): void
    {
        $workspace = $this->workspace();
        $tenant = $this->user('tenant');
        $notifications = app(NotificationService::class);
        $notifications->once($tenant->id, 'account:policy', 'Tài khoản', 'Hỗ trợ tài khoản');
        $notifications->once($tenant->id, 'property:policy', 'Phòng', 'Thông báo phòng', $workspace->id);
        $counts = [Rental::count(), Invoice::count(), DB::table('deposits')->count()];
        $workspace->update(['expires_at' => now()]);
        $this->assertSame(1, UserNotification::where('user_id', $tenant->id)->count());
        app(SubscriptionService::class)->expire();
        $epoch = $workspace->fresh()->cloud_epoch;
        app(SubscriptionService::class)->expire();
        $this->assertSame($epoch, $workspace->fresh()->cloud_epoch);
        $this->renew($workspace);
        $this->assertSame(1, UserNotification::where('user_id', $tenant->id)->count());
        $this->assertSame(2, UserNotification::withoutGlobalScopes()->where('user_id', $tenant->id)->count());
        $notifications->once($tenant->id, 'property:policy', 'Phòng mới', 'Sự kiện mới', $workspace->id);
        $this->assertSame(2, UserNotification::where('user_id', $tenant->id)->count());
        $this->assertSame($counts, [Rental::count(), Invoice::count(), DB::table('deposits')->count()]);
    }

    public function test_renewal_without_cron_does_not_restore_old_listing_or_stamped_outbox(): void
    {
        $workspace = $this->workspace();
        $listing = $this->listing();
        app(InteractionEvents::class)->record('listing.viewed', $workspace, $listing->id);
        $old = DomainEvent::latest('id')->firstOrFail();
        $workspace->update(['expires_at' => now()]);
        $this->renew($workspace);
        $this->assertFalse(app(ListingService::class)->available()->whereKey($listing->id)->exists());
        $manager = app(ProjectionManager::class);
        $manager->drain(1000, $workspace->id);
        $this->assertSame('RC12_SUPPRESSED', $old->fresh()->last_error);
        $this->assertFalse(DB::table('projection_records')->where('event_id', $old->event_id)->exists());
        $manager->replay($workspace->id, today()->toDateTimeString(), now()->addMinute()->toDateTimeString());
        $this->assertNotNull($old->fresh()->projected_at);
        app(ListingService::class)->status($this->user('owner'), $listing->id, 'published');
        $this->assertTrue(app(ListingService::class)->available()->whereKey($listing->id)->exists());
        app(InteractionEvents::class)->record('listing.viewed', $workspace->fresh(), $listing->id);
        $new = DomainEvent::latest('id')->firstOrFail();
        $manager->drain(1000, $workspace->id);
        $this->assertNull($new->fresh()->last_error);
        $this->assertTrue(DB::table('projection_records')->where('event_id', $new->event_id)->exists());
    }

    public function test_signed_stamp_and_commit_guard_reject_drift_and_roll_back(): void
    {
        $cloud = app(PropertyCloud::class);
        $workspace = $this->workspace();
        $stamp = $cloud->snapshot($workspace);
        $this->assertTrue($cloud->matches($workspace->id, $stamp));
        $forged = $stamp;
        $forged['epoch']++;
        $this->assertFalse($cloud->matches($workspace->id, $forged));
        $before = $workspace->name;
        $this->forbidden(fn () => $cloud->transaction($workspace->id, function () use ($workspace) {
            $workspace->update(['name' => 'Không được ghi', 'expires_at' => now()]);
        }));
        $this->assertSame($before, $workspace->fresh()->name);
        $this->assertTrue($cloud->active($workspace));
    }

    public function test_authoritative_audit_failure_rolls_back_money_and_outbox(): void
    {
        $before = [Payment::count(), DomainEvent::count(), UserNotification::withoutGlobalScopes()->count()];
        AuditEntry::creating(fn () => throw new \RuntimeException('Injected audit storage failure'));
        try {
            app(BillingService::class)->pay($this->user('owner'), Invoice::firstOrFail(), ['amount' => 1000, 'method' => 'cash', 'reference' => 'AUDIT-ROLLBACK']);
            $this->fail('Không được commit khi mất audit.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected audit storage failure', $e->getMessage());
        } finally {
            AuditEntry::flushEventListeners();
        }
        $this->assertSame($before, [Payment::count(), DomainEvent::count(), UserNotification::withoutGlobalScopes()->count()]);
    }

    public function test_financial_commit_rechecks_membership_after_business_operation(): void
    {
        $staff = $this->user('accountant');
        $workspace = $this->workspace();
        $before = Payment::count();
        AuditEntry::created(function ($entry) use ($staff, $workspace) {
            if ($entry->action === 'payment.recorded') Membership::where('user_id', $staff->id)->where('workspace_id', $workspace->id)->update(['status' => 'ended']);
        });
        try {
            $this->forbidden(fn () => app(BillingService::class)->pay($staff, Invoice::firstOrFail(), ['amount' => 1000, 'method' => 'cash', 'reference' => 'REVOKED-BEFORE-COMMIT']));
        } finally {
            AuditEntry::flushEventListeners();
        }
        $this->assertSame($before, Payment::count());
    }

    public function test_iot_cloud_stops_without_changing_local_safety_configuration(): void
    {
        $device = Device::firstOrFail();
        $config = DeviceConfig::where('device_id', $device->id)->firstOrFail()->getRawOriginal();
        $before = [SafetyEvent::count(), DomainEvent::count(), UserNotification::withoutGlobalScopes()->count()];
        $this->workspace()->update(['expires_at' => now()]);
        $headers = ['Authorization' => 'Bearer development-device-token-change-before-deployment'];
        $this->getJson('/api/iot/config', $headers)->assertForbidden();
        $this->postJson('/api/iot/events', ['event_id' => 'EXPIRED-CLOUD', 'event_time' => now()->toIso8601String(), 'schema_version' => 1, 'config_version' => 1, 'risk' => 'danger', 'health' => 'healthy', 'reason' => 'Kiểm thử'], $headers)->assertForbidden();
        $this->assertSame($before, [SafetyEvent::count(), DomainEvent::count(), UserNotification::withoutGlobalScopes()->count()]);
        $this->assertSame($config, DeviceConfig::where('device_id', $device->id)->firstOrFail()->getRawOriginal());
        $this->assertSame($device->token_hash, $device->fresh()->token_hash);
    }

    public function test_old_iot_events_and_reminders_are_not_replayed_after_renewal(): void
    {
        $workspace = $this->workspace();
        $oldTime = now()->subHour()->toIso8601String();
        Invoice::firstOrFail()->update(['due_at' => today()]);
        $workspace->update(['expires_at' => now()]);
        $this->renew($workspace);
        $before = UserNotification::withoutGlobalScopes()->count();
        app(WorkflowReminders::class)->run();
        $this->assertSame(0, app(BillingService::class)->remindDueInvoices());
        $this->assertSame($before, UserNotification::withoutGlobalScopes()->count());
        $this->postJson('/api/iot/events', ['event_id' => 'OLD-CLOUD', 'event_time' => $oldTime, 'schema_version' => 1, 'config_version' => 1, 'risk' => 'danger', 'health' => 'healthy', 'reason' => 'Kiểm thử'], ['Authorization' => 'Bearer development-device-token-change-before-deployment'])->assertConflict();
    }

    public function test_expired_property_chat_is_unavailable_to_both_parties(): void
    {
        $c = app(ConnectionService::class)->create($this->user('tenant'), $this->listing()->id, 'Kiểm thử trao đổi');
        $this->workspace()->update(['expires_at' => now()]);
        foreach (['tenant', 'owner', 'staff'] as $role) {
            $this->forbidden(fn () => app(ConversationService::class)->authorized($this->user($role), $c->id));
        }
        $this->forbidden(fn () => app(ConnectionService::class)->transition($this->user('tenant'), $c, 'cancelled'));
    }

    public function test_trial_time_is_preserved_and_early_renewal_keeps_current_period(): void
    {
        $user = User::factory()->create();
        $workspace = app(SubscriptionService::class)->trial($user, 'Dùng thử thật');
        $end = $workspace->expires_at->copy();
        $stamp = app(PropertyCloud::class)->snapshot($workspace);
        $this->renew($workspace);
        $this->assertSame($end->copy()->addMonth()->toDateTimeString(), $workspace->fresh()->expires_at->toDateTimeString());
        $this->assertTrue(app(PropertyCloud::class)->matches($workspace->id, $stamp));
        $this->assertNull($workspace->fresh()->cloud_resumed_at);
        $this->assertSame(1, $workspace->fresh()->cloud_epoch);
    }

    public function test_expired_invitation_does_not_prevent_login_or_send_stale_notice(): void
    {
        $target = User::factory()->create();
        $group = \App\Modules\AccessControl\Models\AccessGroup::where('key', 'custom')->firstOrFail();
        app(\App\Modules\Workspace\WorkspaceService::class)->invite($this->user('owner'), $this->workspace(), ['email' => $target->email, 'access_group_id' => $group->id, 'permissions' => ['property.view']]);
        $this->workspace()->update(['expires_at' => now()]);
        $before = UserNotification::withoutGlobalScopes()->count();
        event(new \Illuminate\Auth\Events\Login('web', $target, false));
        $this->assertSame($before, UserNotification::withoutGlobalScopes()->count());
        $this->assertSame(0, UserNotification::where('user_id', $target->id)->count());
        $this->actingAs($target)->get('/ui/personal-dashboard?context=personal')->assertOk();
    }

    public function test_confirmed_partial_payment_remains_readable_without_pending_proofs_or_payment_actions(): void
    {
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['api.vietqr.io/v2/banks' => \Illuminate\Support\Facades\Http::response(['code' => '00', 'data' => [['bin' => '970415', 'name' => 'Ngân hàng kiểm thử', 'shortName' => 'Kiểm thử', 'transferSupported' => 1]]])]);
        $accounts = app(\App\Modules\Billing\ReceivingAccountService::class);
        $account = $accounts->save($this->user('owner'), $this->workspace(), ['bank_bin' => '970415', 'account_no' => '000000123456', 'account_holder_name' => 'KIEM THU', 'label' => 'Kiểm thử', 'declaration' => 1]);
        $accounts->setDefault($this->user('owner'), $this->workspace(), $account->id, null);
        $invoice = Invoice::firstOrFail();
        $service = app(\App\Modules\Billing\PaymentRequestService::class);
        $payment = $service->issue($this->user('owner'), $this->workspace(), 'invoice', $invoice->id);
        $service->verify($this->user('owner'), $this->workspace(), $payment, 'PARTIAL-CONFIRMED', 1000);
        $service->report($this->user('tenant'), $payment->fresh(), 'UNVERIFIED-PROOF', 'Chứng từ chưa xác nhận bí mật', null);
        $invoice->rental->update(['confirmed_at' => null]);
        $this->workspace()->update(['expires_at' => now()]);
        $response=$this->actingAs($this->user('tenant'))->get('/ui/payments?context=personal')->assertOk()->assertSee('data-record-list',false);
        $detail=$response->viewData('screen')['record_table']['records'][0]['_detail_url'];
        $detailResponse=$this->get($detail)->assertOk();
        $this->assertNotEmpty($detailResponse->viewData('screen')['record_detail']['record']['payment_requests']);
        $detailResponse
            ->assertSee('Chứng từ thanh toán hóa đơn')->assertSee('PARTIAL-CONFIRMED')
            ->assertDontSee('UNVERIFIED-PROOF')->assertDontSee('Thông báo đã chuyển khoản')->assertDontSee('Xem mã QR');
        $this->forbidden(fn () => $service->report($this->user('tenant'), $payment->fresh(), 'NEW-PROOF', null, null));
        $this->forbidden(fn () => $service->verify($this->user('owner'), $this->workspace(), $payment->fresh(), 'NEW-CONFIRM', 1000));
        $this->forbidden(fn () => $service->authorize($this->user('other'), $payment->fresh()));
        $this->assertSame(1, DB::table('payment_request_transactions')->where('payment_request_id', $payment->id)->count());
    }

    public function test_old_reservation_is_retained_for_manual_resolution_without_automatic_replay(): void
    {
        $room = $this->listing()->room;
        $rental = app(RentalService::class)->create($this->user('owner'), ['room_id' => $room->id, 'tenant_id' => $this->user('tenant')->id, 'starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'rent' => 1000000, 'deposit' => 0, 'terms' => 'Điều khoản kiểm thử']);
        app(RentalService::class)->act($this->user('tenant'), $rental, 'confirm');
        app(\App\Modules\Rental\RentalRequestService::class)->reserveManual($this->user('owner'), $this->workspace(), $rental->fresh());
        $hold = \App\Modules\Rental\Models\RoomReservation::where('rental_id', $rental->id)->firstOrFail();
        $this->workspace()->update(['expires_at' => now()]);
        $this->renew($this->workspace());
        $this->travel(3)->days();
        $this->assertSame(0, app(\App\Modules\Rental\RentalRequestService::class)->expire());
        $this->assertSame('deposit_confirmed', $hold->fresh()->status);
        $this->assertSame($room->id, $hold->fresh()->active_room_id);
        $this->assertSame('draft', $rental->fresh()->status);
    }

    public function test_trial_business_cash_payment_survives_expiry_without_purge(): void
    {
        $owner = User::factory()->create();
        $workspace = app(SubscriptionService::class)->trial($owner, 'Nghiệp vụ thật trong dùng thử');
        $property = \App\Modules\Property\Models\Property::create(['workspace_id' => $workspace->id, 'name' => 'Nhà kiểm thử', 'address' => 'Địa chỉ kiểm thử', 'district' => 'Khu kiểm thử']);
        $room = \App\Modules\Property\Models\Room::create(['property_id' => $property->id, 'name' => 'Phòng dùng thử', 'area' => 20, 'rent' => 2000000, 'deposit' => 1000000]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake(['api.vietqr.io/v2/banks' => \Illuminate\Support\Facades\Http::response(['code' => '00', 'data' => [['bin' => '970415', 'name' => 'Ngân hàng kiểm thử', 'shortName' => 'Kiểm thử', 'transferSupported' => 1]]])]);
        $accounts = app(\App\Modules\Billing\ReceivingAccountService::class);
        $account = $accounts->save($owner, $workspace, ['bank_bin' => '970415', 'account_no' => '000000123456', 'account_holder_name' => 'KIEM THU', 'label' => 'Kiểm thử', 'declaration' => 1]);
        $accounts->setDefault($owner, $workspace, $account->id, null);
        $rental = app(RentalService::class)->create($owner, ['room_id' => $room->id, 'tenant_id' => $this->user('tenant')->id, 'starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'rent' => 2000000, 'deposit' => 1000000, 'terms' => 'Điều khoản đã xác nhận']);
        app(RentalService::class)->act($this->user('tenant'), $rental, 'confirm');
        app(\App\Modules\Rental\RentalRequestService::class)->reserveManual($owner, $workspace, $rental->fresh());
        app(\App\Modules\Billing\CashDepositService::class)->record($owner, $rental->fresh(), ['amount' => 1000000, 'reference' => 'TRIAL-REAL-CASH', 'confirm' => 1]);
        $workspace->update(['expires_at' => now()]);
        app(SubscriptionService::class)->expire();
        $this->assertSame(0, app(\App\Modules\Subscription\TrialPurge::class)->run());
        $this->assertSame(1000000, $rental->deposits()->sum('amount'));
        $this->assertSame(1, DB::table('payment_request_transactions')->where('reference', 'TRIAL-REAL-CASH')->count());
        $this->assertNotNull($rental->fresh());
        $request = \App\Modules\Billing\Models\PaymentRequest::where('rental_id', $rental->id)->firstOrFail();
        $history = app(\App\Modules\Billing\PaymentRequestPresenter::class)->present($this->user('tenant'), null, $request);
        $this->assertTrue($history->history_only);
        $this->assertSame(1000000, $history->amount);
        $this->assertCount(1, $history->receipts);
        $this->actingAs($this->user('tenant'))->get('/ui/rental-detail?context=personal&id='.$rental->id)->assertOk()->assertSee('TRIAL-REAL-CASH')->assertDontSee('Kích hoạt thuê');
    }
}
