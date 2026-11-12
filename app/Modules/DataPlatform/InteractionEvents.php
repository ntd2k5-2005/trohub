<?php

namespace App\Modules\DataPlatform;

use App\Modules\DataPlatform\Models\DomainEvent;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Str;

final class InteractionEvents
{
    public function record(string $type, Workspace $workspace, int $resourceId): void
    {
        $stamp = app(\App\Modules\Subscription\PropertyCloud::class)->snapshot($workspace);
        DomainEvent::create(['event_id' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'source' => 'web', 'event_type' => $type, 'schema_version' => 1, 'event_time' => now(), 'ingest_time' => now(), 'provenance' => $workspace->provenance, 'payload' => ['resource_id' => $resourceId, 'cloud_entitlement' => $stamp]]);
    }
}
