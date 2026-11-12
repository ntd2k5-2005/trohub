<?php

namespace App\Modules\Chat;

use App\Models\User;
use App\Modules\Chat\Models\Thread;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChatService
{
    public function __construct(private ContextRegistry $contexts, private ChatRepository $repository) {}

    public function authorized(User $user, int $id): Thread
    {
        abort_unless(config('features.internal_chat'), 404);
        abort_unless($user->status === 'active', 403);
        $thread = Thread::findOrFail($id);
        $this->contexts->get($thread->context_type)->authorize($user, $thread);

        return $thread;
    }

    public function state(User $user, Thread $thread, int $after = 0, ?int $before = null): array
    {
        $this->authorized($user, $thread->id);
        $adapter = $this->contexts->get($thread->context_type);

        return $adapter->details($user, $thread) + ['id' => $thread->id, 'messages' => $this->repository->page($thread, $user, $after, $before), 'peer_read_through' => $this->repository->peerRead($thread, $adapter->side($user, $thread))];
    }

    public function send(User $user, int $id, string $body, string $client): void
    {
        $thread = $this->authorized($user, $id);
        abort_unless($this->contexts->get($thread->context_type)->permits($user, $thread, 'chat.send'), 403);
        if (! trim($body)) {
            throw ValidationException::withMessages(['body' => 'Vui lòng nhập tin nhắn.']);
        }
        $this->contexts->get($thread->context_type)->transaction($user, $thread, 'chat.send', function () use ($thread, $user, $body, $client) {
            $thread = Thread::whereKey($thread->id)->lockForUpdate()->firstOrFail();
            $adapter = $this->contexts->get($thread->context_type);
            $adapter->authorize($user, $thread);
            if (! $adapter->details($user, $thread)['can_send']) {
                throw ValidationException::withMessages(['body' => 'Cuộc trò chuyện này đã kết thúc. Bạn vẫn có thể xem lịch sử.']);
            }
            if ($this->repository->append($thread, $user, $adapter->side($user, $thread), trim($body), $client)) {
                $adapter->sent($user, $thread);
            }
        });
    }

    public function read(User $user, int $id, int $through): void
    {
        $thread = $this->authorized($user, $id);
        $this->contexts->get($thread->context_type)->transaction($user, $thread, 'chat.view', function () use ($thread, $user, $through) {
            $thread = Thread::whereKey($thread->id)->lockForUpdate()->firstOrFail();
            $adapter = $this->contexts->get($thread->context_type);
            $adapter->authorize($user, $thread);
            $this->repository->markRead($thread, $user, $adapter->side($user, $thread), $through);
            if (! DB::table('chat_messages')->where('thread_id', $thread->id)->where('id', '>', $through)->exists()) {
                $adapter->read($user, $thread);
            }
        });
    }
}
