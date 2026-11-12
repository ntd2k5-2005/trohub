<?php

namespace App\Modules\Notification;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Contracts\HotCache;
use App\Modules\Notification\Models\UserNotification;

final class NotificationService
{
    public function linked(int $userId, string $key, string $title, string $body, string $url, ?bool $attention = null, string $label = 'Xem chi tiết', ?int $workspace = null): void
    {
        $attributes = app(\App\Modules\Subscription\PropertyCloud::class)->noticeAttributes($workspace);
        $key = $this->versionedKey($key, $attributes);
        $notification = UserNotification::withoutGlobalScopes()->firstOrCreate(['dedupe_key' => $key], ['user_id' => $userId, 'title' => $title, 'body' => $body, 'action_url' => $url, 'action_label' => $label] + $attributes);
        abort_unless($notification->user_id === $userId, 403);
        if ($attention !== null) {
            $notification->update(['title' => $title, 'body' => $body, 'action_url' => $url, 'read_at' => $attention ? null : now()]);
        }
        app(HotCache::class)->forget('notifications:'.$userId);
    }

    public function readKey(User $user, string $key): void
    {
        UserNotification::where('user_id', $user->id)->where(fn ($q) => $q->where('dedupe_key', $key)->orWhere('dedupe_key', 'like', $key.'@cloud:%'))->update(['read_at' => now()]);
        app(HotCache::class)->forget('notifications:'.$user->id);
    }

    public function once(int $userId, string $key, string $title, string $body, ?int $workspace = null): bool
    {
        $attributes = app(\App\Modules\Subscription\PropertyCloud::class)->noticeAttributes($workspace);
        $notification = UserNotification::withoutGlobalScopes()->firstOrCreate(['dedupe_key' => $this->versionedKey($key, $attributes)], ['user_id' => $userId, 'title' => $title, 'body' => $body] + $attributes);
        abort_unless($notification->user_id === $userId, 403);
        if ($notification->wasRecentlyCreated) {
            app(HotCache::class)->forget('notifications:'.$userId);
        }

        return $notification->wasRecentlyCreated;
    }

    private function versionedKey(string $key, array $attributes): string
    {
        return ($attributes['cloud_epoch'] ?? 1) > 1 ? $key.'@cloud:'.$attributes['workspace_id'].':'.$attributes['cloud_epoch'] : $key;
    }

    public function retire(int $userId, string $key): void
    {
        UserNotification::where('user_id', $userId)->where('dedupe_key', $key)->update(['title' => 'Lịch nhắc đã được cập nhật', 'body' => 'Lịch hẹn hoặc thời hạn trước đây đã thay đổi. Mở hồ sơ để xem thông tin hiện tại.', 'read_at' => now()]);
        app(HotCache::class)->forget('notifications:'.$userId);
    }

    public function markRead(User $user, int $id): void
    {
        abort_unless(app(Authorization::class)->personal($user, 'notification.read'), 403);
        UserNotification::where('user_id', $user->id)->findOrFail($id)->update(['read_at' => now()]);
        app(HotCache::class)->forget('notifications:'.$user->id);
    }
}
