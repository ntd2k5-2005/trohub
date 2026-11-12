<?php

namespace App\Modules\Connection;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Chat\ChatService;
use App\Modules\Connection\Models\Connection;
use App\Modules\DataPlatform\Journal;
use App\Support\ScopedRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ConversationService
{
    public function __construct(private ConversationRepository $messages, private ScopedRepository $scope) {}

    public function authorized(User $user, int $id): Connection
    {
        abort_unless($user->status === 'active', 403);
        $c = Connection::with('listing.room.property.workspace.owner', 'user')->findOrFail($id);
        app(\App\Modules\Subscription\PropertyCloud::class)->requireRoom($c->listing->room_id);
        abort_if($c->listing->room->property->workspace->purged_at, 404);
        if ($c->user_id !== $user->id) {
            $this->scope->managedRoom($user, $c->listing->room_id, 'chat.view');
        } else {
            abort_unless(app(Authorization::class)->personal($user, 'chat.view'), 403);
        }

        return $c;
    }

    public function open(User $user, int $listingId, string $intent, ?string $body, ?string $viewing): Connection
    {
        abort_unless($user->status === 'active', 403);
        abort_unless(app(Authorization::class)->personal($user, 'chat.send') && app(Authorization::class)->personal($user, 'chat.view'), 403);

        return DB::transaction(function () use ($user, $listingId, $intent, $body, $viewing) {
            $intro = $body ?: match ($intent) {
                'viewing' => 'Tôi muốn hẹn xem phòng.', 'rent' => 'Tôi muốn trao đổi để thuê phòng.', default => 'Chào bạn, phòng này còn cho thuê không?',
            };
            $c = app(ConnectionService::class)->create($user, $listingId, $intro);
            $c = Connection::whereKey($c->id)->lockForUpdate()->firstOrFail();
            if (in_array($c->status, ['cancelled', 'rejected', 'agreed'])) {
                if ($intent !== 'chat') {
                    throw ValidationException::withMessages(['intent' => 'Liên hệ đã kết thúc. Vui lòng xem lại trao đổi trước đó.']);
                }

                return $c;
            }
            if ($intent === 'viewing') {
                app(ConnectionService::class)->transition($user, $c, 'viewing_scheduled', $viewing);
            } elseif ($intent === 'rent' && ! $c->rental_requested_at) {
                $c->update(['rental_requested_at' => now()]);
                if ($this->messages->lastText($c->id) !== $intro) {
                    $this->messages->append($c->id, $user->id, $intro, (string) Str::uuid());
                }
                app(Journal::class)->record($user->id, 'connection.rental_requested', $c, $c->listing->room->property->workspace_id);
            }
            $this->notify($user, $c);

            return $c;
        });
    }

    public function send(User $user, int $id, string $body, string $clientId): void
    {
        $this->authorized($user, $id);
        app(ChatService::class)->send($user, $this->messages->thread($id)->id, $body, $clientId);
    }

    private function notify(User $sender, Connection $c): void
    {
        app(RentalChatContext::class)->sent($sender, $this->messages->thread($c->id));
    }
}
