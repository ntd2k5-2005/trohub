<?php

namespace App\Modules\DataPlatform;

use App\Modules\DataPlatform\Adapters\CassandraProjection;
use App\Modules\DataPlatform\Adapters\MongoProjection;
use App\Modules\DataPlatform\Adapters\Neo4jProjection;
use App\Modules\DataPlatform\Adapters\RelationalProjection;
use App\Modules\DataPlatform\Contracts\ProjectionStore;
use App\Modules\DataPlatform\Models\DomainEvent;
use App\Modules\Subscription\PropertyCloud;
use App\Modules\Workspace\Models\Workspace;
use App\Support\SqlTransactions;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ProjectionManager
{
    private const TARGETS = ['mongodb', 'cassandra', 'neo4j'];

    public function store(string $name): ProjectionStore
    {
        if (app()->bound('projections.'.$name)) {
            return app('projections.'.$name);
        }
        if (! config('data_stores.'.$name.'.enabled')) {
            return new RelationalProjection($name);
        }

        return match ($name) {
            'mongodb' => new MongoProjection, 'cassandra' => new CassandraProjection, 'neo4j' => new Neo4jProjection,
            default => throw new \InvalidArgumentException('Unknown projection'),
        };
    }

    public function replay(int $workspaceId, string $from, string $until): int
    {
        $this->requireSchema();

        return SqlTransactions::run(function () use ($workspaceId, $from, $until) {
            if ($workspaceId !== 0) {
                $workspace = Workspace::whereKey($workspaceId)->lockForUpdate()->firstOrFail();
                app(PropertyCloud::class)->requireActive($workspace);
                abort_if($workspace->purged_at || $workspace->status === 'purging', 422, 'Không thể dựng lại dữ liệu đã kết thúc lưu trữ.');
            }
            abort_if(Carbon::parse($from)->gt(Carbon::parse($until)), 422, 'Khoảng thời gian không hợp lệ.');
            $events = DomainEvent::query()->when($workspaceId === 0, fn ($q) => $q->whereNull('workspace_id'), fn ($q) => $q->where('workspace_id', $workspaceId))->where(fn ($q) => $q->whereNull('last_error')->orWhere('last_error', '!=', 'RC12_SUPPRESSED'))->whereBetween('event_time', [$from, $until]);
            DB::table('projection_deliveries')->whereIn('domain_event_id', (clone $events)->select('id'))->update(['status' => 'pending', 'delivered_at' => null, 'last_error' => null, 'updated_at' => now()]);

            return $events->update(['projected_at' => null, 'last_error' => null]);
        });
    }

    public function drain(int $limit = 100, ?int $workspaceId = null): int
    {
        $this->requireSchema();
        $completed = 0;
        foreach (DomainEvent::whereNull('projected_at')->when($workspaceId !== null, fn ($query) => $workspaceId === 0 ? $query->whereNull('workspace_id') : $query->where('workspace_id', $workspaceId))->orderBy('id')->limit($limit)->get() as $event) {
            $completed += (int) $this->project($event);
        }

        return $completed;
    }

    /** At-least-once delivery; adapters upsert by event ID. Network calls hold no SQL locks. */
    public function project(DomainEvent $event): bool
    {
        $this->requireSchema();
        $frame = SqlTransactions::run(function () use ($event) {
            $current = $this->lockEvent($event);
            if ($current->projected_at) {
                return null;
            }
            if (! $this->allowed($current)) {
                $this->suppress($current);

                return null;
            }
            foreach (self::TARGETS as $target) {
                DB::table('projection_deliveries')->insertOrIgnore(['domain_event_id' => $current->id, 'target' => $target, 'driver' => $this->driver($target), 'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }

            return $current->only(['event_id', 'workspace_id', 'source', 'event_type', 'schema_version', 'provenance', 'payload']) + ['event_time' => $current->event_time->toIso8601String(), 'ingest_time' => $current->ingest_time->toIso8601String()];
        });
        if ($frame === null) {
            return false;
        }
        foreach (self::TARGETS as $target) {
            $delivery = DB::table('projection_deliveries')->where('domain_event_id', $event->id)->where('target', $target)->first();
            if ($delivery->status === 'delivered') {
                continue;
            }
            if (! $this->allowed($event->fresh())) {
                break;
            }
            $error = null;
            try {
                $this->store($target)->put($frame);
            } catch (Throwable $failure) {
                $error = $failure::class;
            }
            SqlTransactions::run(function () use ($event, $target, $error) {
                $current = $this->lockEvent($event);
                if (! $this->allowed($current)) {
                    $this->suppress($current);

                    return;
                }
                DB::table('projection_deliveries')->where('domain_event_id', $event->id)->where('target', $target)->where('status', '!=', 'delivered')->update(['status' => $error ? 'failed' : 'delivered', 'driver' => $this->driver($target), 'attempts' => DB::raw('attempts + 1'), 'last_error' => $error, 'delivered_at' => $error ? null : now(), 'updated_at' => now()]);
            });
        }

        return SqlTransactions::run(function () use ($event) {
            $current = $this->lockEvent($event);
            if (! $this->allowed($current)) {
                $this->suppress($current);

                return false;
            }
            $rows = DB::table('projection_deliveries')->where('domain_event_id', $current->id)->get();
            $complete = $rows->count() === count(self::TARGETS) && $rows->every(fn ($row) => $row->status === 'delivered');
            if ($complete) {
                if ($current->projected_at) {
                    return false;
                }
                $current->update(['projected_at' => now(), 'last_error' => null]);

                return true;
            }
            $current->update(['last_error' => $rows->first(fn ($row) => $row->last_error !== null)?->last_error, 'attempts' => $current->attempts + 1]);

            return false;
        });
    }

    private function lockEvent(DomainEvent $event): DomainEvent
    {
        if ($event->workspace_id) {
            Workspace::whereKey($event->workspace_id)->lockForUpdate()->first();
        }

        return DomainEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
    }

    private function allowed(DomainEvent $event): bool
    {
        return $event->last_error !== 'RC12_SUPPRESSED' && (! $event->workspace_id || app(PropertyCloud::class)->matches($event->workspace_id, $event->payload['cloud_entitlement'] ?? null));
    }

    private function suppress(DomainEvent $event): void
    {
        $event->update(['projected_at' => now(), 'last_error' => 'RC12_SUPPRESSED']);
        DB::table('projection_deliveries')->where('domain_event_id', $event->id)->update(['status' => 'suppressed', 'updated_at' => now()]);
    }

    private function driver(string $target): string
    {
        return app()->bound('projections.'.$target) ? 'boundary' : (config('data_stores.'.$target.'.enabled') ? 'native' : 'development');
    }

    private function requireSchema(): void
    {
        abort_unless(Schema::hasTable('projection_deliveries'), 503, 'Đồng bộ dữ liệu đang chờ cập nhật dữ liệu an toàn.');
    }
}
