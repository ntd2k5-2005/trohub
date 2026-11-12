<?php

namespace App\Modules\Property;

use App\Contracts\Authorization;
use App\Modules\Listing\ListingService;
use App\Modules\Listing\Models\Listing;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Support\ScopedRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class HousingMediaController
{
    public function show(Request $r, string $media)
    {
        $photo = DB::table('housing_media')->where('id', $media)->first();
        abort_unless($photo, 404);
        $record = match ($photo->resource_type) {
            'property' => Property::findOrFail($photo->resource_id), 'room' => Room::findOrFail($photo->resource_id), 'listing' => Listing::findOrFail($photo->resource_id), default => abort(404)
        };
        $property = $record instanceof Property ? $record : ($record instanceof Room ? $record->property : $record->room->property);
        $room = $record instanceof Room ? $record : ($record instanceof Listing ? $record->room : null);
        $available = app(ListingService::class)->available()->when($record instanceof Listing, fn ($q) => $q->whereKey($record->id))->when($room, fn ($q) => $q->where('room_id', $room->id))->whereHas('room', fn ($q) => $q->where('property_id', $property->id))->exists();
        if (! $available) {
            $user = $r->user();
            abort_if($user && $user->status !== 'active', 404);
            $access = app(Authorization::class);
            $allowed = $user && collect(['property.view', 'room.update', 'listing.view', 'listing.update'])->contains(fn ($p) => $access->allowed($user, $p, $property->workspace, $property->id, $room?->id));
            abort_unless($allowed || $user && $room && in_array($room->id, app(ScopedRepository::class)->currentRoomIds($user), true), 404);
        }
        abort_unless(str_starts_with($photo->path, 'housing-images/') && ! str_contains($photo->path, '..') && Storage::disk('local')->exists($photo->path), 404);

        return response()->file(Storage::disk('local')->path($photo->path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
