<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\AccessControl\Models\GroupChange;
use App\Modules\DataPlatform\Journal;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;

/** Preview and approval are tied to the exact current authority and affected sources. */
final class GroupChangeService
{
    private function lockedGroup(int $id): AccessGroup
    {
        $workspace = AccessGroup::whereKey($id)->value('workspace_id');
        if ($workspace) Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
        return AccessGroup::whereKey($id)->lockForUpdate()->firstOrFail();
    }
    public function authorize(User $actor, AccessGroup $group, array $codes, bool $emergency = false, ?string $targetStatus = null): void
    {
        $access = app(AccessService::class);
        if ($group->workspace_id) {
            $w = Workspace::findOrFail($group->workspace_id);
            app(\App\Modules\Subscription\PropertyCloud::class)->requireActive($w);
            $member = $access->member($actor, $w);
            app(DelegationPolicy::class)->workspace($actor, $w, ['member.group.update', ...($emergency ? [] : $codes)], $member?->property_ids, $member?->valid_until?->toDateTimeString(), $member?->room_ids);
            foreach ($this->members($group) as $target) {
                app(DelegationPolicy::class)->workspace($actor, $w, ['member.group.update', ...($emergency ? [] : $codes)], $target->property_ids, $target->valid_until?->toDateTimeString(), $target->room_ids);
            }
            // Ownership/scoped assignments may exist without a staff membership.
            foreach (GroupAssignment::where('access_group_id', $group->id)->whereNull('revoked_at')->get() as $a) {
                app(DelegationPolicy::class)->workspace($actor, $w, ['member.group.update', ...($emergency ? [] : $codes)], $a->property_id ? [$a->property_id] : null, $a->valid_until?->toDateTimeString(), $a->room_id ? [$a->room_id] : null);
            }
        } else {
            abort_unless($access->global($actor, $emergency ? 'access.group.disable' : 'access.group.update'), 403);
            if ($targetStatus === 'disabled') abort_unless($access->global($actor, 'access.group.disable'), 403);
            if (! $emergency) app(DelegationPolicy::class)->global($actor, $codes);
        }
    }

    private function members(AccessGroup $g)
    {
        return Membership::where('access_group_id', $g->id)->where('status', '!=', 'ended')->orderBy('id')->get();
    }

    public function impact(AccessGroup $g): array
    {
        $assignments = GroupAssignment::where('access_group_id', $g->id)->orderBy('id')->lockForUpdate()->get();
        $members = Membership::where(fn ($q) => $q->where('access_group_id', $g->id)->orWhereIn('id', $assignments->pluck('membership_id')->filter()))->orderBy('id')->lockForUpdate()->get();
        $grants = DirectGrant::when($g->workspace_id, fn ($q) => $q->where('workspace_id', $g->workspace_id))->orderBy('id')->lockForUpdate()->get();
        return ['group' => $g->only(['id', 'name', 'permissions', 'status', 'version']),
            'members' => $members->map->only(['id', 'user_id', 'status', 'version', 'source_revision', 'permissions', 'property_ids', 'room_ids', 'valid_until'])->all(),
            'assignments' => $assignments->map->only(['id', 'user_id', 'membership_id', 'property_id', 'room_id', 'valid_from', 'valid_until', 'revoked_at'])->all(),
            'primary_users' => User::where('access_group_id', $g->id)->orderBy('id')->lockForUpdate()->get(['id', 'status'])->toArray(),
            'grants' => $grants->map->only(['id', 'version', 'status', 'revoked_at'])->all()];
    }

    private function hash(array $impact, array $after): string
    {
        return hash('sha256', json_encode([$impact, $after], JSON_THROW_ON_ERROR));
    }

    public function propose(User $actor, AccessGroup $group, array $data): GroupChange
    {
        return app(RootProtection::class)->mutation(function () use ($actor, $group, $data) {
            $g = $this->lockedGroup($group->id);
            $after = ['name' => $data['name'], 'permissions' => PermissionCodes::expand($data['permissions']), 'status' => $data['status'] ?? ((bool) ($data['active'] ?? true) ? 'active' : 'disabled')];
            abort_unless(in_array($after['status'], ['active', 'retired', 'disabled'], true), 422);
            abort_if($g->status === 'retired' && $after['status'] !== 'active' && array_diff($after['permissions'], $g->permissions), 409, 'Nhóm đã ngừng mở rộng không nhận thêm quyền.');
            $this->authorize($actor, $g, array_values(array_unique([...$g->permissions, ...$after['permissions']])), targetStatus: $after['status']);
            // Check the last-root invariant before creating a proposal, then roll back the preview mutation.
            DB::beginTransaction();
            try {
                $hadRoots = app(RootProtection::class)->permanentRoots() !== [];
                $g->fill($after + ['active' => $after['status'] !== 'disabled'])->saveQuietly();
                if ($hadRoots && app(RootProtection::class)->permanentRoots() === []) throw \Illuminate\Validation\ValidationException::withMessages(['permissions' => 'Cần duy trì quản trị viên cấp cao còn đầy đủ quyền.']);
            } finally { DB::rollBack(); }
            $g = $g->fresh();
            $impact = $this->impact($g);
            $added = array_diff($after['permissions'], $g->permissions);
            $high = ($g->status === 'disabled' && $after['status'] !== 'disabled') || ($g->status === 'retired' && $after['status'] === 'active')
                || (bool) array_intersect($added, config('authorization.group_change.high_risk_permissions', []))
                || ($g->scope === 'global' && $after['permissions'] !== $g->permissions);
            $change = GroupChange::create(['workspace_id' => $g->workspace_id, 'access_group_id' => $g->id, 'proposer_id' => $actor->id, 'before' => $g->only(['name', 'permissions', 'status']), 'after' => $after, 'impact' => $impact, 'impact_hash' => $this->hash($impact, $after), 'expected_version' => $g->version, 'required_approvals' => $high ? config('authorization.group_change.independent_approvals', 2) : 0]);
            app(Journal::class)->record($actor->id, 'group.change.proposed', $change, $g->workspace_id, ['summary' => $g->name, 'required_approvals' => $change->required_approvals]);
            return $change;
        });
    }

