<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\Workspace\Models\Workspace;

final class GroupAssignments
{
    public function defaults(User $user): void
    {
        $g = AccessGroup::where('key', config('authorization.registration_group'))->first();
        if ($g) {
            GroupAssignment::firstOrCreate(['user_id' => $user->id, 'access_group_id' => $g->id, 'scope_key' => 'personal'], ['origin' => 'registration']);
        }
    }

    public function owner(Workspace $workspace): void
    {
        $g = AccessGroup::where('key', config('authorization.owner_group'))->first();
        if ($g) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('access_groups', 'source_template_id')) $g = app(WorkspaceGroupCopies::class)->resolve($workspace, $g->id, false);
            GroupAssignment::firstOrCreate(['user_id' => $workspace->owner_id, 'access_group_id' => $g->id, 'scope_key' => 'workspace:'.$workspace->id], ['workspace_id' => $workspace->id, 'origin' => 'ownership']);
        }
    }

    public function tenant(User $user): void
    {
        $g = AccessGroup::where('key', config('authorization.tenant_group'))->first();
        if ($g) {
            GroupAssignment::firstOrCreate(['user_id' => $user->id, 'access_group_id' => $g->id, 'scope_key' => 'personal'], ['origin' => 'rental']);
        }
    }

    public function workspace(User $actor, Workspace $workspace, User $target, array $ids, ?string $until): void
    {
        $membership = \App\Modules\Workspace\Models\Membership::where('workspace_id', $workspace->id)->where('user_id', $target->id)->where('status', 'active')->firstOrFail();
        $ids = array_map(function ($id) use ($workspace, $membership) {
            $group = AccessGroup::findOrFail($id);
            if ($group->status !== 'active' || ! $group->active) {
                abort_unless($group->workspace_id === $workspace->id && GroupAssignment::where('membership_id', $membership->id)->where('access_group_id', $group->id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->exists(), 403);
                return $group->id;
            }
            return app(WorkspaceGroupCopies::class)->resolve($workspace, (int) $id)->id;
        }, $ids);
        $groups = AccessGroup::whereIn('id', $ids)->where('scope', 'workspace')->where('workspace_id', $workspace->id)->get();
        abort_unless($groups->count() === count(array_unique($ids)), 403);
        $current = GroupAssignment::where('membership_id', $membership->id)->whereNull('property_id')->whereNull('room_id')->where('origin', 'explicit');
        $current->whereNotIn('access_group_id', $ids)->update(['revoked_at' => now()]);
        foreach ($groups as $g) {
            $record = GroupAssignment::where('membership_id', $membership->id)->where('access_group_id', $g->id)->whereNull('property_id')->whereNull('room_id')->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->first();
            if ($g->status !== 'active' || ! $g->active) { abort_unless($record, 403); continue; }
            if ($record) $record->update(['valid_until' => $until]);
            else $record = GroupAssignment::create(['user_id' => $target->id, 'access_group_id' => $g->id, 'scope_key' => 'workspace:'.$workspace->id.':membership:'.$membership->id.':'.\Illuminate\Support\Str::uuid(), 'membership_id' => $membership->id, 'workspace_id' => $workspace->id, 'grantor_id' => $actor->id, 'valid_until' => $until]);
            app(MembershipAuthority::class)->bind($actor, $membership, $record);
        }
        app(GrantGraph::class)->reconcile();
    }
}
