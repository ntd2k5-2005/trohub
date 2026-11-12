<?php

namespace App\Modules\Listing;

use App\Modules\Listing\Models\Listing;
use App\Modules\Property\HousingAmenities;
use App\Modules\Property\HousingMedia;
use App\Modules\Property\Models\Room;
use App\Support\HousingImages;
use App\Support\ProductPresenter;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $this->request->validate(['room_id' => 'nullable|integer|min:1']);
        $this->vm['housing'] = 'management';
        $rooms = $this->roomOptions('listing.view');
        $this->vm['status_options'] = ['draft' => 'Bản nháp', 'published' => 'Công khai', 'paused' => 'Tạm dừng', 'closed' => 'Kết thúc'];
        if ($rooms) {
            $this->vm['forms'][] = $this->form('Tạo tin cho thuê', 'listing.save', $this->fields($this->roomOptions('listing.create')));
        }
        $query = Listing::with('room.property')->whereIn('room_id', array_keys($rooms))->where('title', 'like', '%'.$q.'%');
        if ($this->request->filled('room_id')) {
            $query->where('room_id', $this->request->integer('room_id'));
        }
        if ($this->request->filled('status')) {
            $query->where('status', $this->request->input('status'));
        }
        $availableIds = app(ListingService::class)->available()->whereIn('room_id', array_keys($rooms))->pluck('id')->all();
        $this->records($query->latest(), function ($l) use ($rooms, $availableIds) {
            $url = in_array($l->id, $availableIds) ? route('ui.show', ['slug' => 'listing-detail', 'context' => 'public', 'id' => $l->id]) : null;

            $forms = [$this->form('Cập nhật tin', 'listing.save', $this->fields($rooms, $l), $l->id)];
            if (! in_array($l->status, ['published', 'pending_review'], true) && ! $l->moderated && $l->room->status === 'vacant') {
                $forms[] = $this->form('Đăng tin', 'listing.status', [$this->field('status', 'Trạng thái', 'hidden', value: 'published')], $l->id);
            }
            if ($l->status === 'published') {
                $forms[] = $this->form('Tạm dừng tin', 'listing.status', [$this->field('status', 'Trạng thái', 'hidden', value: 'paused')], $l->id);
            }
            if ($l->status !== 'closed') {
                $forms[] = $this->form('Kết thúc tin', 'listing.status', [$this->field('status', 'Trạng thái', 'hidden', value: 'closed')], $l->id);
            }

            return $this->card($l->title, $l->room->property->name.' · '.$l->room->name, ['Địa chỉ' => $l->room->property->address, 'Tình trạng phòng' => self::label($l->room->status)], $forms, array_merge($url ? [['label' => 'Xem tin công khai', 'url' => $url]] : [], [['label' => 'Xem trước', 'url' => route('listing.preview', ['listing' => $l->id])]])) + ['images' => HousingImages::for($l), 'price' => self::money($l->room->rent), 'badge' => $l->moderated ? 'Đã bị ẩn' : self::label($l->status), 'area' => (float) $l->room->area, 'capacity' => $l->room->capacity, 'description' => $l->description, 'url' => $url];
        });
    }

    private function fields(array $rooms, ?Listing $listing = null): array
    {
        return [$this->roomField($rooms, $listing), $this->field('title', 'Tiêu đề', value: $listing?->title), $this->field('description', 'Mô tả', 'textarea', value: $listing?->description), $this->galleryField('Bộ ảnh', $listing), $this->field('cover', 'Tải ảnh bìa từ máy', 'file', required: false), ...($listing?->cover_path ? [$this->field('remove_cover', 'Bỏ ảnh bìa đã tải, dùng bộ ảnh liên kết', 'checkbox', required: false)] : []), $this->field('status', 'Trạng thái', 'hidden', value: $listing?->status ?? 'draft')];
    }

    private function roomField(array $rooms, ?Listing $listing): array
    {
        $field = $this->field('room_id', 'Phòng', $listing ? 'hidden' : 'room-select', $rooms, $listing?->room_id ?? $this->request->integer('room_id'));
        $models = Room::whereIn('id', array_keys($rooms))->with('property')->get();
        app(HousingMedia::class)->preload(array_merge($models->all(), $models->pluck('property')->all()));
        $field['previews'] = $models->mapWithKeys(fn ($room) => [$room->id => ['name' => $room->name, 'property' => $room->property->name, 'area' => (float) $room->area, 'rent' => self::money($room->rent), 'deposit' => self::money($room->deposit), 'capacity' => $room->capacity, 'amenities' => HousingAmenities::labels($room, 'room'), 'rules' => $room->rules, 'image' => HousingImages::for($room)[0]]])->all();

        return $field;
    }
}
