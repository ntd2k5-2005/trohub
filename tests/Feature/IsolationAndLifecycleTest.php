<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\Billing\BillingService;
use App\Modules\Billing\Models\ServiceRate;
use App\Modules\DataPlatform\Contracts\ProjectionStore;
use App\Modules\DataPlatform\EventIngestion;
use App\Modules\DataPlatform\Models\DomainEvent;
use App\Modules\DataPlatform\Models\QuarantinedEvent;
use App\Modules\DataPlatform\ProjectionManager;
use App\Modules\IoT\IoTService;
use App\Modules\IoT\Models\Device;
use App\Modules\IoT\Models\SafetyEvent;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\RentalRequestService;
use App\Modules\Rental\RentalService;
use App\Modules\Subscription\Models\SubscriptionOrder;
use App\Modules\Subscription\SubscriptionService;
use App\Modules\Subscription\TrialPurge;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IsolationAndLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function u(string $key): User
    {
        return User::where('email', $key.'@trohub.test')->firstOrFail();
    }

    public function test_property_scoped_staff_never_sees_unassigned_property(): void
    {
        $w = Workspace::where('owner_id', $this->u('owner')->id)->first();
        $p = Property::create(['workspace_id' => $w->id, 'name' => 'Không được xem', 'address' => 'Địa chỉ bí mật', 'district' => 'Quận khác']);
        $room = Room::create(['property_id' => $p->id, 'name' => 'Phòng riêng tư', 'area' => 25, 'rent' => 1000000]);
        $this->actingAs($this->u('staff'))->get('/ui/properties?context=workspace')->assertOk()->assertDontSee('Địa chỉ bí mật');
        $this->get('/ui/room-detail?context=workspace&id='.$room->id)->assertNotFound();
        $this->post('/actions/iot.register', ['context' => 'workspace', 'room_id' => $room->id, 'name' => 'Device', 'serial' => 'UNKNOWN'])->assertForbidden();
    }

    public function test_direct_grant_end_boundary_and_membership_revocation(): void
    {
        $staff = $this->u('accountant');
        $owner = $this->u('owner');
        $w = Workspace::where('owner_id', $owner->id)->first();
        $g = DirectGrant::create(['user_id' => $staff->id, 'workspace_id' => $w->id, 'permission' => 'iot.view', 'grantor_id' => $owner->id, 'valid_from' => now()->subHour(), 'valid_until' => now()->addHour(), 'reason' => 'Bảo trì']);
        $access = app(AccessService::class);
        $this->assertTrue($access->allowed($staff, 'iot.view', $w, Membership::where('user_id', $staff->id)->first()->property_ids[0]));
        $this->travel(1)->hours();
        $this->assertFalse($access->allowed($staff, 'iot.view', $w, Membership::where('user_id', $staff->id)->first()->property_ids[0]));
        $this->travelBack();
        Membership::where('user_id', $staff->id)->update(['status' => 'ended']);
        $this->assertFalse($access->allowed($staff, 'invoice.view', $w));
    }

    public function test_trial_purge_preserves_global_identity_and_other_paid_workspace(): void
    {
        $user = User::factory()->create();
        $w = app(SubscriptionService::class)->trial($user, 'Dùng thử');
        $p = Property::create(['workspace_id' => $w->id, 'name' => 'Nhà thử', 'address' => 'Địa chỉ thử', 'district' => 'Thử']);
        $r = Room::create(['property_id' => $p->id, 'name' => 'Phòng thử', 'area' => 20, 'rent' => 1000000]);
        $paidCount = Room::count() - 1;
        $w->update(['expires_at' => now()->subDays(9), 'retention_until' => now()->subDay()]);
        $this->assertSame(0, app(TrialPurge::class)->run());
        $this->assertNotNull($user->fresh());
        $this->assertSame($paidCount + 1, Room::count());
        $result = app(EventIngestion::class)->ingest(['event_id' => (string) Str::uuid(), 'workspace_id' => $w->id, 'source' => 'trial', 'event_type' => 'view', 'schema_version' => 1, 'event_time' => now()->toIso8601String(), 'provenance' => 'trial', 'payload' => ['room_id' => $r->id]]);
        $this->assertNotSame('stale_purged', $result);
    }

    public function test_trial_upgrade_keeps_safe_configuration_and_paid_expiry_keeps_history(): void
    {
        $u = User::factory()->create();
        $service = app(SubscriptionService::class);
        $w = $service->trial($u, 'Dùng thử');
        $p = Property::create(['workspace_id' => $w->id, 'name' => 'Nhà', 'address' => 'Địa chỉ', 'district' => 'Khu vực']);
        \Tests\Support\SubscriptionFixture::renew($w, $this->u('platform'));
        $this->assertSame('active', $w->fresh()->status);
        $this->assertNull($w->fresh()->retention_until);
        $this->assertNotNull($p->fresh());
        $w->update(['expires_at' => now()->subDays(9)]);
        $service->expire();
        $this->assertSame('suspended', $w->fresh()->status);
        $this->assertNotNull($p->fresh());
        $this->assertSame(0, app(TrialPurge::class)->run());
    }

    public function test_trial_can_activate_real_rental(): void
    {
        $u = User::factory()->create();
        $w = app(SubscriptionService::class)->trial($u, 'Dùng thử');
        $p = Property::create(['workspace_id' => $w->id, 'name' => 'Nhà', 'address' => 'Địa chỉ', 'district' => 'Khu vực']);
        $room = Room::create(['property_id' => $p->id, 'name' => '101', 'area' => 20, 'rent' => 1000000]);
        $r = app(RentalService::class)->create($u, ['room_id' => $room->id, 'tenant_id' => $this->u('tenant')->id, 'starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'rent' => 1000000, 'deposit' => 0, 'terms' => 'Điều khoản']);
        $r->update(['confirmed_at' => now()]);
        app(RentalRequestService::class)->reserveManual($u, $w, $r->fresh());
        app(RentalService::class)->act($u, $r, 'activate');
        $this->assertSame('active', $r->fresh()->status);
        $this->assertSame('occupied', $room->fresh()->status);
    }

    public function test_device_move_keeps_old_attribution_and_hides_other_room_from_tenant(): void
    {
        $d = Device::first();
        $old = $d->assignments()->first()->room_id;
        $time = now()->subMinute();
        $target = Room::where('property_id', Room::find($old)->property_id)->where('id', '!=', $old)->first();
        app(IoTService::class)->move($this->u('owner'), $d, $target->id);
        app(IoTService::class)->ingest($d, ['event_id' => 'old-event', 'event_time' => $time->toIso8601String(), 'schema_version' => 1, 'config_version' => 1, 'risk' => 'danger', 'health' => 'healthy', 'reason' => 'Cảnh báo cũ']);
        $this->assertEquals($old, SafetyEvent::first()->room_id);
        $this->assertSame('unknown', $d->fresh()->risk);
        $this->actingAs($this->u('tenant'))->get('/ui/room-safety?context=personal')->assertOk()->assertDontSee($d->name);
    }

    public function test_event_replay_lateness_privacy_and_optional_projection_failure(): void
    {
        $ingest = app(EventIngestion::class);
        $data = ['event_id' => (string) Str::uuid(), 'source' => 'web', 'event_type' => 'search', 'schema_version' => 1, 'event_time' => now()->subHour()->toIso8601String(), 'provenance' => 'production', 'payload' => ['district' => 'Bình Thạnh']];
        $this->assertSame('accepted', $ingest->ingest($data));
        $this->assertSame('duplicate', $ingest->ingest($data));
        $this->assertTrue(DomainEvent::where('event_id', $data['event_id'])->first()->late);
        $this->assertSame('quarantined', $ingest->ingest(array_replace($data, ['event_id' => (string) Str::uuid(), 'payload' => ['email' => 'secret@example.com']])));
        $this->assertStringNotContainsString('secret', QuarantinedEvent::first()->toJson());
        app()->instance('projections.mongodb', new class implements ProjectionStore
        {
            public function put(array $event): void
            {
                throw new \RuntimeException('Infrastructure unavailable');
            }

            public function history(int $workspaceId, string $from, string $until, int $limit = 100): array
            {
                throw new \RuntimeException('Infrastructure unavailable');
            }

            public function purge(int $workspaceId): void
            {
                throw new \RuntimeException('Infrastructure unavailable');
            }
        });
        config(['data_stores.mongodb.enabled' => true]);
        $this->assertSame(0, app(ProjectionManager::class)->drain());
        $this->assertNotNull(DomainEvent::first()->last_error);
        app()->forgetInstance('projections.mongodb');
        config(['data_stores.mongodb.enabled' => false]);
        $this->assertGreaterThan(0, app(ProjectionManager::class)->drain());
        $this->assertSame(0, app(ProjectionManager::class)->drain());
    }

    public function test_meter_cannot_decrease_without_reason(): void
    {
        $rate = ServiceRate::where('kind', 'electricity')->first();
        $room = Room::where('property_id', $rate->property_id)->first();
        $service = app(BillingService::class);
        $service->reading($this->u('owner'), ['room_id' => $room->id, 'service_rate_id' => $rate->id, 'period' => '2026-08', 'current' => 100]);
        $this->expectException(ValidationException::class);
        $service->reading($this->u('owner'), ['room_id' => $room->id, 'service_rate_id' => $rate->id, 'period' => '2026-09', 'current' => 10]);
    }

    public function test_locked_account_loses_existing_session_access(): void
    {
        $u = $this->u('tenant');
        $this->actingAs($u);
        $u->forceFill(['status' => 'locked'])->save();
        $this->get('/ui/personal-dashboard?context=personal')->assertForbidden();
    }
}
