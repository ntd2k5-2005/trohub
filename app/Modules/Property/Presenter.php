<?php

namespace App\Modules\Property;

use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Support\HousingImages;
use App\Support\ProductPresenter;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $this->request->validate(['property_id' => 'nullable|integer|min:1', 'tab' => 'nullable|in:rooms,houses']);
        $this->vm['housing'] = $slug === 'room-detail' ? 'room-detail' : 'management';
        $this->vm['tabs'] = [$this->link('Phòng', 'properties'), $this->link('Nhà trọ', 'properties', ['tab' => 'houses'])];
        $props = $this->propertyOptions('property.update');
        $createProps = $this->workspace->properties()->get()->filter(fn ($p) => $this->access->allowed($this->user, 'room.create', $this->workspace, $p->id))->pluck('name', 'id')->all();
        $viewProps = $this->propertyOptions('property.view');
        $this->vm['property_options'] = $viewProps;
        $this->vm['status_options'] = ['vacant' => 'Phòng trống', 'occupied' => 'Đang có người thuê', 'inactive' => 'Ngưng sử dụng'];
        $this->vm['stats'] = ['Nhà trọ' => count($viewProps), 'Tổng số phòng' => $this->rooms()->count(), 'Phòng trống' => $this->rooms()->where('status', 'vacant')->count(), 'Đang cho thuê' => $this->rooms()->where('status', 'occupied')->count()];
        if ($slug !== 'room-detail') {
            if ($this->access->allowed($this->user, 'property.create', $this->workspace)) {
                $this->vm['forms'][] = $this->form('Thêm nhà trọ', 'property.save', $this->propertyFields());
            }
            if ($createProps) {
                $this->vm['forms'][] = $this->form('Thêm phòng', 'room.save', $this->roomFields($createProps));
            }
        }
        if ($this->request->input('tab') === 'houses' && $slug !== 'room-detail') {
            foreach(\App\Modules\Property\Models\PropertyMove::where('source_workspace_id',$this->workspace->id)->latest()->get() as $move){
                $p=Property::find($move->property_id);if(!$p||!$this->access->allowed($this->user,'property.move',$p->workspace,$p->id))continue;
                $this->vm['records'][]=$this->card('Chuyển nhà: '.$p->name,'Đề nghị chuyển khu quản lý',['Khu nhận'=>\App\Modules\Workspace\Models\Workspace::find($move->target_workspace_id)->name,'Trạng thái'=>['pending'=>'Chờ xác nhận','blocked'=>'Cần xử lý điều kiện','completed'=>'Đã hoàn tất'][$move->status],'Lý do'=>$move->reason,'Điều kiện cần xử lý'=>implode(' ',$move->blockers)?:'Đã kiểm tra, cần xác nhận trước khi chuyển'], $move->status==='pending'?[$this->form('Xác nhận chuyển nhà','property.move.commit',[$this->field('confirm','Tôi đã kiểm tra phạm vi quản lý, người nhận tiền và nghĩa vụ hiện có','checkbox')],$move->id)]:[]);
            }
            $query = Property::whereIn('id', array_keys($viewProps))->withCount(['rooms' => fn ($r) => $r->whereIn('id', $this->access->roomIds($this->user, $this->workspace, 'property.view')), 'rooms as vacant_count' => fn ($r) => $r->whereIn('id', $this->access->roomIds($this->user, $this->workspace, 'property.view'))->where('status', 'vacant')])->where(fn ($p) => $p->where('name', 'like', '%'.$q.'%')->orWhere('address', 'like', '%'.$q.'%'));
            $moveRecords=$this->vm['records'];
            $this->records($query->latest(), function ($p) {
                $forms = [];
                if ($this->access->allowed($this->user, 'property.update', $this->workspace, $p->id)) {
                    if (! $p->retired_at) $forms = [$this->form('Sửa thông tin nhà', 'property.save', $this->propertyFields($p), $p->id), $this->rentalPolicyForm('property', $p)];
                    $forms[] = $this->assetForm($p, 'property');
                    $targets=\App\Modules\Workspace\Models\Workspace::where('owner_id',$this->workspace->owner_id)->whereKeyNot($this->workspace->id)->get()->filter(fn($w)=>$this->access->allowed($this->user,'property.create',$w))->pluck('name','id')->all();
                    if($targets&&$this->access->allowed($this->user,'property.move',$this->workspace,$p->id))$forms[]=$this->form('Chuyển sang khu quản lý khác','property.move.propose',[$this->field('target_workspace_id','Khu nhận','select',$targets),$this->field('reference','Mã đề nghị','hidden',value:(string)\Illuminate\Support\Str::uuid()),$this->field('reason','Lý do chuyển','textarea')],$p->id);
                }
                return $this->card($p->name, $p->address, ['Khu vực' => $p->district, 'Số phòng' => $p->rooms_count, 'Phòng trống' => $p->vacant_count], $forms, [$this->link('Xem các phòng', 'properties', ['property_id' => $p->id])]) + ['images' => HousingImages::for($p), 'description' => $p->description, 'badge' => $p->retired_at ? 'Đã ngừng khai thác' : $p->vacant_count.' phòng trống'];
            });
            $this->vm['property_moves']=$moveRecords;

            return;
        }
        $query = $this->rooms()->with('property')->where(fn ($r) => $r->where('name', 'like', '%'.$q.'%')->orWhereHas('property', fn ($p) => $p->where('name', 'like', '%'.$q.'%')->orWhere('address', 'like', '%'.$q.'%')));
        if ($this->request->filled('property_id')) {
            $query->where('property_id', $this->request->integer('property_id'));
        }
        if ($this->request->filled('status')) {
            $query->where('status', $this->request->input('status'));
        }
        if ($slug === 'room-detail') {
            $query->whereKey($this->request->integer('id'));
        }
        $this->records($query->latest(), function ($room) use ($slug) {
            $forms = [];
            if (! $room->retired_at && $this->can('property.manage', $room->property_id, $room->id)) {
                $forms[] = $this->form('Sửa thông tin phòng', 'room.save', $this->roomFields([$room->property_id => $room->property->name], $room), $room->id);
                $forms[] = $this->rentalPolicyForm('room', $room);
                if ($room->status === 'vacant') {
                    $forms[] = $this->form('Ngưng sử dụng', 'room.inactivate', [], $room->id);
                }
            }
            if ($this->can($room->retired_at ? 'room.update' : 'room.deactivate', $room->property_id, $room->id)) $forms[] = $this->assetForm($room, 'room');
            $links = $slug !== 'room-detail' ? [$this->link('Hồ sơ phòng', 'room-detail', ['id' => $room->id])] : [];
            if ($this->can('listing.view', $room->property_id, $room->id)) {
                $links[] = $this->link('Tin cho thuê', 'workspace-listings', ['room_id' => $room->id]);
            }
            if ($this->can('iot.view', $room->property_id, $room->id) && config('features.iot')) {
                $links[] = $this->link('Thiết bị và an toàn', 'iot-devices', ['room_id' => $room->id]);
            }

            return $this->card($room->name, $room->property->name.' · '.$room->property->address, ['Loại hình' => HousingTypes::label($room->room_type), 'Tiền cọc' => self::money($room->deposit), 'Diện tích' => (float) $room->area.' m²', 'Số người tối đa' => $room->capacity, 'Tiện nghi phòng' => HousingAmenities::labels($room, 'room') ?: 'Chưa cập nhật', 'Tiện ích nhà' => HousingAmenities::labels($room->property, 'property') ?: 'Chưa cập nhật', 'Quy định' => $room->rules ?: 'Chưa cập nhật'], $forms, $links) + ['images' => HousingImages::for($room), 'price' => self::money($room->rent), 'badge' => self::label($room->status), 'area' => (float) $room->area, 'capacity' => $room->capacity, 'url' => $this->link('', 'room-detail', ['id' => $room->id])['url']];
        });
        if ($slug === 'room-detail' && ! $this->vm['records']) {
            abort(404);
        }
    }

    private function propertyFields(?Property $p = null): array
    {
        return [$this->field('name', 'Tên nhà trọ', value: $p?->name), $this->field('address', 'Địa chỉ', value: $p?->address), $this->field('public_address', 'Địa chỉ hiển thị công khai', value: $p?->public_address, required: false), $this->field('district', 'Khu vực', value: $p?->district), $this->field('description', 'Giới thiệu nhà trọ', 'textarea', value: $p?->description, required: false), $this->field('amenity_ids', 'Tiện ích chung', 'amenities', config('housing.amenities.property'), ['selected' => $p?->amenity_ids ?? [], 'custom' => $p?->custom_amenities ?? []], false), $this->galleryField('Ảnh nhà trọ', $p)];
    }

    private function assetForm(Property|Room $asset, string $type): array
    {
        return $this->form($asset->retired_at ? 'Khôi phục khai thác' : ($type === 'property' ? 'Ngừng khai thác nhà và các phòng' : 'Ngừng khai thác phòng'), $asset->retired_at ? 'asset.restore' : 'asset.retire', [
            $this->field('asset_type', 'Loại tài nguyên', 'hidden', value: $type), $this->field('version', 'Phiên bản', 'hidden', value: $asset->version),
            $this->field('reason', 'Lý do', 'textarea'), $this->field('confirm', 'Tôi đã kiểm tra nghĩa vụ và xác nhận thay đổi', 'checkbox'),
        ], $asset->id) + ['confirmation' => 'Dữ liệu, chứng từ và lịch sử được giữ nguyên. Nhà hoặc phòng chỉ được ngừng khai thác khi đã xử lý xong các nghĩa vụ. Khôi phục không tự đăng lại tin.'];
    }

    private function roomFields(array $props, ?Room $room = null): array
    {
        return [$this->field('property_id', 'Nhà trọ', 'select', $props, $room?->property_id ?? $this->request->integer('property_id')), $this->field('name', 'Tên phòng', value: $room?->name), $this->field('floor', 'Tầng', value: $room?->floor, required: false), $this->field('room_type', 'Loại hình cho thuê', 'housing-type', HousingTypes::options($this->workspace->id), $room?->room_type, false), $this->field('length', 'Chiều dài (m)', 'number', value: $room?->length, required: false), $this->field('width', 'Chiều rộng (m)', 'number', value: $room?->width, required: false), $this->field('available_from', 'Ngày có thể nhận phòng', 'date', value: $room?->available_from, required: false), $this->field('area', 'Diện tích (m²)', 'number', value: $room?->area), $this->field('rent', 'Tiền thuê (đồng)', 'number', value: $room?->rent), $this->field('deposit', 'Tiền cọc (đồng)', 'number', value: $room?->deposit ?? 0), $this->field('capacity', 'Số người tối đa', 'number', value: $room?->capacity ?? 2), $this->field('amenity_ids', 'Tiện nghi riêng', 'amenities', config('housing.amenities.room'), ['selected' => $room?->amenity_ids ?? [], 'custom' => array_values(array_unique(array_merge($room?->custom_amenities ?? [], $room?->amenities ? [$room->amenities] : [])))], false), $this->field('rules', 'Quy định', 'textarea', value: $room?->rules, required: false), $this->galleryField('Ảnh phòng', $room)];
    }
}
