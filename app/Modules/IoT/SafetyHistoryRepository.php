<?php

namespace App\Modules\IoT;

use App\Modules\DataPlatform\ProjectionManager;
use App\Modules\IoT\Models\SafetyEvent;
use Throwable;

/** Enriches already-authorized SQL history rows; never determines visibility or safety decisions. */
final class SafetyHistoryRepository
{
    private array $months = [];

    public function reading(SafetyEvent $event): array
    {
        $fallback = ['payload' => $event->payload, 'projected' => false];
        if (! config('data_stores.cassandra.enabled')) {
            return $fallback;
        }
        $workspace = $event->room->property->workspace_id;
        $month = ($event->ingest_time ?? $event->created_at)->copy()->startOfMonth();
        $key = $workspace.':'.$month->format('Y-m');
        if (! array_key_exists($key, $this->months)) {
            try {
                $this->months[$key] = app(ProjectionManager::class)->store('cassandra')->history($workspace, $month->toIso8601String(), $month->copy()->addMonth()->toIso8601String(), 1000);
            } catch (Throwable) {
                $this->months[$key] = [];
            }
        }
        foreach ($this->months[$key] as $row) {
            $payload = $row['payload'] ?? [];
            $telemetry = $payload['telemetry'] ?? [];
            if ((int) ($row['workspace_id'] ?? 0) === $workspace && (int) ($payload['resource_id'] ?? 0) === $event->id
                && (int) ($telemetry['room_id'] ?? 0) === $event->room_id && (int) ($telemetry['device_id'] ?? 0) === $event->device_id && is_array($telemetry['payload'] ?? null)) {
                return ['payload' => $telemetry['payload'], 'projected' => true];
            }
        }

        return $fallback;
    }
}
