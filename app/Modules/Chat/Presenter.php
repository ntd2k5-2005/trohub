<?php

namespace App\Modules\Chat;

use App\Support\ProductPresenter;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $repository = app(ChatRepository::class);
        $service = app(ChatService::class);
        $selected = null;
        if ($this->request->filled('id')) {
            $thread = $repository->visible($this->user, $this->workspace)->findOrFail($this->request->integer('id'));
            $selected = $service->state($this->user, $service->authorized($this->user, $thread->id));
            $selected += ['messages_url' => route('chat.messages', ['thread' => $thread->id]), 'send_url' => route('chat.send', ['thread' => $thread->id]), 'read_url' => route('chat.read', ['thread' => $thread->id])];
        }
        $this->vm['chat'] = ['draft_key' => 'chat-drafts:'.$this->user->id, 'selected' => $selected, 'inbox' => $repository->inbox($this->user, $this->workspace, $q), 'q' => $q, 'page_size' => config('chat.page_size'), 'poll_ms' => config('chat.poll_ms'),
            'inbox_url' => route('chat.inbox', ['context' => $this->workspace ? 'workspace' : 'personal'] + ($this->workspace ? ['workspace_id' => $this->workspace->id] : [])),
            'home_url' => $this->link('', 'conversations')['url']];
    }
}
