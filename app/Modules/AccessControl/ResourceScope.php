<?php

namespace App\Modules\AccessControl;

use App\Modules\Property\Models\Room;
use App\Modules\Workspace\Models\Workspace;

final class ResourceScope
{
    public static function fits(?array $properties, ?array $rooms, ?int $property, ?int $room): bool
    {
        return ($properties === null || $property !== null && in_array($property, $properties, true))
            && ($rooms === null || $room !== null && in_array($room, $rooms, true));
    }

    public static function target(Workspace $workspace, ?int $property, ?int $room): array
    {
        if ($room) {
            $record = Room::whereHas('property', fn ($p) => $p->where('workspace_id', $workspace->id))->findOrFail($room);
            abort_unless(! $property || $record->property_id === $property, 403);
            $property = $record->property_id;
        }
        if ($property) abort_unless($workspace->properties()->whereKey($property)->exists(), 403);
        return [$property, $room];
    }
}
