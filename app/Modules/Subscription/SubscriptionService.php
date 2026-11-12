<?php

namespace App\Modules\Subscription;

use App\Contracts\Authorization;
use App\Contracts\Authorization as AccessService;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Subscription\Models\SubscriptionOrder;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubscriptionService
{
    public function __construct(private Journal $journal, private AccessService $access) {}

    public function startPaid(User $user, array $data): Workspace
    {
        abort_unless(app(Authorization::class)->personal($user, 'workspace.create'), 403);

        return DB::transaction(function () use ($user, $data) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            app(\App\Modules\Workspace\OwnerQuota::class)->admitLegacyWorkspace($user->id,$data['plan']);
            $workspace = Workspace::create(['owner_id' => $user->id, 'name' => $data['name'], 'plan' => $data['plan'], 'status' => 'pending', 'expires_at' => now(), 'provenance' => 'production']);
            $order = SubscriptionOrder::create(['workspace_id' => $workspace->id, 'plan' => $data['plan'], 'reference' => $data['reference']]);
            $this->journal->record($user->id, 'subscription.requested', $order, $workspace->id);

            return $workspace;
        });
    }

    public function order(User $user, Workspace $workspace, array $data): void
    {
        abort_if(app(AccountSubscriptionService::class)->current($workspace->owner_id), 409, 'Vui lòng dùng báo giá gói cấp tài khoản.');
        $this->access->authorize($user, 'subscription.manage', $workspace);
        DB::transaction(function () use ($workspace, $data, $user) {
            $order = SubscriptionOrder::create($data + ['workspace_id' => $workspace->id]);
            $this->journal->record($user->id, 'subscription.requested', $order, $workspace->id);
        });
    }

    public function trial(User $user, string $name): Workspace
    {
        abort_unless(app(Authorization::class)->personal($user, 'workspace.create'), 403);

        return DB::transaction(function () use ($user, $name) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (Workspace::where('owner_id', $user->id)->exists() || \App\Modules\Subscription\Models\AccountSubscription::where('owner_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'Bạn đã sử dụng quyền dùng thử. Hãy gia hạn không gian hiện có.']);
            }
            app(\App\Modules\Workspace\OwnerQuota::class)->admitLegacyWorkspace($user->id,'trial');
            $workspace = Workspace::create(['owner_id' => $user->id, 'name' => $name, 'plan' => 'trial', 'status' => 'trial', 'provenance' => 'production', 'expires_at' => now()->addDays(config('subscriptions.trial_days')), 'retention_until' => null]);
            $this->journal->record($user->id, 'subscription.trial_started', $workspace, $workspace->id);

            return $workspace;
        });
    }

    public function confirm(User $admin, SubscriptionOrder $order): void
    {
        abort_unless($this->access->global($admin, 'subscription.approve'), 403);
        DB::transaction(function () use ($admin, $order) {
            $workspace = Workspace::whereKey($order->workspace_id)->lockForUpdate()->firstOrFail();
            $order = SubscriptionOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'pending') {
                return;
            }
            abort(409, 'Yêu cầu này chưa có báo giá và bằng chứng số tiền xác lập. Cần đối chiếu hồ sơ trước khi xác nhận thanh toán, không tự chuyển kỳ gói.');

        });
    }

    public function expire(): void
    {
        Workspace::whereNull('purged_at')->whereNotIn('status', ['pending', 'archived'])->where('expires_at', '<=', now())->each(function ($candidate) {
            DB::transaction(function () use ($candidate) {
                $w = Workspace::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if ($w->expires_at->isFuture()) {
                    return;
                }
                if ($w->status !== 'suspended' || ! $w->cloud_cutoff_at || $w->cloud_resumed_at && $w->cloud_resumed_at->gt($w->cloud_cutoff_at)) {
                    abort_unless(app(PropertyCloud::class)->ready(), 503, 'Cần cập nhật dữ liệu trước khi xử lý hết hạn.');
                    $w->update(['status' => 'suspended', 'cloud_epoch' => $w->cloud_epoch + 1, 'cloud_cutoff_at' => now()]);
                    DB::table('user_notifications')->where('workspace_id', $w->id)->whereNull('suppressed_at')->update(['suppressed_at' => now()]);
                    $this->journal->record(null, 'subscription.expired', $w, $w->id);
                    $this->journal->notify($w->owner_id, 'Gói dịch vụ đã hết hạn', 'Vui lòng kiểm tra gói dịch vụ để tiếp tục sử dụng các chức năng quản lý.');
                }
                DB::table('listings')->whereIn('room_id', DB::table('rooms')->whereIn('property_id', $w->properties()->select('id'))->select('id'))->where('status', 'published')->update(['status' => 'paused']);
            });
        });
    }
}
