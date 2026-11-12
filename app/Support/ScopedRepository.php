<?php

namespace App\Support;

use App\Contracts\Authorization as AccessService;
use App\Models\User;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Models\Rental;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

final class ScopedRepository
{
    public function __construct(private AccessService $access) {}

    public function rooms(User $user, ?Workspace $workspace, string $permission = 'property.view'): Builder
    {
        if ($workspace) {
            return Room::whereIn('id', $this->access->roomIds($user, $workspace, $permission));
        }

        return Room::whereIn('id', $this->rentals($user)->whereIn('status', ['active', 'move_out_pending'])->select('room_id'))
            ->whereHas('property.workspace', fn ($w) => app(\App\Modules\Subscription\PropertyCloud::class)->eligible($w));
    }

    public function rentals(User $user, ?Workspace $workspace = null, string $permission = 'rental.view'): Builder
    {
        if ($workspace) {
            return Rental::whereIn('room_id', $this->rooms($user, $workspace, $permission)->select('id'));
        }

        abort_unless($this->access->personal($user, $permission), 403);

        $cloud = app(\App\Modules\Subscription\PropertyCloud::class);
        return Rental::where(fn ($q) => $q->where('tenant_id', $user->id)->orWhereHas('occupants', fn ($q) => $q->where('user_id', $user->id)))
            ->where(fn ($q) => $q->whereHas('room.property.workspace', fn ($w) => $cloud->eligible($w))
                ->orWhere(fn ($history) => $history->where('tenant_id', $user->id)->where(fn ($confirmed) => $confirmed->whereNotNull('confirmed_at')
                    ->orWhereHas('invoices', fn ($i) => $i->whereNotNull('issued_at'))
                    ->orWhereHas('deposits', fn ($d) => $d->whereNotNull('received_at'))
                    ->orWhereExists(fn ($tx) => $tx->selectRaw('1')->from('payment_request_transactions')->whereColumn('rental_id', 'rentals.id')->whereNotNull('verified_at')))));
    }

    public function currentRoomIds(User $user): array
    {
        return Rental::whereIn('status', ['active', 'move_out_pending'])->where('starts_at', '<=', today())
            ->whereHas('room.property.workspace', fn ($w) => app(\App\Modules\Subscription\PropertyCloud::class)->eligible($w))
            ->where(fn ($q) => $q->where('tenant_id', $user->id)->orWhereHas('occupants', fn ($q) => $q->where('user_id', $user->id)->where('starts_at', '<=', now())->whereNull('ends_at')))
            ->pluck('room_id')->all();
    }

    public function managedRoom(User $user, int $id, string $permission): Room
    {
        $room = Room::with('property.workspace')->findOrFail($id);
        abort_if(($room->retired_at || $room->property->retired_at) && ! str_ends_with($permission, '.view') && ! str_ends_with($permission, '.export'), 409, 'Tài nguyên đã ngừng khai thác.');
        $this->access->authorize($user, $permission, $room->property->workspace, $room->property_id, $room->id);

        return $room;
    }
}
