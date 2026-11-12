<?php

namespace App\Modules\Property;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Property\Models\{Property, Room};
use App\Modules\Rental\ObligationGuard;
use App\Modules\Workspace\{OwnerQuota, WorkspaceLifecycle};
use App\Modules\Workspace\Models\Workspace;

final class AssetLifecycle
{
    public function change(User $actor, Workspace $workspace, string $type, int $id, int $version, bool $restore, string $reason): void
    {
        abort_unless(in_array($type, ['property', 'room'], true) && trim($reason) !== '', 422);
        app(OwnerQuota::class)->transaction($workspace->owner_id, function () use ($actor, $workspace, $type, $id, $version, $restore, $reason) {
            $w = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            abort_unless($w->owner_id === $workspace->owner_id, 409);
            $property = $type === 'property' ? $w->properties()->lockForUpdate()->findOrFail($id) : $w->properties()->whereHas('rooms', fn ($q) => $q->whereKey($id))->lockForUpdate()->firstOrFail();
            $asset = $type === 'property' ? $property : $property->rooms()->lockForUpdate()->findOrFail($id);
            $permission = $type === 'property' ? 'property.update' : ($restore ? 'room.update' : 'room.deactivate');
            app(Authorization::class)->authorize($actor, $permission, $w, $property->id, $type === 'room' ? $asset->id : null);
            abort_unless($asset->version === $version && (bool) $asset->retired_at === $restore, 409, 'Tài nguyên đã thay đổi. Vui lòng tải lại.');
            $rooms = $type === 'property' ? $property->rooms()->lockForUpdate()->get() : collect([$asset]);
            if ($restore) {
                app(WorkspaceLifecycle::class)->requireAdmission($w);
                abort_if($type === 'room' && $property->retired_at, 409, 'Hãy khôi phục nhà trọ trước khi mở lại phòng.');
                app(OwnerQuota::class)->admit($w->owner_id, $type === 'property' ? 'properties' : 'rooms');
                $asset->update(['retired_at' => null, 'retirement_reason' => null] + ($type === 'room' ? ['status' => 'vacant'] : []));
            } else {
                app(ObligationGuard::class)->settled($rooms->pluck('id')->all());
                foreach ($rooms as $room) {
                    app(Authorization::class)->authorize($actor, 'room.deactivate', $w, $property->id, $room->id);
                    if (! $room->retired_at) $room->update(['retired_at' => now(), 'retirement_reason' => $reason, 'status' => 'inactive']);
                    $room->listings()->where('status', 'published')->update(['status' => 'paused']);
                }
                if ($type === 'property') $property->update(['retired_at' => now(), 'retirement_reason' => $reason]);
            }
            app(Journal::class)->record($actor->id, $restore ? 'asset.restored' : 'asset.retired', $asset->fresh(), $w->id, ['reason' => $reason, 'resource_type' => $type, 'rooms' => $rooms->pluck('id')->all(), 'money_changed' => false]);
            app(Authorization::class)->authorize($actor, $permission, $w, $property->id, $type === 'room' ? $asset->id : null);
        });
    }
}
