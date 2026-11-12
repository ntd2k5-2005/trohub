<?php

namespace App\Modules\DataPlatform;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Models\DomainEvent;
use Illuminate\Support\Carbon;
use Throwable;

/** SQL controls visibility; Cassandra supplies the event history when available. */
final class BusinessHistoryRepository
{
    public function read(User $actor, int $workspace, Carbon $from, Carbon $until): array
    {
        abort_unless(app(Authorization::class)->global($actor, 'data.view'), 403);
        abort_unless($workspace >= 0 && $until->gte($from) && $from->diffInDays($until) <= 31, 422, 'Chọn khoảng thời gian tối đa 31 ngày.');
        $rows = DomainEvent::whereIn('source', ['business', 'web'])->when($workspace === 0, fn ($query) => $query->whereNull('workspace_id'), fn ($query) => $query->where('workspace_id', $workspace))->whereBetween('event_time', [$from, $until])->latest('event_time')->latest('id')->limit(100)->get();
        $native = [];
        if (config('data_stores.cassandra.enabled')) {
            try {
                $native = collect(app(ProjectionManager::class)->store('cassandra')->history($workspace, $from->toIso8601String(), $until->toIso8601String(), 1000))->keyBy('event_id')->all();
            } catch (Throwable) {
                // Unavailable derived storage cannot make the administration page fail.
            }
        }
        $matched = 0;
        $events = $rows->map(function ($event) use ($native, $workspace, &$matched) {
            $candidate = $native[$event->event_id] ?? null;
            $valid = $candidate && ($candidate['source'] ?? null) === $event->source && (int) ($candidate['workspace_id'] ?? 0) === $workspace
                && ($candidate['event_type'] ?? null) === $event->event_type && ($candidate['payload'] ?? null) == $event->payload;
            $matched += (int) $valid;
            return ['id' => $event->id, 'event_id' => $event->event_id, 'type' => $valid ? $candidate['event_type'] : $event->event_type, 'time' => $event->event_time, 'native' => (bool) $valid];
        })->all();
        return ['events' => $events, 'native_count' => $matched, 'engine' => $matched ? ($matched === count($events) ? 'cassandra' : 'mixed') : 'mysql'];
    }
}
