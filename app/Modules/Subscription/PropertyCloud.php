<?php

namespace App\Modules\Subscription;

use App\Modules\Property\Models\Room;
use App\Modules\Workspace\Models\Workspace;
use App\Support\SqlTransactions;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * RC-12 boundary for the current workspace-backed entitlement storage.
 * Account-level subscription reconciliation remains a separate migration/workflow.
 */
final class PropertyCloud
{
    private ?bool $ready = null;

    public function ready(): bool
    {
        return $this->ready ??= Schema::hasColumns('workspaces', ['cloud_epoch', 'cloud_cutoff_at', 'cloud_resumed_at', 'lifecycle']) && Schema::hasTable('workspace_restrictions') && Schema::hasTable('account_subscriptions') && Schema::hasTable('account_restrictions')
            && Schema::hasColumn('listings', 'cloud_epoch') && Schema::hasColumn('user_notifications', 'scope_kind');
    }

    public function eligible(Builder $query): Builder
    {
        if (! $this->ready()) return $query->whereRaw('1=0');
        return app(\App\Modules\Workspace\WorkspaceLifecycle::class)->unrestricted($query)->whereIn('workspaces.lifecycle', ['active', 'paused'])
            ->where(fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('account_subscriptions')->whereColumn('owner_id', 'workspaces.owner_id')->whereNotNull('authority_enabled_at')->whereIn('status', ['trial', 'active'])->where('expires_at', '>', now()))
                ->orWhere(fn ($legacy) => $legacy->whereNotExists(fn ($s) => $s->selectRaw('1')->from('account_subscriptions')->whereColumn('owner_id', 'workspaces.owner_id')->whereNotNull('authority_enabled_at'))->whereIn('workspaces.status', ['active', 'trial'])->where('workspaces.expires_at', '>', now())))
            ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('account_restrictions')->whereColumn('owner_id', 'workspaces.owner_id')->whereNull('released_at'))
            ->whereNull('workspaces.purged_at')->whereHas('owner', fn ($u) => $u->where('status', 'active'));
    }

    public function active(Workspace|int $workspace): bool
    {
        return $this->eligible(Workspace::query())->whereKey($workspace instanceof Workspace ? $workspace->id : $workspace)->exists();
    }

    public function requireActive(Workspace|int $workspace): Workspace
    {
        $current = Workspace::findOrFail($workspace instanceof Workspace ? $workspace->id : $workspace);
        abort_unless($this->active($current), 403, 'Gói dịch vụ của bên quản lý đã hết hiệu lực. Bạn vẫn có thể xem chứng từ đã xác nhận của chính mình.');
        return $current;
    }

    public function requireRoom(int $room): Workspace
    {
        return $this->requireActive(Room::findOrFail($room)->property->workspace_id);
    }

    public function snapshot(Workspace|int $workspace): array
    {
        abort_unless($this->ready(), 503, 'Hệ thống đang chờ cập nhật dữ liệu để tiếp tục xử lý an toàn.');
        $current = $this->requireActive($workspace);
        $stamp = ['workspace_id' => $current->id, 'owner_id' => $current->owner_id, 'epoch' => (int) $current->cloud_epoch,
            'issued_at' => now()->toIso8601String(), 'expires_at' => $current->expires_at->toIso8601String()];
        return $stamp + ['signature' => hash_hmac('sha256', json_encode($stamp), config('app.key'))];
    }

    public function matches(int $workspace, ?array $stamp): bool
    {
        if (! $this->ready() || ! $stamp || ! $this->active($workspace)) return false;
        $signature = $stamp['signature'] ?? '';
        unset($stamp['signature']);
        // MySQL JSON normalizes object key order. Verify the original signing
        // schema in its defined order, preserving signatures already issued.
        $keys = ['workspace_id', 'owner_id', 'epoch', 'issued_at', 'expires_at'];
        if (count($stamp) !== count($keys) || array_diff($keys, array_keys($stamp))) return false;
        $stamp = array_replace(array_fill_keys($keys, null), $stamp);
        if (! is_string($signature) || ! hash_equals(hash_hmac('sha256', json_encode($stamp), config('app.key')), $signature)) return false;
        $current = Workspace::find($workspace);
        return $current && ($stamp['workspace_id'] ?? null) === $current->id && ($stamp['owner_id'] ?? null) === $current->owner_id
            && ($stamp['epoch'] ?? null) === (int) $current->cloud_epoch
            && isset($stamp['expires_at']) && \Illuminate\Support\Carbon::parse($stamp['expires_at'])->isFuture();
    }

    public function transaction(int $workspace, Closure $operation, ?Closure $authority = null): mixed
    {
        return SqlTransactions::run(function () use ($workspace, $operation, $authority) {
            Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
            $stamp = $this->snapshot($workspace);
            if ($authority) $authority();
            $result = $operation();
            abort_unless($this->matches($workspace, $stamp), 403, 'Quyền sử dụng đã thay đổi trước khi hoàn tất. Thao tác chưa được ghi nhận.');
            if ($authority) $authority();
            return $result;
        });
    }

    public function currentOperation(Workspace $workspace, mixed $createdAt, ?int $epoch = null): bool
    {
        $current = Workspace::find($workspace->id);
        return $current && $this->active($current)
            && ($epoch !== null ? $epoch === (int) $current->cloud_epoch
                : (! $current->cloud_resumed_at || $createdAt && \Illuminate\Support\Carbon::parse($createdAt)->gt($current->cloud_resumed_at)));
    }

    public function managedTransaction(\App\Models\User $user, int $room, string $permission, Closure $operation): mixed
    {
        $workspace = $this->requireRoom($room);
        return $this->transaction($workspace->id, $operation, function () use ($user, $room, $permission) {
            app(\App\Support\ScopedRepository::class)->managedRoom($user, $room, $permission);
        });
    }

    public function noticeAttributes(?int $workspace): array
    {
        if (! $this->ready()) {
            abort_if($workspace !== null, 503, 'Thông báo đang chờ cập nhật dữ liệu để xử lý an toàn.');
            return [];
        }
        if ($workspace === null) return ['scope_kind' => 'account'];
        $stamp = $this->snapshot($workspace);
        return ['scope_kind' => 'property', 'workspace_id' => $workspace, 'cloud_epoch' => $stamp['epoch']];
    }

    public function visibleNotifications(Builder $query): Builder
    {
        if (! $this->ready()) return $query->whereRaw('1=0');
        return $query->whereNull('suppressed_at')->where(fn ($q) => $q->where('scope_kind', 'account')
            ->orWhere(fn ($p) => $p->where('scope_kind', 'property')->whereHas('workspace', function ($w) {
                $this->eligible($w)->whereColumn('workspaces.cloud_epoch', 'user_notifications.cloud_epoch');
            })));
    }
}
