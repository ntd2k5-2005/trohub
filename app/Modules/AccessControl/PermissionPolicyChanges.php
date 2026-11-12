<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\{AccessGroup, DirectGrant, GroupAssignment, Permission, PermissionChange};
use App\Modules\DataPlatform\Journal;
use App\Modules\Workspace\Models\{Membership, Workspace};
use Illuminate\Support\Facades\Validator;

/** Policy relaxation is a reviewed change; tightening remains immediately effective. */
final class PermissionPolicyChanges
{
    public function authorize(User $actor): void
    {
        abort_unless(app(AccessService::class)->global($actor, 'access.catalog.edit'), 403);
    }

    public function expanding(Permission $p, array $data): bool
    {
        $nextDepth=array_key_exists('max_depth',$data)?($data['max_depth']??config('authorization.delegation.default_max_depth')):($p->max_depth??config('authorization.delegation.default_max_depth'));
        return $nextDepth > ($p->max_depth ?? config('authorization.delegation.default_max_depth'))
            || ! $p->delegable && (bool) ($data['delegable'] ?? false) || ! $p->active && (bool) ($data['active'] ?? true);
    }

    public function impact(Permission $p, bool $locked = false): array
    {
        $groups = AccessGroup::orderBy('id')->get()->filter(fn ($g) => in_array($p->code, PermissionCodes::expand($g->permissions), true));
        $grants = DirectGrant::orderBy('id')->get();
        $ids = $grants->where('permission', $p->code)->pluck('id')->all();
        do {
            $old = $ids;
            $ids = array_values(array_unique([...$ids, ...$grants->filter(fn ($g) => $g->source_kind === 'grant' && in_array($g->source_id, $ids, true) || $g->delegation_source_kind === 'grant' && in_array($g->delegation_source_id, $ids, true))->pluck('id')->all()]));
        } while ($old !== $ids);
        $affected = $grants->whereIn('id', $ids);
        $assignments = GroupAssignment::whereIn('access_group_id', $groups->pluck('id'))->orderBy('id')->get();
        $memberIds = $assignments->pluck('membership_id')->merge($affected->pluck('membership_id'))->merge($affected->pluck('issuer_membership_id'))->filter()->all();
        $members = Membership::orderBy('id')->get()->filter(fn ($m) => in_array($m->access_group_id, $groups->pluck('id')->all(), true) || in_array($m->id, $memberIds, true) || in_array($p->code, PermissionCodes::expand($m->permissions ?? []), true))->values();
        if (! $locked) {
            Workspace::whereIn('id', $members->pluck('workspace_id')->merge($affected->pluck('workspace_id'))->merge($groups->pluck('workspace_id'))->filter())->orderBy('id')->lockForUpdate()->get();
            return $this->impact($p, true);
        }
        $users = User::where(fn ($q) => $q->whereIn('access_group_id', $groups->pluck('id'))->orWhereIn('id', $assignments->pluck('user_id')->merge($members->pluck('user_id'))->merge($affected->pluck('user_id'))->merge($affected->pluck('grantor_id'))))->orderBy('id')->get();
        return ['policy' => $p->only(['id', 'code', 'name', 'active', 'delegable', 'max_depth', 'version', 'category_id', 'description']),
            'groups' => $groups->map->only(['id', 'version', 'permissions', 'status'])->values()->all(),
            'grants' => $affected->map->only(['id', 'version', 'status', 'depth', 'revoked_at', 'valid_from', 'valid_until', 'source_id', 'delegation_source_id'])->values()->all(),
            'assignments' => $assignments->map->only(['id', 'user_id', 'membership_id', 'property_id', 'room_id', 'revoked_at', 'valid_from', 'valid_until'])->all(),
            'members' => $members->map->only(['id', 'user_id', 'version', 'source_revision', 'status', 'permissions', 'property_ids', 'room_ids', 'valid_until'])->all(),
            'users' => $users->map->only(['id', 'status', 'access_group_id'])->all(),
            'workspaces' => Workspace::whereIn('id', $members->pluck('workspace_id')->merge($affected->pluck('workspace_id'))->filter())->orderBy('id')->get()->map->only(['id', 'owner_id', 'status', 'lifecycle', 'version', 'expires_at', 'cloud_epoch'])->all()];
    }

