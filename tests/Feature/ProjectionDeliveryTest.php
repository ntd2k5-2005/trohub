<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\DataPlatform\Contracts\ProjectionStore;
use App\Modules\DataPlatform\Models\DomainEvent;
use App\Modules\DataPlatform\ProjectionManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectionDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed(DatabaseSeeder::class);
        $event = DomainEvent::create(['event_id' => (string) Str::uuid(), 'source' => 'web', 'event_type' => 'search', 'schema_version' => 1, 'event_time' => now(), 'ingest_time' => now(), 'provenance' => 'production', 'payload' => ['district' => 'Thủ Đức']]);
        $stores = [];
        foreach (['mongodb', 'cassandra', 'neo4j'] as $target) {
            $store = new class implements ProjectionStore
            {
                public int $calls = 0;

                public array $levels = [];

                public bool $fail = false;

                public function put(array $event): void
                {
                    $this->calls++;
                    $this->levels[] = DB::transactionLevel();
                    if ($this->fail) {
                        throw new \RuntimeException('External boundary failed');
                    }
                }

                public function history(int $workspaceId, string $from, string $until, int $limit = 100): array
                {
                    return [];
                }

                public function purge(int $workspaceId): void {}
            };
            $this->app->instance('projections.'.$target, $store);
            $stores[$target] = $store;
        }

        return [$event, $stores];
    }

    public function test_one_failed_target_does_not_block_independent_targets_and_retry_does_not_repeat_successes(): void
    {
        [$event, $stores] = $this->fixture();
        $stores['mongodb']->fail = true;
        $manager = app(ProjectionManager::class);
        $this->assertFalse($manager->project($event));
        $this->assertSame(1, $stores['cassandra']->calls);
        $this->assertSame(1, $stores['neo4j']->calls);
        $this->assertNull($event->fresh()->projected_at);
        $stores['mongodb']->fail = false;
        $this->assertTrue($manager->project($event));
        $this->assertSame(2, $stores['mongodb']->calls);
        $this->assertSame(1, $stores['cassandra']->calls);
        $this->assertSame(1, $stores['neo4j']->calls);
        $this->assertNotNull($event->fresh()->projected_at);
        $this->assertFalse($manager->project($event));
    }

    public function test_adapter_network_boundary_does_not_hold_an_additional_sql_transaction(): void
    {
        [$event, $stores] = $this->fixture();
        $baseline = DB::transactionLevel();
        $this->assertTrue(app(ProjectionManager::class)->project($event));
        foreach ($stores as $store) {
            $this->assertSame([$baseline], $store->levels);
        }
    }

    public function test_shared_data_can_be_replayed_only_by_authorized_operator_and_retries_delivery(): void
    {
        [$event,$stores] = $this->fixture();
        $manager = app(ProjectionManager::class);
        $this->assertTrue($manager->project($event));
        $input = ['context' => 'system-admin', 'target_workspace_id' => 0, 'from' => today()->toDateString(), 'until' => now()->addMinute()->toDateTimeString()];
        $ordinary = User::where('email', 'user@trohub.test')->firstOrFail();
        $this->actingAs($ordinary)->post('/actions/data.replay', $input)->assertForbidden();
        $operator = User::where('email', 'system@trohub.test')->firstOrFail();
        $this->actingAs($operator)->post('/actions/data.replay', $input)->assertSessionHasNoErrors();
        $this->assertNull($event->fresh()->projected_at);
        $this->assertTrue($manager->project($event));
        foreach ($stores as $store) {
            $this->assertSame(2, $store->calls);
        }
    }

    public function test_mysql_json_key_order_does_not_invalidate_entitlement_but_tampering_does(): void
    {
        $this->seed(DatabaseSeeder::class);
        $workspace = \App\Modules\Workspace\Models\Workspace::where('status', 'active')->firstOrFail();
        $cloud = app(\App\Modules\Subscription\PropertyCloud::class);
        $stamp = $cloud->snapshot($workspace);
        ksort($stamp); // MySQL JSON storage can reorder object keys.
        $this->assertTrue($cloud->matches($workspace->id, $stamp));
        $stamp['owner_id']++;
        $this->assertFalse($cloud->matches($workspace->id, $stamp));
        unset($stamp['issued_at']);
        $this->assertFalse($cloud->matches($workspace->id, $stamp));
    }
}
