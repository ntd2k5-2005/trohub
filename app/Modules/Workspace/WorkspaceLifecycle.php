<?php

namespace App\Modules\Workspace;

use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\DataPlatform\Journal;
use App\Modules\Workspace\Models\{Workspace, WorkspaceRestriction};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class WorkspaceLifecycle
{
    public function unrestricted(Builder $query): Builder
    {
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('workspace_restrictions')->whereColumn('workspace_id', 'workspaces.id')->whereNull('released_at'));
    }

    public function requireAdmission(Workspace $w): void
    {
        $w = $w->fresh();
        app(\App\Modules\Subscription\PropertyCloud::class)->requireActive($w);
        abort_unless(($w->lifecycle ?? 'active') === 'active', 409, 'Khu quản lý đang tạm dừng tiếp nhận mới. Các nghĩa vụ hiện có vẫn được xử lý.');
    }

    public function transition(User $actor, Workspace $workspace, string $target, int $version, string $reason): void
    {
        abort_unless(trim($reason) !== '' && in_array($target, ['active', 'paused', 'archived'], true), 422);
        app(OwnerQuota::class)->transaction($workspace->owner_id, function () use ($actor, $workspace, $target, $version, $reason) {
            $w = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AccessService::class)->canManageLifecycle($actor, $w), 403);
            abort_unless($w->version === $version, 409, 'Khu quản lý đã thay đổi. Vui lòng tải lại trước khi tiếp tục.');
            $state = $w->status === 'archived' ? 'archived' : $w->lifecycle;
            abort_if($target === $state, 409, 'Trạng thái này đã được áp dụng.');
            if ($target === 'archived') {
                app(WorkspaceArchive::class)->archive($actor, $w, $reason);
                return;
            }
            abort_unless($this->unrestricted(Workspace::query())->whereKey($w->id)->exists() && ! $w->purged_at && $w->owner->status === 'active' && $w->expires_at->isFuture(), 409, 'Cần gói dịch vụ còn hiệu lực và xử lý các hạn chế trước khi đổi trạng thái.');
            $account = app(\App\Modules\Subscription\AccountSubscriptionService::class)->current($w->owner_id);
            abort_if($account && (! in_array($account->status, ['active', 'trial'], true) || $account->expires_at->lte(now())) || DB::table('account_restrictions')->where('owner_id', $w->owner_id)->whereNull('released_at')->exists(), 409, 'Cần xử lý hạn chế hoặc gói dịch vụ cấp tài khoản trước.');
            abort_unless(in_array($state, ['active', 'paused', 'draft', 'archived'], true) && ($target === 'active' || $state === 'active'), 409);
            if ($target === 'active') {
                app(OwnerQuota::class)->admit($w->owner_id, 'workspaces', $state === 'archived' ? 1 : 0);
                if ($state === 'archived') app(\App\Modules\Rental\ObligationGuard::class)->settled($w->properties()->with('rooms')->get()->flatMap->rooms->pluck('id')->all());
            }
            $this->suppressPending($w);
            $w->update(['lifecycle' => $target, 'status' => $w->plan === 'trial' ? 'trial' : 'active', 'lifecycle_reason' => $reason, 'version' => $w->version + 1,
                'cloud_epoch' => $state === 'archived' ? $w->cloud_epoch + 1 : $w->cloud_epoch]);
            app(Journal::class)->record($actor->id, 'workspace.lifecycle.changed', $w, $w->id, ['before' => $state, 'after' => $target, 'reason' => $reason, 'members_restored' => false, 'listings_published' => false]);
            abort_unless(app(AccessService::class)->canManageLifecycle($actor, $w->fresh()), 403);
        });
    }

    public function suppressPending(Workspace $w): void
    {
        $rooms = DB::table('rooms')->whereIn('property_id', $w->properties()->select('id'))->select('id');
        DB::table('listings')->whereIn('room_id', $rooms)->where('status', 'published')->update(['status' => 'paused']);
        DB::table('domain_events')->where('workspace_id', $w->id)->whereNull('projected_at')->update(['projected_at' => now(), 'last_error' => 'RC12_SUPPRESSED']);
    }

    public function restrict(User $actor, Workspace $workspace, string $cause, string $reason): WorkspaceRestriction
    {
        abort_unless(app(AccessService::class)->global($actor, 'system.configure'), 403);
        return app(OwnerQuota::class)->transaction($workspace->owner_id, function () use ($actor, $workspace, $cause, $reason) {
            abort_unless(trim($reason) && preg_match('/^[a-z0-9_.-]{1,80}$/', $cause), 422);
            $w = Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $existing = WorkspaceRestriction::where('active_key', $w->id.':'.$cause)->first();
            if ($existing) return $existing;
            $restriction = WorkspaceRestriction::create(['workspace_id' => $w->id, 'cause' => $cause, 'active_key' => $w->id.':'.$cause, 'reason' => $reason, 'created_by' => $actor->id]);
            $w->update(['cloud_epoch' => $w->cloud_epoch + 1, 'version' => $w->version + 1, 'cloud_cutoff_at' => now()]);
            $this->suppressPending($w);
            app(Journal::class)->record($actor->id, 'workspace.restricted', $restriction, $w->id, ['reason' => $reason, 'cause' => $cause]);
            abort_unless(app(AccessService::class)->global($actor, 'system.configure'), 403);
            return $restriction;
        });
    }

    public function release(User $actor, int $id, string $reason): void
    {
        abort_unless(app(AccessService::class)->global($actor, 'system.configure') && trim($reason) !== '', 403);
        $r = WorkspaceRestriction::findOrFail($id);
        app(OwnerQuota::class)->transaction($r->workspace->owner_id, function () use ($actor, $r, $reason) {
            Workspace::whereKey($r->workspace_id)->lockForUpdate()->firstOrFail();
            $r = WorkspaceRestriction::whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($r->released_at) return;
            $r->update(['released_at' => now(), 'released_by' => $actor->id, 'active_key' => null]);
            app(Journal::class)->record($actor->id, 'workspace.restriction.released', $r, $r->workspace_id, ['reason' => $reason]);
        });
    }
}
