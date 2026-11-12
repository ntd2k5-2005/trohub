<?php

namespace App\Modules\DataPlatform;

use App\Modules\DataPlatform\Models\DomainEvent;
use App\Modules\DataPlatform\Models\QuarantinedEvent;
use App\Modules\Workspace\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

final class EventIngestion
{
    public function ingest(array $data): string
    {
        $validator = Validator::make($data, ['event_id' => 'required|uuid', 'workspace_id' => 'nullable|integer|exists:workspaces,id', 'source' => 'required|in:web,business,iot,external,synthetic,trial', 'event_type' => 'required|string|max:100', 'schema_version' => 'required|integer|in:1', 'event_time' => 'required|date', 'provenance' => 'required|in:production,trial,synthetic,external', 'payload' => 'required|array']);
        if ($validator->fails()) {
            return $this->reject('schema', $data);
        }
        if ($this->containsPrivateData($data['payload'])) {
            return $this->reject('privacy', $data);
        }
        $w = isset($data['workspace_id']) ? Workspace::find($data['workspace_id']) : null;
        if ($w?->purged_at || $w?->status === 'purging') {
            return 'stale_purged';
        }
        if ($w && $data['provenance'] !== $w->provenance) {
            return $this->reject('provenance', $data);
        }
        $time = Carbon::parse($data['event_time']);
        if ($time->gt(now()->addMinute())) {
            return $this->reject('future_event', $data);
        }
        if ($w) {
            $cloud = app(\App\Modules\Subscription\PropertyCloud::class);
            if (! $cloud->currentOperation($w, $time)) return 'cloud_unavailable';
            // Never trust a caller-supplied authority/epoch stamp.
            $data['payload']['cloud_entitlement'] = $cloud->snapshot($w);
        }
        $event = DomainEvent::firstOrCreate(['event_id' => $data['event_id']], collect($data)->only(['workspace_id', 'source', 'event_type', 'schema_version', 'event_time', 'provenance', 'payload'])->all() + ['ingest_time' => now(), 'late' => $time->lt(now()->subSeconds(config('business.watermark_seconds')))]);

        return $event->wasRecentlyCreated ? 'accepted' : 'duplicate';
    }

    private function containsPrivateData(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/email|phone|password|token|address|full.?name/i', (string) $key) || (is_array($value) && $this->containsPrivateData($value))) {
                return true;
            }
        }

        return false;
    }

    private function reject(string $reason, array $data): string
    {
        // Quarantine metadata only: rejected payloads must not persist prohibited PII.
        QuarantinedEvent::create(['reason' => $reason, 'payload' => ['event_id' => is_string($data['event_id'] ?? null) ? substr($data['event_id'], 0, 100) : null]]);

        return 'quarantined';
    }
}
