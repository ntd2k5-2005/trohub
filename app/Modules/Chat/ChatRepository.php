<?php

namespace App\Modules\Chat;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Chat\Models\Thread;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ChatRepository
{
    public function visible(User $user, ?Workspace $workspace, string $query = '')
    {
        abort_unless(config('features.internal_chat'), 404);
        $access = app(Authorization::class);
        abort_unless($workspace ? ($access->allowed($user, 'chat.view', $workspace) || $access->roomIds($user, $workspace, 'chat.view') !== []) : $access->personal($user, 'chat.view'), 403);
        $registry = app(ContextRegistry::class);

        return Thread::where(function ($q) use ($registry, $user, $workspace, $query) {
            $q->whereRaw('1=0');
            foreach (array_keys(config('chat.contexts')) as $type) {
                $q->orWhere(fn ($sub) => $sub->where('context_type', $type)->whereIn('context_id', $registry->get($type)->visibleSources($user, $workspace, $query)->select('id')));
            }
        });
    }

    public function ensure(string $type, int $source, ?int $workspace): Thread
    {
        return Thread::firstOrCreate(['context_type' => $type, 'context_id' => $source], ['workspace_id' => $workspace]);
    }

    public function initialize(string $type, int $source, ?int $workspace, User $sender, string $side, ?string $intro): Thread
    {
        return DB::transaction(function () use ($type, $source, $workspace, $sender, $side, $intro) {
            $thread = $this->ensure($type, $source, $workspace);
            $thread = Thread::whereKey($thread->id)->lockForUpdate()->firstOrFail();
            $this->append($thread, $sender, $side, '', 'initial-context', 'context');
            if ($intro && ! $this->hasText($thread)) {
                $this->append($thread, $sender, $side, $intro, 'initial-message');
            }

            return $thread;
        });
    }

    public function hasText(Thread $thread): bool
    {
        return DB::table('chat_messages')->where('thread_id', $thread->id)->where('kind', 'text')->exists();
    }

    public function lastText(Thread $thread): ?string
    {
        return DB::table('chat_messages')->where('thread_id', $thread->id)->where('kind', 'text')->orderByDesc('id')->value('body');
    }

    public function append(Thread $thread, User $sender, string $side, string $body, string $client, string $kind = 'text'): bool
    {
        $inserted = DB::table('chat_messages')->insertOrIgnore(['thread_id' => $thread->id, 'sender_id' => $sender->id, 'sender_side' => $side, 'body' => $body, 'client_id' => $client, 'kind' => $kind, 'created_at' => now(), 'updated_at' => now()]) === 1;
        if ($inserted) {
            $thread->touch();
        }

        return $inserted;
    }

    public function page(Thread $thread, User $viewer, int $after = 0, ?int $before = null): array
    {
        $q = DB::table('chat_messages')->join('users', 'users.id', '=', 'chat_messages.sender_id')->where('thread_id', $thread->id)->select('chat_messages.*', 'users.name');
        if ($after) {
            $q->where('chat_messages.id', '>', $after);
        }
        if ($before) {
            $q->where('chat_messages.id', '<', $before);
        }
        $rows = ($after ? $q->orderBy('chat_messages.id') : $q->orderByDesc('chat_messages.id'))->limit(config('chat.page_size'))->get()->sortBy('id');
        $adapter = app(ContextRegistry::class)->get($thread->context_type);
        $card = $rows->contains('kind', 'context') ? $adapter->card($viewer, $thread) : null;

        return $rows->map(fn ($m) => ['id' => $m->id, 'body' => $m->body, 'name' => $m->name, 'mine' => $m->sender_id === $viewer->id, 'kind' => $m->kind, 'card' => $m->kind === 'context' ? $card : null, 'time' => Carbon::parse($m->created_at)->format('H:i'), 'date' => Carbon::parse($m->created_at)->format('d/m/Y')])->values()->all();
    }

    public function peerRead(Thread $thread, string $side): int
    {
        return (int) DB::table('chat_reads')->where('thread_id', $thread->id)->where('side', '!=', $side)->max('last_message_id');
    }

    public function markRead(Thread $thread, User $user, string $side, int $through): void
    {
        abort_unless(DB::table('chat_messages')->where('thread_id', $thread->id)->where('id', $through)->exists(), 422);
        DB::table('chat_reads')->insertOrIgnore(['thread_id' => $thread->id, 'user_id' => $user->id, 'side' => $side, 'last_message_id' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('chat_reads')->where('thread_id', $thread->id)->where('user_id', $user->id)->where('last_message_id', '<', $through)->update(['last_message_id' => $through, 'updated_at' => now()]);
    }

    public function inbox(User $user, ?Workspace $workspace, string $query, int $page = 1): array
    {
        $pager = $this->visible($user, $workspace, $query)->orderByDesc('updated_at')->orderByDesc('id')->paginate(config('chat.inbox_size'), ['*'], 'page', $page);
        $items = $pager->getCollection()->map(function ($thread) use ($user, $workspace) {
            $adapter = app(ContextRegistry::class)->get($thread->context_type);
            $side = $adapter->side($user, $thread);
            $info = $adapter->details($user, $thread);
            $last = DB::table('chat_messages')->where('thread_id', $thread->id)->orderByDesc('id')->first();
            $read = (int) DB::table('chat_reads')->where('thread_id', $thread->id)->where('user_id', $user->id)->value('last_message_id');
            $unread = DB::table('chat_messages')->where('thread_id', $thread->id)->where('sender_side', '!=', $side)->where('id', '>', $read)->count();
            $mine = $last && $last->sender_id === $user->id;

            return ['id' => $thread->id, 'name' => $info['name'], 'initial' => mb_substr($info['name'], 0, 1), 'subtitle' => $info['subtitle'],
                'preview' => $last ? ($last->kind === 'context' ? ($info['context_preview'] ?? 'Đã chia sẻ thông tin') : mb_substr($last->body, 0, 100)) : 'Chưa có tin nhắn',
                'mine' => (bool) $mine, 'state' => $mine ? ($this->peerRead($thread, $side) >= $last->id ? 'Đã xem' : 'Đã gửi') : '',
                'messages_url' => route('chat.messages', ['thread' => $thread->id]), 'send_url' => route('chat.send', ['thread' => $thread->id]), 'read_url' => route('chat.read', ['thread' => $thread->id]), 'unread' => $unread, 'time' => $last ? Carbon::parse($last->created_at)->format('d/m H:i') : '',
                'url' => route('ui.show', ['slug' => 'conversations', 'context' => $workspace ? 'workspace' : 'personal', 'id' => $thread->id] + ($workspace ? ['workspace_id' => $workspace->id] : []))];
        })->all();

        return ['items' => $items, 'page' => $pager->currentPage(), 'pages' => $pager->lastPage(), 'total' => $pager->total()];
    }
}
