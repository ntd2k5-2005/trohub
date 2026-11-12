<?php

namespace App\Modules\Workspace;

use App\Contracts\Authorization as AccessService;
use App\Models\User;
use App\Modules\AccessControl\DelegationPolicy;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\AccessControl\PermissionCodes;
use App\Modules\DataPlatform\Journal;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WorkspaceGroups
{
    public function save(User $actor, Workspace $workspace, int $id, array $data): void
    {
        $access = app(AccessService::class);
        $cap = $id ? 'member.group.update' : 'member.group.create';
        abort_unless($access->allowed($actor, $cap, $workspace) || $access->roomIds($actor, $workspace, $cap) !== [], 403);
        DB::transaction(function () use ($actor, $workspace, $id, $data, $access) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $group = $id ? AccessGroup::where('workspace_id', $workspace->id)->where('scope', 'workspace')->lockForUpdate()->findOrFail($id) : new AccessGroup(['workspace_id' => $workspace->id, 'scope' => 'workspace']);
            $codes = PermissionCodes::expand($data['permissions']);
            abort_if(array_diff($codes, array_keys(app(DelegationPolicy::class)->options($actor, $workspace))) !== [], 403);
            $member = $access->member($actor, $workspace);
            app(DelegationPolicy::class)->workspace($actor, $workspace, array_unique([...($group->permissions ?? []), ...$codes]), $member?->property_ids, $member?->valid_until?->toDateTimeString(), $member?->room_ids);
            if ($id) {
                $targets = Membership::where('workspace_id', $workspace->id)->where('status', 'active')->where(fn ($q) => $q->where('access_group_id', $id)->orWhereIn('user_id', GroupAssignment::where('workspace_id', $workspace->id)->where('access_group_id', $id)->whereNull('revoked_at')->select('user_id')))->get();
                foreach ($targets as $target) {
                    app(DelegationPolicy::class)->workspace($actor, $workspace, array_unique([...($group->permissions ?? []), ...$codes]), $target->property_ids, $target->valid_until?->toDateTimeString(), $target->room_ids);
                }
            }
            if (AccessGroup::where('workspace_id', $workspace->id)->where('name', $data['name'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                throw ValidationException::withMessages(['name' => 'Tên nhóm đã được sử dụng tại khu quản lý này.']);
            }
            if ($id) {
                app(\App\Modules\AccessControl\GroupChangeService::class)->propose($actor, $group, $data);
                return;
            }
            $before = $group->only(['name', 'permissions', 'active']);
            $group->fill(['name' => $data['name'], 'permissions' => $codes, 'active' => $data['active'] ?? true])->save();
            app(Journal::class)->record($actor->id, 'workspace.group.save', $group, $workspace->id, ['summary' => $group->name, 'before' => $before, 'after' => $group->only(['name', 'permissions', 'active'])]);
        });
    }
}
