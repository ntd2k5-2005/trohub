<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\DataPlatform\Journal;

final class AccountGroups
{
    public function assign(User $actor, User $target, array $ids, ?string $until = null): void
    {
        abort_if($actor->id === $target->id, 403, 'Không thể tự thay đổi quyền của tài khoản đang sử dụng.');
        app(RootProtection::class)->mutation(function () use ($actor, $target, $ids, $until) {
            abort_unless(app(AccessService::class)->global($actor, 'access.assign'), 403);
            $target = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $groups = AccessGroup::whereIn('id', $ids)->where('active', true)->where('status', 'active')->whereIn('scope', ['global', 'personal'])->whereNull('workspace_id')->get();
            abort_unless($groups->count() === count(array_unique($ids)), 403);
            $old = AccessGroup::whereKey($target->access_group_id)->get()->merge(app(AccessService::class)->assignments($target)->whereNull('workspace_id')->pluck('group'));
            app(DelegationPolicy::class)->global($actor, PermissionCodes::expand($old->flatMap(fn ($g) => $g->permissions)->all()), now()->toDateTimeString());
            app(DelegationPolicy::class)->global($actor, PermissionCodes::expand($groups->flatMap(fn ($g) => $g->permissions)->all()), $until);
            $target->forceFill(['access_group_id' => null])->save();
            GroupAssignment::where('user_id', $target->id)->whereNull('workspace_id')->whereNull('revoked_at')->update(['revoked_at' => now()]);
            app(GrantGraph::class)->reconcile();
            foreach ($groups as $group) {
                GroupAssignment::create(['user_id' => $target->id, 'access_group_id' => $group->id, 'scope_key' => ($group->scope === 'personal' ? 'personal' : 'platform').':'.\Illuminate\Support\Str::uuid(), 'grantor_id' => $actor->id, 'valid_from' => now(), 'valid_until' => $until, 'origin' => 'explicit']);
            }
            app(Journal::class)->record($actor->id, 'account.group', $target, null, ['summary' => 'Cập nhật các nhóm quyền của '.$target->email, 'before' => $old->pluck('id')->all(), 'after' => $ids]);
        });
    }
}
