<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\DataPlatform\BusinessHistoryRepository;
use App\Modules\DataPlatform\Contracts\ProjectionStore;
use App\Modules\DataPlatform\Journal;
use App\Modules\DataPlatform\Models\DomainEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->freezeTime(); $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'supreme@trohub.test')->firstOrFail();
        app(Journal::class)->record($admin->id, 'listing.updated', $admin);
        $event = DomainEvent::latest('id')->firstOrFail();
        return [$admin, $event];
    }

    private function boundary(array $events, bool $unavailable = false): void
    {
        config(['data_stores.cassandra.enabled' => true]);
        app()->instance('projections.cassandra', new class($events, $unavailable) implements ProjectionStore {
            public function __construct(private array $events, private bool $unavailable) {}
            public function put(array $event): void {}
            public function purge(int $workspaceId): void {}
            public function history(int $workspaceId, string $from, string $until, int $limit = 100): array
            {
                if ($this->unavailable) throw new \RuntimeException('Boundary unavailable');
                return $this->events;
            }
        });
    }

    public function test_native_history_is_matched_to_sql_and_shown_in_existing_administration_screen(): void
    {
        [$admin, $event] = $this->fixture();
        $frame = $event->only(['event_id', 'workspace_id', 'source', 'event_type', 'payload']);
        $this->boundary([$frame]);
        $history = app(BusinessHistoryRepository::class)->read($admin, 0, today(), today()->endOfDay());
        $this->assertSame(1, $history['native_count']);
        $this->actingAs($admin)->get('/ui/data-platform?context=system-admin&section=activity&history_workspace_id=0')->assertOk()->assertHeader('X-TroHub-History-Engine', 'mixed')->assertSee('Cập nhật tin cho thuê')->assertDontSee('listing.updated');
    }

    public function test_forged_projection_and_unknown_event_do_not_override_sql(): void
    {
        [$admin, $event] = $this->fixture();
        $frame = $event->only(['event_id', 'workspace_id', 'source', 'event_type', 'payload']);
        $frame['payload'] = ['resource_id' => 999999];
        $unknown = $frame; $unknown['event_id'] = 'unknown';
        $this->boundary([$frame, $unknown]);
        $history = app(BusinessHistoryRepository::class)->read($admin, 0, today(), today()->endOfDay());
        $this->assertSame('mysql', $history['engine']); $this->assertSame(0, $history['native_count']); $this->assertContains($event->event_id, array_column($history['events'], 'event_id')); $this->assertNotContains('unknown', array_column($history['events'], 'event_id'));
    }

    public function test_cassandra_outage_falls_back_without_changing_source_events(): void
    {
        [$admin, $event] = $this->fixture(); $before = $event->toJson();
        $this->boundary([], true);
        $this->actingAs($admin)->get('/ui/data-platform?context=system-admin&section=activity&history_workspace_id=0')->assertOk()->assertHeader('X-TroHub-History-Engine', 'mysql')->assertSee('Hồ sơ chính');
        $this->assertSame($before, $event->fresh()->toJson());
    }

    public function test_history_requires_global_permission_and_bounded_dates(): void
    {
        [$admin] = $this->fixture();
        $ordinary = User::where('email', 'user@trohub.test')->firstOrFail();
        $this->actingAs($ordinary)->get('/ui/data-platform?context=system-admin&section=activity')->assertForbidden();
        $this->actingAs($admin)->get('/ui/data-platform?context=system-admin&section=activity&history_from=2026-01-01&history_until=2026-12-31')->assertStatus(422);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(BusinessHistoryRepository::class)->read($ordinary, 0, today(), today()->endOfDay());
    }
}
