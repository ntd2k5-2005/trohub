<?php

namespace App\Modules\DataPlatform;

use App\Modules\DataPlatform\Contracts\HotCache;
use App\Modules\DataPlatform\Models\AuditEntry;
use App\Modules\DataPlatform\Models\DomainEvent;
use App\Modules\IoT\Models\SafetyEvent;
use App\Modules\Listing\Models\Listing;
use App\Modules\Notification\Models\UserNotification;
use App\Modules\Roommate\Models\RoommateNeed;
use App\Modules\Roommate\Models\RoommateRequest;
use App\Modules\Subscription\PropertyCloud;
use App\Modules\Workspace\Models\Workspace;
use App\Support\SqlTransactions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class Journal
{
    public function record(?int $actor, string $action, Model $resource, ?int $workspace = null, array $changes = []): void
    {
        SqlTransactions::run(fn () => $this->persist($actor, $action, $resource, $workspace, $changes));
    }

    private function persist(?int $actor, string $action, Model $resource, ?int $workspace, array $changes): void
    {
        AuditEntry::create(['actor_id' => $actor, 'workspace_id' => $workspace, 'action' => $action, 'resource_type' => $resource->getMorphClass(), 'resource_id' => $resource->id, 'changes' => $changes]);
        $payload = ['resource_id' => $resource->id];
        if ($workspace && app(PropertyCloud::class)->active($workspace)) {
            $payload['cloud_entitlement'] = app(PropertyCloud::class)->snapshot($workspace);
        }
        if ($resource instanceof RoommateNeed || $resource instanceof RoommateRequest) {
            $need = $resource instanceof RoommateNeed ? $resource->fresh() : RoommateNeed::findOrFail($resource->roommate_need_id);
            $payload['roommate'] = $need->only(['id', 'user_id', 'district', 'budget', 'status', 'expires_at']);
        }
        if ($resource instanceof Listing) {
            $payload['listing'] = $resource->only(['id', 'room_id', 'title', 'description', 'status']);
        }
        if ($resource instanceof SafetyEvent) {
            $payload['telemetry'] = $resource->only(['device_id', 'room_id', 'risk', 'health', 'config_version', 'payload']);
        }
        DomainEvent::create(['event_id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'source' => $resource instanceof SafetyEvent ? 'iot' : 'business', 'event_type' => $action, 'schema_version' => 1,
            'event_time' => now(), 'ingest_time' => now(), 'provenance' => $workspace ? Workspace::findOrFail($workspace)->provenance : 'production',
            'payload' => $payload]);
    }

    public function notify(int $user, string $title, string $body, int $priority = 0, ?int $workspace = null): void
    {
        UserNotification::create(['user_id' => $user, 'title' => $title, 'body' => $body, 'priority' => $priority] + app(PropertyCloud::class)->noticeAttributes($workspace));
        app(HotCache::class)->forget('notifications:'.$user);
    }
}
