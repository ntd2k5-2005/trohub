<?php

namespace App\Modules\Workspace;

use App\Models\User;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Support\SqlTransactions;
use Closure;

/** Counts accounts, never invitations or membership rows. No payment-period reconciliation. */
final class StaffCapacity
{
    public function transaction(Workspace $workspace, Closure $operation): mixed
    {
        return SqlTransactions::run(function () use ($workspace, $operation) {
            User::whereKey($workspace->owner_id)->lockForUpdate()->firstOrFail();
            $current = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->owner_id === $workspace->owner_id, 409, 'Bên quản lý vừa thay đổi. Vui lòng tải lại.');
            return $operation($current);
        });
    }

    public function activeAccounts(int $owner): array
    {
        return Membership::whereHas('workspace', fn ($w) => $w->where('owner_id', $owner)->whereNull('purged_at'))
            ->where('user_id', '!=', $owner)->where('status', 'active')
            ->distinct()->pluck('user_id')->all();
    }

    public function limit(int $owner): int|string|null
    {
        // Only an unambiguous existing plan can supply a transitional quota.
        // Mixed legacy plans must be reconciled explicitly, not max/min/summed.
        $value = app(OwnerQuota::class)->limits($owner)['staff'] ?? null;
        if($value==='unlimited')return $value;
        return is_int($value) && $value >= 0 ? $value : null;
    }

    public function available(Workspace $workspace, int $user): bool
    {
        if ($user === $workspace->owner_id) return false;
        $accounts = $this->activeAccounts($workspace->owner_id);
        if (in_array($user, $accounts, true)) return true;
        $limit = $this->limit($workspace->owner_id);
        return $limit === 'unlimited' || is_int($limit) && count($accounts) < $limit;
    }
}
