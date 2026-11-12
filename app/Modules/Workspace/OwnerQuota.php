<?php

namespace App\Modules\Workspace;

use App\Models\User;
use App\Modules\Property\Models\{Property, Room};
use App\Modules\Workspace\Models\Workspace;
use App\Support\SqlTransactions;

/** Owner row serializes admission across all of that owner's workspaces. */
final class OwnerQuota
{
    public function transaction(int $owner, callable $operation): mixed
    {
        return SqlTransactions::run(function () use ($owner, $operation) {
            User::whereKey($owner)->lockForUpdate()->firstOrFail();
            return $operation();
        });
    }

    public function limits(int $owner): array
    {
        $account = app(\App\Modules\Subscription\AccountSubscriptionService::class)->current($owner);
        if ($account) return $account->planVersion->snapshot['limits'];
        $version=\Illuminate\Support\Facades\DB::table('owner_quota_versions')->where('active_owner_id',$owner)->value('plan_version_id');
        if($version)return \App\Modules\Subscription\Models\PlanVersion::findOrFail($version)->snapshot['limits'];
        $plans = Workspace::where('owner_id', $owner)->whereNull('purged_at')->where('status', '!=', 'pending')->distinct()->pluck('plan');
        return $plans->count() === 1 ? config('business.limits.'.$plans->first(), []) : [];
    }

    public function usage(int $owner, string $kind): int
    {
        $workspaces = Workspace::where('owner_id', $owner)->whereNull('purged_at')->select('id');
        return match ($kind) {
            'workspaces' => Workspace::whereIn('id', $workspaces)->where('lifecycle', '!=', 'archived')->where('status', '!=', 'archived')->count(),
            'properties' => Property::whereIn('workspace_id', $workspaces)->whereNull('retired_at')->count(),
            'rooms' => Room::whereIn('property_id', Property::whereIn('workspace_id', $workspaces)->select('id'))->whereNull('retired_at')->count(),
            'staff' => count(app(StaffCapacity::class)->activeAccounts($owner)),
            default => throw new \InvalidArgumentException('Unknown quota resource'),
        };
    }

    public function admit(int $owner, string $kind, int $additional = 1): void
    {
        $limit = $this->limits($owner)[$kind] ?? null;
        if ($limit === 'unlimited') return;
        abort_unless(is_int($limit) && $limit >= 0, 409, 'Hạn mức cho thao tác này chưa được xác định. Vui lòng liên hệ hỗ trợ để kiểm tra gói dịch vụ.');
        abort_if($this->usage($owner, $kind) + $additional > $limit, 409, 'Đã đạt hạn mức của gói dịch vụ trên các khu quản lý của bạn.');
    }

    public function admitLegacyWorkspace(int $owner,string $initialPlan):void
    {
        abort_if(app(\App\Modules\Subscription\AccountSubscriptionService::class)->current($owner),409,'Hãy tạo khu quản lý từ gói dùng chung của tài khoản.');
        if(Workspace::where('owner_id',$owner)->exists()){$this->admit($owner,'workspaces');return;}
        // Compatibility admission only. No default is inferred for an unconfigured legacy plan.
        $limit=config('business.limits.'.$initialPlan.'.workspaces');
        abort_unless($limit==='unlimited'||is_int($limit)&&$limit>=1,409,'Gói cũ chưa xác định hạn mức khu quản lý. Cần cấu hình gói cấp tài khoản trước khi đăng ký mới.');
    }
}
