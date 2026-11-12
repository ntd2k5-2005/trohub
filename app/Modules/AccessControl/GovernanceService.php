<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\Models\PermissionCategory;
use App\Modules\DataPlatform\Journal;
use Illuminate\Validation\ValidationException;

final class GovernanceService
{
    public function execute(User $u, string $action, int $id, array $data): void
    {
        $access = app(AccessService::class);
        $permission = app(ActionAuthorizer::class)->permission($action, ['id' => $id] + $data);
        abort_unless($permission && $access->global($u, $permission), 403);
        app(RootProtection::class)->mutation(function () use ($u, $action, $id, $data, $access) {
            $before = [];
            switch ($action) {
                case 'account.status':
                    $record = User::whereKey($id)->lockForUpdate()->firstOrFail();
                    abort_unless(app(AccountPolicy::class)->canChangeStatus($u, $record), 403);
                    $before = $record->only('status');
                    $record->forceFill(['status' => $data['status']])->save();
                    break;
                case 'permission.save':
                    $existing = Permission::where('code', $data['code'])->first();
                    if (in_array($data['code'], array_keys(config('authorization.legacy')), true) && ($data['active'] ?? true)) {
                        throw ValidationException::withMessages(['active' => 'Quyền cũ đã được thay bằng các thao tác cụ thể.']);
                    }
                    if (in_array($data['code'], config('authorization.critical_permissions'), true) && ! ($data['active'] ?? true)) {
                        throw ValidationException::withMessages(['active' => 'Không thể ngừng quyền cần thiết để duy trì quản trị phân quyền.']);
                    }
                    $data['category_id'] = PermissionCategory::where('name', $data['category'])->where('active', true)->firstOrFail()->id;
                    if ($existing && app(PermissionPolicyChanges::class)->expanding($existing, $data)) {
                        app(PermissionPolicyChanges::class)->propose($u, $existing, $data);
                        return;
                    }
                    $before = $existing?->only(['name', 'category_id', 'active', 'delegable', 'description']) ?? [];
                    $data['category_id'] = PermissionCategory::where('name', $data['category'])->where('active', true)->firstOrFail()->id;
                    $record = Permission::updateOrCreate(['code' => $data['code']], array_diff_key($data, ['reason' => true]));
                    if (! $existing && $access->global($u, config('authorization.bootstrap_permission'))) {
                        // Creating catalog entries also initializes finite protected-group authority, with audit.
                        foreach (AccessGroup::where('protected', true)->get() as $g) {
                            $g->update(['permissions' => array_values(array_unique([...$g->permissions, $record->code]))]);
                        }
                    }
                    break;
                case 'category.save':
                    if (PermissionCategory::where('name', $data['name'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                        throw ValidationException::withMessages(['name' => 'Tên danh mục đã được sử dụng.']);
                    }
                    $record = $id ? PermissionCategory::findOrFail($id) : new PermissionCategory;
                    $before = $record->only('name');
                    $record->fill($data)->save();
                    Permission::where('category_id', $record->id)->update(['category' => $record->name]);
                    break;
                case 'group.save':
                    $record = $id ? AccessGroup::whereKey($id)->lockForUpdate()->firstOrFail() : new AccessGroup;
                    abort_if($record->workspace_id !== null, 403);
                    $data['permissions'] = PermissionCodes::expand($data['permissions']);
                    app(DelegationPolicy::class)->global($u, array_unique([...($record->permissions ?? []), ...$data['permissions']]));
                    if (! ($data['active'] ?? true)) {
                        abort_unless($access->global($u, 'access.group.disable'), 403);
                    }
                    abort_if($id && $record->scope !== $data['scope'], 422);
                    if ($data['scope'] === 'workspace' && $record->key !== config('authorization.owner_group')) {
                        abort_if(Permission::whereIn('code', $data['permissions'])->where('delegable', false)->exists(), 403);
                    }
                    if (AccessGroup::where('name', $data['name'])->where('scope', $data['scope'])->whereNull('workspace_id')->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                        throw ValidationException::withMessages(['name' => 'Tên nhóm đã được sử dụng trong phạm vi này.']);
                    }
                    if ($id) {
                        app(GroupChangeService::class)->propose($u, $record, $data);
                        return;
                    }
                    $before = $record->only(['name', 'permissions', 'active']);
                    $record->fill($data)->save();
                    break;
                case 'account.group':
                    $target = User::findOrFail($data['user_id']);
                    app(AccountGroups::class)->assign($u, $target, $data['access_group_ids'] ?? [$data['access_group_id']], $data['valid_until'] ?? null);

                    return;
                case 'global.grant':
                    app(AccountGrantService::class)->issue($u, $data);
                    return;
                default:abort(404);
            }
            app(Journal::class)->record($u->id, $action, $record, null, ['summary' => $record->name ?? $data['reason'] ?? 'Đã cập nhật quyền.', 'before' => $before, 'after' => array_intersect_key($data, array_flip(['name', 'scope', 'permissions', 'access_group_id', 'access_group_ids', 'user_id', 'permission', 'valid_from', 'valid_until', 'reason', 'status', 'code', 'category', 'delegable', 'active', 'description']))]);
        });
    }
}
