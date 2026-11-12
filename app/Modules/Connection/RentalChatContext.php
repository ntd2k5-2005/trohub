<?php

namespace App\Modules\Connection;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Chat\Contracts\ChatContext;
use App\Modules\Chat\Models\Thread;
use App\Modules\Connection\Models\Connection;
use App\Modules\Listing\ListingService;
use App\Modules\Notification\NotificationService;
use App\Modules\Workspace\Models\Workspace;
use App\Support\HousingImages;
use App\Support\ProductPresenter;
use App\Support\ScopedRepository;
use Illuminate\Database\Eloquent\Builder;

final class RentalChatContext implements ChatContext
{
    public function transaction(User $user, Thread $thread, string $permission, \Closure $operation): mixed
    {
        $workspace = $this->source($thread)->listing->room->property->workspace_id;
        return app(\App\Modules\Subscription\PropertyCloud::class)->transaction($workspace, $operation, function () use ($user, $thread, $permission) {
            $this->authorize($user, $thread);
            abort_unless($this->permits($user, $thread, $permission), 403);
        });
    }

    private function source(Thread $thread): Connection
    {
        return Connection::with('user', 'listing.room.property.workspace.owner')->findOrFail($thread->context_id);
    }

    public function visibleSources(User $user, ?Workspace $workspace, string $query): Builder
    {
        if (! $workspace) {
            abort_unless(app(Authorization::class)->personal($user, 'chat.view'), 403);
        }
        $q = Connection::whereHas('listing.room.property.workspace', fn ($w) => app(\App\Modules\Subscription\PropertyCloud::class)->eligible($w));
        if ($workspace) {
            $q->whereHas('listing', fn ($l) => $l->whereIn('room_id', app(ScopedRepository::class)->rooms($user, $workspace, 'chat.view')->select('id')));
        } else {
            $q->where('user_id', $user->id);
        }
        if ($query !== '') {
            $q->whereHas($workspace ? 'user' : 'listing.room.property.workspace.owner', fn ($u) => $u->where('name', 'like', '%'.$query.'%'));
        }

        return $q;
    }

    public function authorize(User $user, Thread $thread): void
    {
        app(ConversationService::class)->authorized($user, $thread->context_id);
    }

    public function permits(User $user, Thread $thread, string $permission): bool
    {
        $c = $this->source($thread);
        $access = app(Authorization::class);
        if (! app(\App\Modules\Subscription\PropertyCloud::class)->active($c->listing->room->property->workspace)) {
            return false;
        }

        return $c->user_id === $user->id ? $access->personal($user, $permission) : $access->allowed($user, $permission, $c->listing->room->property->workspace, $c->listing->room->property_id, $c->listing->room_id);
    }

    public function side(User $user, Thread $thread): string
    {
        return $this->source($thread)->user_id === $user->id ? 'requester' : 'management';
    }

    public function details(User $user, Thread $thread): array
    {
        $c = $this->source($thread);
        $personal = $c->user_id === $user->id;
        $w = $c->listing->room->property->workspace;

        return ['name' => $personal ? $w->owner->name : $c->user->name, 'subtitle' => $c->listing->room->property->name, 'context_preview' => 'Đã chia sẻ một phòng',
            'can_send' => $this->permits($user, $thread, 'chat.send') && ! in_array($c->status, ['cancelled', 'rejected']),
            'workflow_url' => route('ui.show', ['slug' => $personal ? 'connections' : 'workspace-leads', 'context' => $personal ? 'personal' : 'workspace', 'id' => $c->id] + ($personal ? [] : ['workspace_id' => $w->id])),
            'workflow_label' => 'Lịch xem & yêu cầu thuê'];
    }

    public function card(User $user, Thread $thread): array
    {
        $c = $this->source($thread);
        $l = $c->listing;
        $available = app(ListingService::class)->available()->whereKey($l->id)->exists();

        return ['label' => 'Phòng được chia sẻ', 'image_alt' => 'Ảnh phòng được chia sẻ', 'action_label' => 'Xem chi tiết phòng →', 'title' => $l->title, 'subtitle' => $l->room->property->address, 'price' => ProductPresenter::money($l->room->rent).'/tháng', 'image' => $available ? HousingImages::for($l)[0] : config('housing.fallback_image'),
            'url' => $available ? route('ui.show', ['slug' => 'listing-detail', 'context' => 'public', 'id' => $l->id]) : null,
            'unavailable' => 'Tin hiện không còn công khai'];
    }

    public function sent(User $user, Thread $thread): void
    {
        $c = $this->source($thread);
        $toOwner = $c->user_id === $user->id;
        $w = $c->listing->room->property->workspace;
        if (! $toOwner && $c->status === 'new') {
            $c->update(['status' => 'contacted']);
        }
        $c->touch();
        $recipient = $toOwner ? $w->owner_id : $c->user_id;
        $url = route('ui.show', ['slug' => 'conversations', 'context' => $toOwner ? 'workspace' : 'personal', 'id' => $thread->id] + ($toOwner ? ['workspace_id' => $w->id] : []), false);
        app(NotificationService::class)->linked($recipient, 'conversation:'.$c->id.':'.$recipient, 'Tin nhắn từ '.$user->name, 'Bạn có tin nhắn mới.', $url, true, 'Mở cuộc trò chuyện', workspace: $w->id);
    }

    public function read(User $user, Thread $thread): void
    {
        app(NotificationService::class)->readKey($user, 'conversation:'.$thread->context_id.':'.$user->id);
    }
}
