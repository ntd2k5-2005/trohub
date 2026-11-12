<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Billing\BillingService;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use App\Modules\IoT\IoTService;
use App\Modules\IoT\Models\Device;
use App\Modules\IoT\Models\SafetyEvent;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\RentalRequestService;
use App\Modules\Rental\RentalService;
use App\Modules\Workspace\Models\Workspace;
use App\Support\ModuleRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BusinessCoreTest extends TestCase
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

    public function test_all_authorized_screens_render_using_real_data(): void
    {
        foreach (['owner' => ['personal', 'workspace'], 'tenant' => ['personal'], 'platform' => ['platform-admin'], 'system' => ['system-admin']] as $user => $contexts) {
            $this->actingAs($this->user($user));
            foreach ($contexts as $context) {
                foreach (ModuleRegistry::pages() as $page) {
                    if (! in_array($context, $page['contexts']) || in_array($page['slug'], ['room-detail', 'rental-detail', 'listing-detail'])) {
                        continue;
                    }
                    $response = $this->get('/ui/'.$page['slug'].'?context='.$context);
                    $denied = ($user === 'system' && $page['slug'] === 'access-control') || ($user === 'owner' && $context === 'personal' && in_array($page['slug'], ['payments', 'my-invoices', 'maintenance', 'room-safety', 'room-safety-history'], true));
                    $this->assertSame($denied ? 403 : 200, $response->status(), $user.' '.$context.' '.$page['slug']);
                }
            }
        }
    }

    public function test_public_search_and_guest_scope(): void
    {
        $this->get('/ui/marketplace-search?context=public')->assertOk()->assertSee('Tìm một nơi ở');
        $this->get('/ui/marketplace-search?context=public&max_price=1')->assertOk()->assertSee('Chưa có dữ liệu phù hợp');
        $this->get('/ui/workspace-dashboard?context=workspace&_profile=landlord')->assertForbidden();
    }

    public function test_tenant_cannot_access_other_room_iot_or_manage_finance(): void
    {
        $this->actingAs($this->user('tenant'));
        $this->get('/ui/iot-devices?context=workspace')->assertForbidden();
        $this->get('/ui/room-safety?context=personal&room_id='.Room::latest('id')->first()->id)->assertForbidden();
        $invoice = Invoice::first();
        $this->post('/actions/payment.create', ['context' => 'personal', 'id' => $invoice->id, 'amount' => 1000, 'reference' => 'attacker', 'method' => 'cash'])->assertForbidden();
    }

    public function test_workspace_isolation(): void
    {
        $owner = $this->user('owner');
        $other = $this->user('other');
        $w = Workspace::where('owner_id', $other->id)->first();
        $this->actingAs($owner)->get('/ui/properties?context=workspace&workspace_id='.$w->id)->assertForbidden();
        $room = Room::whereIn('property_id', $w->properties()->select('id'))->first();
        $this->post('/actions/listing.save', ['context' => 'workspace', 'room_id' => $room->id, 'title' => 'test', 'description' => 'test', 'status' => 'published'])->assertForbidden();
    }

    public function test_partial_payment_idempotency_and_overpayment(): void
    {
        $owner = $this->user('owner');
        $i = Invoice::first();
        $service = app(BillingService::class);
        $data = ['amount' => 1000000, 'reference' => 'PAY-1', 'method' => 'cash'];
        $service->pay($owner, $i, $data);
        $service->pay($owner, $i, $data);
        $this->assertSame(1, Payment::count());
        $this->assertSame('partial', $i->fresh()->payment_state);
        $this->assertEquals($i->total - 1000000, $i->fresh()->outstanding);
        $service->pay($owner, $i, ['amount' => $i->total - 1000000, 'reference' => 'PAY-2', 'method' => 'cash']);
        $this->assertSame('paid', $i->fresh()->payment_state);
        $this->expectException(ValidationException::class);
        $service->pay($owner, $i, ['amount' => 1, 'reference' => 'PAY-3', 'method' => 'cash']);
    }

    public function test_activate_rental_changes_room_and_listing_and_blocks_overlap(): void
    {
        $u = $this->user('owner');
        $room = Room::where('status', 'vacant')->first();
        $service = app(RentalService::class);
        $data = ['room_id' => $room->id, 'tenant_id' => $this->user('tenant')->id, 'starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'rent' => 3000000, 'deposit' => 0, 'terms' => 'Điều khoản'];
        $a = $service->create($u, $data);
        $b = $service->create($u, $data);
        $service->act($this->user('tenant'), $a, 'confirm');
        $service->act($this->user('tenant'), $b, 'confirm');
        app(RentalRequestService::class)->reserveManual($u, $room->property->workspace, $a->fresh());
        $service->act($u, $a, 'activate');
        $this->assertSame('occupied', $room->fresh()->status);
        $this->assertSame('paused', $room->listings()->first()->status);
        $this->expectException(ValidationException::class);
        $service->act($u, $b, 'activate');
    }

    public function test_iot_dedup_late_assignment_and_subscription_cutoff(): void
    {
        $d = Device::first();
        $old = $d->assignments()->first()->room_id;
        $time = now()->subMinute();
        $d->update(['last_seen_at' => null]);
        $data = ['event_id' => 'E1', 'event_time' => $time->toIso8601String(), 'schema_version' => 1, 'config_version' => 1, 'risk' => 'danger', 'health' => 'healthy', 'reason' => 'Nồng độ cao'];
        $service = app(IoTService::class);
        $service->ingest($d, $data);
        $service->ingest($d, $data);
        $this->assertSame(1, SafetyEvent::count());
        $this->assertEquals($old, SafetyEvent::first()->room_id);
        $this->assertSame('danger', $d->fresh()->risk);
        $service->ingest($d, array_replace($data, ['event_id' => 'E2', 'event_time' => $time->copy()->subSecond()->toIso8601String(), 'risk' => 'normal', 'health' => 'sensor_fault']));
        $this->assertSame('danger', $d->fresh()->risk);
        $this->assertSame('unknown', SafetyEvent::where('event_id', 'E2')->first()->risk);
        Workspace::whereKey($d->workspace_id)->update(['status' => 'suspended', 'expires_at' => now()->subDays(8)]);
        try {
            $service->ingest($d, array_replace($data, ['event_id' => 'EXPIRED']));
            $this->fail('Cloud IoT phải bị khóa sau hết hạn.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame(2, SafetyEvent::count());
    }

    public function test_analytics_can_be_disabled(): void
    {
        config(['features.analytics' => false]);
        $this->actingAs($this->user('owner'))->get('/ui/analytics-overview?context=workspace')->assertNotFound();
        $this->get('/ui/properties?context=workspace')->assertOk();
    }

    public function test_auth_uses_credentials_not_profile_configuration(): void
    {
        config(['ui_access.preview_profile' => 'landlord', 'ui_access.allow_query_profile_switch' => true]);
        $this->get('/ui/properties?context=workspace&_profile=landlord')->assertForbidden();
        $this->post('/dang-nhap', ['email' => 'owner@trohub.test', 'password' => 'Abcd@1234'])->assertRedirect();
        $this->assertAuthenticatedAs($this->user('owner'));
    }
}