    private function hash(array $impact, array $after): string { return hash('sha256', json_encode([$impact, $after], JSON_THROW_ON_ERROR)); }

    public function propose(User $actor, Permission $p, array $data): PermissionChange
    {
        return app(RootProtection::class)->mutation(function () use ($actor, $p, $data) {
            $this->authorize($actor);
            $p = Permission::whereKey($p->id)->lockForUpdate()->firstOrFail();
            $reason = Validator::make($data, ['reason' => 'required|string|min:5|max:2000'])->validate()['reason'];
            $after = array_intersect_key($data, array_flip(['name', 'category', 'category_id', 'description', 'active', 'delegable', 'max_depth']));
            abort_unless($this->expanding($p, $after), 409, 'Thay đổi siết quyền được áp dụng ngay theo quy trình hiện hành.');
            $impact = $this->impact($p);
            $change = PermissionChange::create(['permission_id' => $p->id, 'proposer_id' => $actor->id, 'before' => $impact['policy'], 'after' => $after, 'impact' => $impact, 'impact_hash' => $this->hash($impact, $after), 'expected_version' => $p->version, 'reason' => $reason, 'approvals' => []]);
            app(Journal::class)->record($actor->id, 'permission.change.proposed', $change, null, ['summary' => $p->name, 'reason' => $reason, 'before' => $change->before, 'after' => $after]);
            return $change;
        });
    }

    private function current(PermissionChange $change): Permission
    {
        abort_unless($change->status === 'pending_review', 409, 'Đề nghị đã được xử lý.');
        $p = Permission::whereKey($change->permission_id)->lockForUpdate()->firstOrFail();
        abort_unless($p->version === $change->expected_version && hash_equals($change->impact_hash, $this->hash($this->impact($p), $change->after)), 409, 'Chính sách, nguồn quyền hoặc phạm vi đã thay đổi. Hãy xem trước và xin phê duyệt lại.');
        return $p;
    }

    public function canApprove(User $actor,PermissionChange $change):bool
    {
        return $change->status==='pending_review' && $actor->id!==$change->proposer_id && !in_array($actor->id,array_column($change->impact['users'],'id'),true);
    }

    public function approve(User $actor, int $id): void
    {
        app(RootProtection::class)->mutation(function () use ($actor, $id) {
            $this->authorize($actor);
            $change = PermissionChange::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->current($change);
            abort_unless($this->canApprove($actor,$change),403,'Người phê duyệt phải độc lập với người đề nghị và các tài khoản nhận quyền.');
            $approvals = $change->approvals; $approvals[(string) $actor->id] = ['hash' => $change->impact_hash, 'at' => now()->toIso8601String()];
            $change->update(['approvals' => $approvals]);
            app(Journal::class)->record($actor->id, 'permission.change.approved', $change, null, ['summary' => $change->permission->name, 'impact_hash' => $change->impact_hash]);
        });
    }

    public function apply(User $actor, int $id): void
    {
        app(RootProtection::class)->mutation(function () use ($actor, $id) {
            $this->authorize($actor);
            $change = PermissionChange::whereKey($id)->lockForUpdate()->firstOrFail();
            $p = $this->current($change); $count = 0;
            foreach ($change->approvals as $reviewer => $approval) {
                $user = User::find($reviewer);
                if ($user && $this->canApprove($user,$change) && $approval['hash'] === $change->impact_hash && app(AccessService::class)->global($user, 'access.catalog.edit')) $count++;
            }
            abort_unless($count >= config('authorization.group_change.independent_approvals'), 409, 'Chưa đủ người phê duyệt độc lập còn thẩm quyền.');
            $this->authorize(User::findOrFail($change->proposer_id));
            $p->fill($change->after)->save();
            $change->update(['status' => 'applied', 'applied_at' => now()]);
            app(Journal::class)->record($actor->id, 'permission.change.applied', $change, null, ['summary' => $p->name, 'before' => $change->before, 'after' => $change->after, 'approvals' => array_keys($change->approvals)]);
            $this->authorize($actor);
        });
    }
}
