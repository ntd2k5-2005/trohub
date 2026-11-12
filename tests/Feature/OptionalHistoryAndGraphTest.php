<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\DataPlatform\Adapters\Neo4jProjection;
use App\Modules\DataPlatform\Contracts\ProjectionStore;
use App\Modules\DataPlatform\Models\DomainEvent;
use App\Modules\IoT\IoTService;
use App\Modules\IoT\Models\Device;
use App\Modules\Rental\Models\Rental;
use App\Modules\Roommate\Models\RoommateNeed;
use App\Modules\Roommate\Models\RoommateRequest;
use App\Modules\Roommate\RecommendationRepository;
use App\Modules\Roommate\RoommateService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptionalHistoryAndGraphTest extends TestCase
{
    use RefreshDatabase;

    private function historyFixture(bool $offline = false, bool $wrongRoom = false): array
    {
        $this->seed(DatabaseSeeder::class);
        $rental = Rental::where('status', 'active')->firstOrFail();
        $room = $rental->room;
        $service = app(IoTService::class);
        $service->register($room->property->workspace->owner, $room->id, 'Cảm biến kiểm thử', 'HISTORY-TEST');
        $device = Device::where('serial', 'HISTORY-TEST')->firstOrFail();
        $event = $service->ingest($device, ['event_id' => (string) Str::uuid(), 'event_time' => now()->toIso8601String(), 'config_version' => 1, 'risk' => 'warning', 'health' => 'healthy', 'reason' => 'Sự kiện thử cảm biến', 'payload' => ['smoke_ppm' => 180, 'temperature_c' => 28]]);
        $store = new class implements ProjectionStore
        {
            public array $rows = [];

            public bool $offline = false;

            public function put(array $event): void {}

            public function history(int $workspaceId, string $from, string $until, int $limit = 100): array
            {
                if ($this->offline) {
                    throw new \RuntimeException('Optional boundary unavailable');
                }

                return $this->rows;
            }

            public function purge(int $workspaceId): void {}
        };
        $store->offline = $offline;
        $store->rows = [['workspace_id' => $room->property->workspace_id, 'payload' => ['resource_id' => $event->id, 'telemetry' => ['room_id' => $wrongRoom ? $room->id + 9999 : $room->id, 'device_id' => $device->id, 'payload' => ['smoke_ppm' => 321, 'temperature_c' => 28]]]]];
        $this->app->instance('projections.cassandra', $store);
        config(['data_stores.cassandra.enabled' => true]);

        return [$rental->tenant, $event];
    }

    public function test_projected_history_enriches_authorized_room_and_does_not_leak_to_other_account(): void
    {
        [$tenant] = $this->historyFixture();
        $this->actingAs($tenant)->get('/ui/room-safety-history?context=personal')->assertOk()->assertHeader('X-TroHub-History-Engine', 'cassandra')->assertSee('321 ppm');
        $other = User::where('email', 'user@trohub.test')->firstOrFail();
        $this->actingAs($other)->get('/ui/room-safety-history?context=personal')->assertForbidden()->assertDontSee('321 ppm')->assertDontSee('Sự kiện thử cảm biến');
    }

    public function test_history_rejects_projection_misattributed_to_another_room(): void
    {
        [$tenant] = $this->historyFixture(wrongRoom: true);
        $this->actingAs($tenant)->get('/ui/room-safety-history?context=personal')->assertOk()->assertSee('180 ppm')->assertDontSee('321 ppm');
    }

    public function test_history_outage_retains_authoritative_sql_history(): void
    {
        [$tenant] = $this->historyFixture(offline: true);
        $this->actingAs($tenant)->get('/ui/room-safety-history?context=personal')->assertOk()->assertSee('180 ppm')->assertSee('Sự kiện thử cảm biến');
    }

    public function test_roommate_event_owner_is_projected_as_a_real_graph_relationship(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('email', 'user@trohub.test')->firstOrFail();
        app(RoommateService::class)->execute($owner, 'roommate.save', 0, ['district' => 'Thủ Đức', 'budget' => '3000000', 'move_in' => today()->addWeek()->toDateString(), 'lifestyle' => 'Gọn gàng, thích đọc sách', 'expires_at' => today()->addMonth()->toDateString(), 'mode' => 'search_together']);
        $need = RoommateNeed::where('user_id', $owner->id)->latest()->firstOrFail();
        $event = DomainEvent::where('event_type', 'roommate.save')->latest()->firstOrFail();
        $this->assertSame($owner->id, $event->payload['roommate']['user_id']);
        $this->assertIsInt($event->payload['roommate']['budget']);
        $this->assertSame('open', $event->payload['roommate']['status']);
        config(['data_stores.neo4j.http_uri' => 'http://graph.test']);
        Http::preventStrayRequests();
        Http::fake(['graph.test/*' => Http::response(['results' => [['data' => []]], 'errors' => []])]);
        (new Neo4jProjection)->put($event->toArray() + ['event_time' => now()->toIso8601String()]);
        Http::assertSent(fn ($request) => str_contains($request['statements'][0]['statement'], 'HAS_NEED') && $request['statements'][0]['parameters']['user'] === $owner->id && $request['statements'][0]['parameters']['id'] === $need->id);
    }

    public function test_stale_graph_ids_are_rechecked_against_current_district_and_budget(): void
    {
        $this->seed(DatabaseSeeder::class);
        $me = RoommateNeed::create(['user_id' => User::where('email', 'user@trohub.test')->value('id'), 'district' => 'Thủ Đức', 'budget' => 3000000, 'move_in' => today()->addWeek()->toDateString(), 'lifestyle' => 'Tìm người ở cùng khu vực', 'status' => 'open', 'expires_at' => today()->addMonth()]);
        $wrong = RoommateNeed::create(['user_id' => User::factory()->create()->id, 'district' => 'Khu vực khác sau chỉnh sửa', 'budget' => 100000000, 'move_in' => today()->addWeek()->toDateString(), 'lifestyle' => 'Nhu cầu thử kiểm tra chỉ mục cũ', 'status' => 'open', 'expires_at' => today()->addMonth()]);
        config(['data_stores.neo4j.enabled' => true, 'data_stores.neo4j.http_uri' => 'http://graph.test']);
        Http::fake(['graph.test/*' => Http::response(['results' => [['data' => [['row' => [$wrong->id]]]]], 'errors' => []])]);
        $result = app(RecommendationRepository::class)->candidates($me);
        $this->assertTrue($result['available']);
        $this->assertSame([], $result['ids']);
    }

    public function test_roommate_response_emits_current_need_status_for_graph_synchronization(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('email', 'user@trohub.test')->firstOrFail();
        $partner = User::where('email', 'tenant@trohub.test')->firstOrFail();
        $service = app(RoommateService::class);
        $service->execute($owner, 'roommate.save', 0, ['district' => 'Thủ Đức', 'budget' => '3000000', 'move_in' => today()->addWeek()->toDateString(), 'lifestyle' => 'Gọn gàng, yên tĩnh', 'expires_at' => today()->addMonth()->toDateString(), 'mode' => 'search_together']);
        $need = RoommateNeed::where('user_id', $owner->id)->latest()->firstOrFail();
        $service->execute($partner, 'roommate.connect', $need->id, []);
        $request = RoommateRequest::where('roommate_need_id', $need->id)->firstOrFail();
        $service->execute($owner, 'roommate.respond', $request->id, ['status' => 'accepted']);
        $event = DomainEvent::where('event_type', 'roommate.respond')->latest()->firstOrFail();
        $this->assertSame('matched', data_get($event->payload, 'roommate.status'));
        $this->assertSame($need->id, data_get($event->payload, 'roommate.id'));
    }
}
