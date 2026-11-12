<?php

namespace App\Modules\Property;

use App\Contracts\Authorization as AccessService;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Models\RoomReservation;
use App\Modules\Workspace\Models\Workspace;
use App\Support\HousingImages;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PropertyService
{
    public function __construct(private AccessService $access, private Journal $journal) {}

    public function saveProperty(User $user, Workspace $workspace, array $data, ?int $id = null): Property
    {
        $this->access->authorize($user, $id ? 'property.update' : 'property.create', $workspace, $id);
        // A property-scoped employee cannot create a new property outside the assignment.
        if (! $id && $workspace->owner_id !== $user->id) {
            abort_unless($this->access->member($user, $workspace)?->property_ids === null, 403);
        }

        return app(\App\Modules\Workspace\OwnerQuota::class)->transaction($workspace->owner_id, function () use ($user, $workspace, $data, $id) {
            $workspace = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($user, $id ? 'property.update' : 'property.create', $workspace, $id);
            if (! $id) {
                app(\App\Modules\Workspace\WorkspaceLifecycle::class)->requireAdmission($workspace);
                app(\App\Modules\Workspace\OwnerQuota::class)->admit($workspace->owner_id, 'properties');
            }
            $files = $data['gallery'] ?? [];
            $remove = $data['remove_images'] ?? [];
            unset($data['gallery'], $data['remove_images']);
            $property = $id ? $workspace->properties()->findOrFail($id) : new Property(['workspace_id' => $workspace->id]);
            abort_if($property->retired_at, 409, 'Nhà đã ngừng khai thác. Hãy khôi phục trước khi chỉnh sửa.');
            $property->fill(HousingImages::normalize(HousingAmenities::normalize($data, 'property')))->save();
            app(HousingMedia::class)->save($property, $files, $remove);
            $this->journal->record($user->id, 'property.saved', $property, $workspace->id);

            return $property;
        });
    }

    public function saveRoom(User $user, Property $property, array $data, ?int $id = null): Room
    {
        $this->access->authorize($user, ($data['status'] ?? null) === 'inactive' ? 'room.deactivate' : ($id ? 'room.update' : 'room.create'), $property->workspace, $property->id, $id);

        return app(\App\Modules\Workspace\OwnerQuota::class)->transaction($property->workspace->owner_id, function () use ($user, $property, $data, $id) {
            $files = $data['gallery'] ?? [];
            $remove = $data['remove_images'] ?? [];
            unset($data['gallery'], $data['remove_images']);
            $workspace = Workspace::whereKey($property->workspace_id)->lockForUpdate()->firstOrFail();
            $property = Property::whereKey($property->id)->lockForUpdate()->firstOrFail();
            abort_unless($property->workspace_id === $workspace->id && ! $property->retired_at, 409);
            $this->access->authorize($user, ($data['status'] ?? null) === 'inactive' ? 'room.deactivate' : ($id ? 'room.update' : 'room.create'), $workspace, $property->id, $id);
            if (! $id) {
                app(\App\Modules\Workspace\WorkspaceLifecycle::class)->requireAdmission($workspace);
                app(\App\Modules\Workspace\OwnerQuota::class)->admit($workspace->owner_id, 'rooms');
            }
            $room = $id ? $property->rooms()->lockForUpdate()->findOrFail($id) : new Room(['property_id' => $property->id]);
            abort_if($room->retired_at, 409, 'Phòng đã ngừng khai thác. Hãy khôi phục trước khi chỉnh sửa.');
            if (isset($data['status']) && $room->exists && RoomReservation::where('active_room_id', $room->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'Phòng đang được giữ. Hãy xử lý giữ phòng trước khi đổi trạng thái.']);
            }
            if (isset($data['capacity']) && $room->exists && $room->rentals()->whereIn('status', ['active', 'move_out_pending'])->withCount(['occupants' => fn ($q) => $q->whereNull('ends_at')])->get()->sum('occupants_count') > $data['capacity']) {
                throw ValidationException::withMessages(['capacity' => 'Sức chứa không được ít hơn số người đang cư trú.']);
            }
            if (isset($data['status']) && ! in_array($data['status'], ['vacant', 'inactive'], true)) {
                throw ValidationException::withMessages(['status' => 'Trạng thái phòng được cập nhật từ hồ sơ thuê.']);
            }
            if (($data['status'] ?? null) === 'vacant' && $room->exists && $room->rentals()->whereIn('status', ['active', 'move_out_pending'])->exists()) {
                throw ValidationException::withMessages(['status' => 'Không thể chuyển phòng đang thuê thành phòng trống.']);
            }
            if (isset($data['name']) && $property->rooms()->where('name', $data['name'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Tên phòng đã tồn tại trong nhà trọ này.']);
            }
            if (($data['status'] ?? null) === 'inactive' && $room->exists && $room->rentals()->whereIn('status', ['active', 'move_out_pending'])->exists()) {
                throw ValidationException::withMessages(['status' => 'Phòng đang có người thuê, cần hoàn tất trả phòng trước.']);
            }
            if (array_key_exists('room_type', $data)) $data['room_type'] = HousingTypes::normalize($data['room_type']);
            $room->fill(HousingImages::normalize(HousingAmenities::normalize($data, 'room')))->save();
            if ($room->wasChanged(['rent', 'deposit', 'area', 'capacity', 'amenity_ids', 'custom_amenities', 'rules', 'available_from', 'room_type'])) {
                $room->listings()->where('status', 'published')->update(['status' => 'paused']);
            }
            app(HousingMedia::class)->save($room, $files, $remove);
            if ($room->status === 'inactive') {
                $room->listings()->where('status', 'published')->update(['status' => 'paused']);
            }
            $this->journal->record($user->id, 'room.saved', $room, $property->workspace_id);

            return $room;
        });
    }
}
