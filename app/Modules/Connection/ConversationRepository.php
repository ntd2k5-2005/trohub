<?php

namespace App\Modules\Connection;

use App\Models\User;
use App\Modules\Chat\ChatRepository;
use App\Modules\Chat\Models\Thread;
use App\Modules\Connection\Models\Connection;

// Compatibility boundary for existing rental-contact callers; storage belongs to Chat.
final class ConversationRepository
{
    public function thread(int $connection): Thread
    {
        $c = Connection::with('listing.room.property')->findOrFail($connection);

        return app(ChatRepository::class)->initialize('rental_contact', $connection, $c->listing->room->property->workspace_id, User::findOrFail($c->user_id), 'requester', $c->message);
    }

    public function lastText(int $connection): ?string
    {
        return app(ChatRepository::class)->lastText($this->thread($connection));
    }

    public function messages(int $connection, int $viewer, int $after = 0, ?int $before = null): array
    {
        return app(ChatRepository::class)->page($this->thread($connection), User::findOrFail($viewer), $after, $before);
    }

    public function append(int $connection, int $sender, string $body, string $clientId): bool
    {
        $thread = $this->thread($connection);
        $user = User::findOrFail($sender);

        return app(ChatRepository::class)->append($thread, $user, app(RentalChatContext::class)->side($user, $thread), $body, $clientId);
    }
}
