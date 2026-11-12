<?php

namespace App\Modules\DataPlatform\Adapters;

use App\Modules\DataPlatform\Contracts\ProjectionStore;
use Illuminate\Support\Facades\DB;

final class RelationalProjection implements ProjectionStore
{
    public function __construct(private string $store) {}

    public function put(array $event): void
    {
        DB::table('projection_records')->updateOrInsert(['store' => $this->store, 'event_id' => $event['event_id']], ['workspace_id' => $event['workspace_id'], 'event_time' => $event['event_time'], 'payload' => json_encode($event, JSON_THROW_ON_ERROR)]);
    }

    public function history(int $workspaceId, string $from, string $until, int $limit = 100): array
    {
        return DB::table('projection_records')->where('store', $this->store)->where('workspace_id', $workspaceId)->whereBetween('event_time', [$from, $until])->orderByDesc('event_time')->limit(min($limit, 1000))->get()->map(fn ($r) => json_decode($r->payload, true, 512, JSON_THROW_ON_ERROR))->all();
    }

    public function purge(int $workspaceId): void
    {
        DB::table('projection_records')->where('store', $this->store)->where('workspace_id', $workspaceId)->delete();
    }
}