    private function current(GroupChange $change): AccessGroup
    {
        abort_unless($change->status === 'pending_review', 409, 'Đề nghị này đã được xử lý.');
        $g = $this->lockedGroup($change->access_group_id);
        abort_unless($g->version === $change->expected_version && hash_equals($change->impact_hash, $this->hash($this->impact($g), $change->after)), 409, 'Quyền hoặc người bị ảnh hưởng đã thay đổi. Hãy tạo bản xem trước mới.');
        return $g;
    }

    private function independent(User $actor, GroupChange $change): bool
    {
        $affected = [...array_column($change->impact['members'], 'user_id'), ...array_column($change->impact['assignments'], 'user_id'), ...array_column($change->impact['primary_users'], 'id')];
        return $actor->id !== $change->proposer_id && ! in_array($actor->id, $affected, true);
    }

    public function canApprove(User $actor, GroupChange $change): bool
    {
        return $change->status === 'pending_review' && $this->independent($actor, $change);
    }

    public function approve(User $actor, int $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $change = GroupChange::whereKey($id)->lockForUpdate()->firstOrFail();
            $g = $this->current($change);
            abort_unless($this->independent($actor, $change), 403, 'Cần người phê duyệt độc lập với người đề nghị và người nhận quyền.');
            $this->authorize($actor, $g, array_unique([...$g->permissions, ...$change->after['permissions']]), targetStatus: $change->after['status']);
            DB::table('group_change_approvals')->insertOrIgnore(['group_change_id' => $id, 'reviewer_id' => $actor->id, 'impact_hash' => $change->impact_hash, 'created_at' => now(), 'updated_at' => now()]);
            app(Journal::class)->record($actor->id, 'group.change.approved', $change, $g->workspace_id, ['summary' => $g->name]);
        });
    }

    public function apply(User $actor, int $id): void
    {
        app(RootProtection::class)->mutation(function () use ($actor, $id) {
            $change = GroupChange::whereKey($id)->lockForUpdate()->firstOrFail();
            $g = $this->current($change);
            $codes = array_unique([...$g->permissions, ...$change->after['permissions']]);
            $this->authorize($actor, $g, $codes, targetStatus: $change->after['status']);
            $count = 0;
            foreach (DB::table('group_change_approvals')->where('group_change_id', $id)->where('impact_hash', $change->impact_hash)->get() as $approval) {
                $reviewer = User::find($approval->reviewer_id);
                if (! $reviewer || ! $this->independent($reviewer, $change)) continue;
                try { $this->authorize($reviewer, $g, $codes, targetStatus: $change->after['status']); $count++; }
                catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if ($e->getStatusCode() !== 403) throw $e; }
            }
            abort_unless($count >= $change->required_approvals, 409, 'Chưa đủ người phê duyệt độc lập còn thẩm quyền.');
            $g->fill($change->after + ['version' => $g->version + 1])->save();
            app(MembershipAuthority::class)->expandGroup($actor, $g, array_values(array_diff($change->after['permissions'], $change->before['permissions'])));
            $change->update(['status' => 'applied', 'applied_at' => now()]);
            app(Journal::class)->record($actor->id, 'group.change.applied', $change, $g->workspace_id, ['summary' => $g->name, 'before' => $change->before, 'after' => $change->after]);
        });
    }

    public function disable(User $actor, int $id, string $reason): void
    {
        abort_unless(trim($reason) !== '', 422);
        app(RootProtection::class)->mutation(function () use ($actor, $id, $reason) {
            $g = $this->lockedGroup($id);
            $this->authorize($actor, $g, [], true);
            $g->update(['status' => 'disabled', 'version' => $g->version + 1]);
            app(Journal::class)->record($actor->id, 'group.emergency_disabled', $g, $g->workspace_id, ['summary' => $g->name, 'reason' => $reason, 'follow_up_required' => true]);
        });
    }
}
