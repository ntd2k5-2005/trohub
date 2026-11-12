<?php

namespace App\Modules\Notification;

use App\Modules\Notification\Events\InboxOpened;
use App\Modules\Notification\Models\UserNotification;
use App\Support\ProductPresenter;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        event(new InboxOpened($this->user));
        $filters = $this->request->validate(['state' => 'nullable|in:all,unread,read', 'type' => 'nullable|in:all,safety']);
        $this->vm['tabs'] = [$this->link('Tất cả', 'notifications'), $this->link('Chưa đọc', 'notifications', ['state' => 'unread']), $this->link('An toàn', 'notifications', ['type' => 'safety'])];
        $query = UserNotification::where('user_id', $this->user->id)->when(($filters['state'] ?? '') === 'unread', fn ($b) => $b->whereNull('read_at'))->when(($filters['state'] ?? '') === 'read', fn ($b) => $b->whereNotNull('read_at'))->when(($filters['type'] ?? '') === 'safety', fn ($b) => $b->where('priority', '>=', 2));
        $this->records($query->orderByRaw('CASE WHEN read_at IS NULL AND priority > 0 THEN 0 ELSE 1 END')->orderByDesc('updated_at')->orderByDesc('id'), fn ($n) => $this->card($n->title, $n->body, ['Mức ưu tiên' => $n->priority >= 2 ? 'An toàn' : 'Thông thường', 'Thời gian' => $n->updated_at->format('d/m/Y H:i'), 'Trạng thái' => $n->read_at ? 'Đã đọc' : 'Chưa đọc'], $n->read_at ? [] : [$this->form('Đánh dấu đã đọc', 'notification.read', [], $n->id)], $n->action_url ? [['label' => $n->action_label, 'url' => $n->action_url]] : []));
    }
}
