<?php

namespace App\Modules\Marketplace;

use App\Modules\Billing\Models\ServiceRate;
use App\Modules\DataPlatform\InteractionEvents;
use App\Modules\Listing\ListingService;
use App\Modules\Listing\Models\Favorite;
use App\Modules\Listing\Models\Listing;
use App\Modules\Property\HousingAmenities;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\RentalPolicy;
use App\Modules\Trust\Models\Review;
use App\Support\HousingImages;
use App\Support\ProductPresenter;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $this->marketplace($slug, $q);
    }

    protected function marketplace(string $slug, string $q): void
    {
        $this->vm['marketplace'] = true;
        $preview = (int) $this->request->attributes->get('listing.preview', 0);
        if ($preview) {
            $this->vm['preview_back'] = route('ui.show', ['slug' => 'workspace-listings', 'context' => 'workspace', 'workspace_id' => Listing::findOrFail($preview)->room->property->workspace_id]);
        }
        $this->vm['housing'] = $slug === 'listing-detail' ? 'listing-detail' : 'discovery';
        $this->vm['landing'] = $slug === 'marketplace-home';
        $this->request->validate(['capacity' => 'nullable|integer|min:1|max:100', 'amenity' => 'nullable|string|max:100', 'min_price' => 'nullable|integer|min:0|max:1000000000', 'max_price' => 'nullable|integer|min:0|max:1000000000', 'min_area' => 'nullable|numeric|min:0|max:10000', 'district' => 'nullable|string|max:200', 'room_type' => \App\Modules\Property\HousingTypes::rules(), 'move_in' => 'nullable|date', 'sort' => 'nullable|in:newest,price_asc,price_desc']);
        if ($this->request->filled('min_price') && $this->request->filled('max_price') && $this->request->integer('max_price') < $this->request->integer('min_price')) {
            throw ValidationException::withMessages(['max_price' => 'Giá tối đa cần lớn hơn hoặc bằng giá tối thiểu.']);
        }
        $listings = app(ListingService::class)->available()->with('room.property.workspace.owner');
        if ($preview) {
            $listings = Listing::whereKey($preview)->with('room.property.workspace.owner');
        }
        if ($slug === 'favorites' || $slug === 'compare-listings') {
            $listings = app(ListingService::class)->available()->with('room.property.workspace.owner')->whereIn('id', Favorite::where('user_id', $this->user->id)->select('listing_id'));
            $this->vm['links'][] = $this->link($slug === 'favorites' ? 'So sánh phòng đã lưu' : 'Danh sách đã lưu', $slug === 'favorites' ? 'compare-listings' : 'favorites');
        }
        if ($slug === 'listing-detail') {
            $listings->whereKey($this->request->integer('id'));
        }
        if (in_array($slug, ['marketplace-home', 'marketplace-search'], true)) $engine = app(\App\Modules\Listing\PublicListingSearch::class)->apply($listings, $q, $this->request->all());
        else $listings->where(fn ($b) => $b->where('title', 'like', '%'.$q.'%')->orWhere('description', 'like', '%'.$q.'%'));
        if (in_array($slug, ['marketplace-home', 'marketplace-search'], true)) $this->request->attributes->set('search.engine', $engine);
        if ($this->request->filled('district')) {
            $listings->whereHas('room.property', fn ($p) => $p->where('district', 'like', '%'.$this->request->input('district').'%'));
        }
        foreach (['min_price' => ['rent', '>='], 'max_price' => ['rent', '<='], 'min_area' => ['area', '>=']] as $key => [$column,$op]) {
            if ($this->request->filled($key)) {
                $listings->whereHas('room', fn ($r) => $r->where($column, $op, $this->request->input($key)));
            }
        }
        if ($this->request->filled('capacity')) {
            $listings->whereHas('room', fn ($r) => $r->where('capacity', '>=', $this->request->integer('capacity')));
        }
        if ($this->request->filled('room_type')) {
            $listings->whereHas('room', fn ($r) => $r->where('room_type', \App\Modules\Property\HousingTypes::normalize($this->request->input('room_type'))));
        }
        if ($this->request->filled('move_in')) {
            $listings->whereHas('room', fn ($r) => $r->where(fn ($a) => $a->whereNull('available_from')->orWhere('available_from', '<=', $this->request->date('move_in')->toDateString())));
        }
        $this->vm['active_filters'] = [];
        $filterLabels = ['q' => 'Từ khóa', 'district' => 'Khu vực', 'min_price' => 'Giá từ', 'max_price' => 'Giá đến', 'min_area' => 'Diện tích từ', 'capacity' => 'Số người', 'amenity' => 'Tiện nghi', 'move_in' => 'Ngày vào ở', 'room_type' => 'Loại phòng'];
        foreach ($filterLabels as $key => $label) {
            if (! $this->request->filled($key)) {
                continue;
            }
            $value = $this->request->input($key);
            if ($key === 'room_type') {
                $value = \App\Modules\Property\HousingTypes::label($value);
            }
            $this->vm['active_filters'][] = ['label' => $label.': '.$value, 'url' => $this->request->fullUrlWithoutQuery([$key, 'page'])];
        }
        if ($this->request->filled('amenity')) {
            $listings->whereHas('room', fn ($r) => HousingAmenities::filter($r, $this->request->input('amenity')));
        }
        $availableIds = app(ListingService::class)->available()->pluck('id')->all();
        if (in_array($this->request->input('sort'), ['price_asc', 'price_desc'])) {
            $listings->orderBy(Room::select('rent')->whereColumn('rooms.id', 'listings.room_id'), $this->request->input('sort') === 'price_asc' ? 'asc' : 'desc');
        } else {
            $listings->latest();
        }
        $this->records($listings, function ($l) use ($slug, $availableIds, $preview) {
            $room = $l->room;
            $available = in_array($l->id, $availableIds);
            if ($slug === 'listing-detail' && ! $preview) {
                app(InteractionEvents::class)->record('listing.viewed', $room->property->workspace, $l->id);
            }
            $facts = ['Giá thuê' => self::money($room->rent).'/tháng', 'Tiền cọc' => self::money($room->deposit), 'Diện tích' => $room->area.' m²', 'Số người tối đa' => $room->capacity, 'Tình trạng' => $available ? 'Đang nhận khách' : 'Tạm ngừng nhận khách', 'Địa chỉ' => ($room->property->public_address ?: $room->property->district), 'Tiện nghi' => HousingAmenities::labels($room, 'room') ?: 'Chưa cập nhật', 'Tiện ích nhà' => HousingAmenities::labels($room->property, 'property') ?: 'Chưa cập nhật', 'Quy định' => $room->rules ?: 'Trao đổi khi xem phòng'];
            if (in_array($slug, ['listing-detail', 'compare-listings'])) {
                foreach (Review::whereIn('rental_id', Rental::where('room_id', $room->id)->where('status', 'closed')->select('id'))->latest()->limit(5)->get() as $index => $review) {
                    $facts['Đánh giá sau thuê '.($index + 1)] = $review->rating.'/5 · '.$review->comment;
                    $details=app(\App\Modules\Trust\ReviewDetailsService::class)->read($review)['document'];
                    if($comment=data_get($details,'aspects.handover.comment'))$facts['Bàn giao · đánh giá '.($index+1)]=$comment;
                    if($suggestions=data_get($details,'aspects.shared_areas.suggestions'))$facts['Khu vực chung · đánh giá '.($index+1)]=implode('; ',$suggestions);
                    if(data_get($details,'would_recommend')!==null)$facts['Giới thiệu chỗ ở · đánh giá '.($index+1)]=$details['would_recommend']?'Sẵn lòng giới thiệu':'Chưa phù hợp';
                }
                foreach (ServiceRate::where('property_id', $room->property_id)->where('effective_from', '<=', today())->orderByDesc('effective_from')->get()->unique('name') as $rate) {
                    $facts[$rate->name] = self::money($rate->rate).match ($rate->kind) {
                        'electricity' => '/kWh','water' => '/m³',default => '/tháng'
                    };
                }
            }
            if (! in_array($slug, ['listing-detail', 'compare-listings'])) {
                $facts = array_intersect_key($facts, array_flip(['Giá thuê', 'Diện tích', 'Tình trạng', 'Tiện nghi']));
            }
            $forms = [];
            if ($this->user && ! $preview) {
                $policy = app(RentalPolicy::class)->forRoom($room);
                if ($slug === 'listing-detail' && $available && $room->property->workspace->owner_id !== $this->user->id) {
                    $forms[] = $this->form('Đề nghị thuê phòng', 'rental_request.submit', [$this->field('listing_id', '', 'hidden', value: $l->id), $this->field('quote', '', 'hidden', value: app(RentalPolicy::class)->quote($room)), $this->field('starts_at', 'Ngày dự kiến bắt đầu', 'date'), $this->field('ends_at', 'Ngày dự kiến kết thúc', 'date'), $this->field('people', 'Số người dự kiến', 'number', value: 1), $this->field('message', 'Lời nhắn', 'textarea', required: false), $this->field('accept_terms', 'Tôi đồng ý giá thuê '.self::money($room->rent).', tiền cọc '.self::money($room->deposit).' và điều khoản: '.$policy['deposit_terms'].' Giữ phòng '.$policy['hold_hours'].' giờ sau khi được chấp thuận. '.($policy['partial_deposit'] ? 'Cho phép trả cọc từng phần.' : 'Thanh toán tiền cọc một lần.'), 'checkbox')]);
                }
                $saved = Favorite::where('user_id', $this->user->id)->where('listing_id', $l->id)->exists();
                $forms[] = $this->form($saved ? 'Bỏ lưu' : 'Lưu phòng', 'favorite.toggle', [], $l->id);
                if ($slug === 'listing-detail') {
                    $forms[] = $this->form('Báo cáo tin', 'report.create', [$this->field('target_type', 'Loại', 'hidden', value: 'listing'), $this->field('target_id', 'Tin', 'hidden', value: $l->id), $this->field('reason', 'Lý do báo cáo', 'textarea')]);
                }
            }
            $links = $available && $slug !== 'listing-detail' ? [['label' => 'Xem chi tiết', 'url' => route('ui.show', ['slug' => 'listing-detail', 'context' => 'public', 'id' => $l->id])]] : [];
            if (! $this->user && $slug !== 'listing-detail') {
                $links[] = ['label' => 'Đăng nhập để liên hệ', 'url' => route('login', ['listing' => $l->id])];
            }

            $text = $preview ? ['title' => $l->title, 'description' => $l->description] : app(PublicListingText::class)->read($l);
            return $this->card($text['title'], ($room->property->public_address ?: $room->property->district), $facts, $forms, $links) + [
                'images' => HousingImages::for($l), 'price' => self::money($room->rent),
                'description' => $text['description'], 'badge' => $available ? 'Đang nhận khách' : 'Tạm ngừng nhận khách',
                'area' => (float) $room->area, 'capacity' => $room->capacity,
                'url' => $available ? route('ui.show', ['slug' => 'listing-detail', 'context' => 'public', 'id' => $l->id]) : null,
                'listing_id' => $l->id, 'contact_allowed' => ! $preview && $available && $this->user && $this->access->personal($this->user, 'contact.create') && $room->property->workspace->owner_id !== $this->user->id,
                'publisher' => $room->property->workspace->owner->name, 'property_name' => $room->property->name,
                'published_date' => $l->published_at ? Carbon::parse($l->published_at)->format('d/m/Y') : null,
            ];
        });
        if ($slug === 'listing-detail' && ! $this->vm['records']) {
            abort(404);
        }
    }
}
