<?php

namespace App\Modules\Analytics;

use App\Modules\DataPlatform\Models\DomainEvent;
use App\Modules\Workspace\Models\Workspace;
use Carbon\Carbon;

final class AnalyticsService
{
    public function aggregate(int $workspaceId, string $from, string $to, string $provenance): array
    {
        abort_unless(config('features.analytics'), 404);
        $events = DomainEvent::where('workspace_id', $workspaceId)->where('provenance', $provenance)->whereBetween('event_time', [$from, $to])->orderBy('event_time')->get();

        return ['definition_version' => 1, 'workspace_id' => $workspaceId, 'from' => $from, 'to' => $to, 'provenance' => $provenance, 'generated_at' => now()->toIso8601String(), 'counts' => $events->countBy('event_type')->all(), 'dataset_version' => hash('sha256', $events->pluck('event_id')->sort()->implode('|'))];
    }

    public function forecast(int $workspaceId, string $cutoff, string $horizon): array
    {
        abort_unless(config('features.analytics'), 404);
        if (Carbon::parse($cutoff)->gte(Carbon::parse($horizon))) {
            return ['status' => 'invalid_time_split'];
        }
        $workspace = Workspace::findOrFail($workspaceId);
        $events = DomainEvent::where('workspace_id', $workspaceId)->where('provenance', $workspace->provenance)->whereIn('event_type', ['listing.viewed', 'connection.created'])->where('event_time', '<', $cutoff)->orderBy('event_time')->get();
        if ($events->isEmpty()) {
            return ['status' => 'insufficient_data', 'value' => null];
        }
        $start = $events->first()->event_time->copy()->startOfDay();
        $end = Carbon::parse($cutoff)->startOfDay();
        $days = (int) $start->diffInDays($end);
        if ($days < config('business.forecast_min_days', 14) || $events->count() < config('business.forecast_min_events', 30)) {
            return ['status' => 'insufficient_data', 'value' => null];
        }
        $observed = $events->countBy(fn ($e) => $e->event_time->format('Y-m-d'));
        $series = [];
        for ($day = $start->copy(); $day->lt($end); $day->addDay()) {
            $series[] = $observed[$day->format('Y-m-d')] ?? 0;
        }
        $train = array_slice($series, 0, -7);
        $test = array_slice($series, -7);
        $baseline = array_sum($train) / count($train);
        $mae = array_sum(array_map(fn ($v) => abs($v - $baseline), $test)) / count($test);
        $result = ['status' => 'ready', 'value' => round(array_sum($series) / count($series), 2), 'unit' => 'daily_interest_events', 'model_version' => 'historical-mean-v1', 'feature_version' => 1, 'dataset_version' => hash('sha256', $events->pluck('event_id')->implode('|')), 'training_start' => $start->toDateString(), 'cutoff' => $cutoff, 'horizon' => $horizon, 'validation_mae' => round($mae, 2), 'provenance' => $workspace->provenance];

        return $result;
    }
}
