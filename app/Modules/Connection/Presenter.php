<?php

namespace App\Modules\Connection;

use App\Modules\Connection\Models\Connection;
use App\Support\ProductPresenter;
use Illuminate\Support\Carbon;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $this->connections($q);
    }

    protected function connections(string $q): void
    {
        $query = Connection::with('listing.room.property', 'user');
        if ($this->workspace) {
            $query->whereHas('listing', fn ($b) => $b->whereIn('room_id', $this->rooms('connection.view')->select('id')));
        } else {
            $query->where('user_id', $this->user->id)->whereHas('listing.room.property.workspace', fn ($w) => app(\App\Modules\Subscription\PropertyCloud::class)->eligible($w));
        }
        if ($q !== '') {
            $query->whereHas('listing', fn ($l) => $l->where('title', 'like', '%'.$q.'%'));
        }
        if ($this->request->filled('id')) {
            $query->whereKey($this->request->integer('id'));
            (clone $query)->firstOrFail();
        }
        $this->records($query->latest('updated_at'), function ($c) {
            $forms = [];
            $next = ['new' => ['contacted', 'viewing_scheduled', 'rejected'], 'contacted' => ['viewing_scheduled', 'agreed', 'rejected'], 'viewing_scheduled' => ['viewing_scheduled', 'cancelled'], 'viewing_confirmed' => ['viewed', 'viewing_scheduled', 'cancelled'], 'viewed' => ['agreed', 'rejected']];
            $choices = $this->workspace && $c->user_id !== $this->user->id ? ($next[$c->status] ?? []) : (in_array($c->status, ['new', 'contacted', 'viewing_scheduled', 'viewing_confirmed', 'viewed']) ? ($c->status === 'viewing_scheduled' ? ['viewing_confirmed', 'cancelled'] : ['cancelled']) : []);
            if (in_array($c->status, ['new', 'contacted', 'viewing_scheduled', 'viewing_confirmed']) && ! in_array('viewing_scheduled', $choices)) {
                $choices[] = 'viewing_scheduled';
            }
            if ($c->status === 'viewing_scheduled') {
                $requesterProposed = ($c->viewing_proposed_by ?? $c->listing->room->property->workspace->owner_id) === $c->user_id;
                $canConfirm = ($this->user->id === $c->user_id) !== $requesterProposed;
                $choices = array_values(array_diff($choices, ['viewing_confirmed']));
                if ($canConfirm) {
                    $choices[] = 'viewing_confirmed';
                }
            }
            if ($choices) {
                $forms[] = $this->form('Cập nhật liên hệ', 'connection.update', [$this->field('status', 'Bước tiếp theo', 'select', array_combine($choices, array_map([self::class, 'label'], $choices))), $this->field('viewing_at', 'Lịch xem (khi đặt lịch)', 'datetime-local', required: false)], $c->id);
            }

            $links = [];
            if (config('features.internal_chat') && $this->can('chat.view', $c->listing->room->property_id, $c->listing->room_id)) {
                $links[] = $this->link('Mở cuộc trò chuyện', 'conversations', ['id' => app(ConversationRepository::class)->thread($c->id)->id]);
            }
            if ($this->workspace && $c->status === 'agreed' && $this->can('rental.create', $c->listing->room->property_id, $c->listing->room_id)) {
                $links[] = $this->link('Lập hồ sơ thuê', 'rentals', ['connection_id' => $c->id]);
            }

            return $this->card($c->listing->title, $this->workspace ? $c->user->name : $c->listing->room->property->name, ['Trạng thái' => self::label($c->status), 'Lời nhắn' => $c->message, 'Lịch hẹn' => $c->viewing_at ? Carbon::parse($c->viewing_at)->format('d/m/Y H:i') : 'Chưa hẹn', 'Nhu cầu thuê' => $c->rental_requested_at ? 'Đã gửi yêu cầu thuê' : 'Đang tìm hiểu'], $forms, $links);
        });
    }
}
