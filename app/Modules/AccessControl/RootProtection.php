<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\AccessControl\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RootProtection
{
    public function permanentRoots(): array
    {
        $catalog = Permission::where('active', true)->get();
        $required = $catalog->pluck('code')->all();
        $result = [];
        foreach (User::where('status', 'active')->get() as $user) {
            $groups = GroupAssignment::with('group')->where('user_id', $user->id)->whereNull('workspace_id')->whereNull('property_id')->whereNull('room_id')->whereNull('revoked_at')->whereNull('valid_until')
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))->get()->pluck('group');
            $primary = AccessGroup::find($user->access_group_id);
            if ($primary) {
                $groups->push($primary);
            }
            $codes = PermissionCodes::expand($groups->filter(fn ($g) => $g?->active && in_array($g->scope, ['global', 'personal'], true))->flatMap(fn ($g) => $g->permissions)->all());
            $global = PermissionCodes::expand($groups->filter(fn ($g) => $g?->active && $g->scope === 'global')->flatMap(fn ($g) => $g->permissions)->all());
            $platform = $catalog->filter(fn ($p) => $p->scopes === null || in_array('platform', $p->scopes, true))->pluck('code')->all();
            if (in_array(config('authorization.bootstrap_permission'), $global, true) && ! array_diff($required, $codes) && ! array_diff($platform, $global)) {
                $result[] = $user->id;
            }
        }

        return $result;
    }

    public function mutation(callable $callback): mixed
    {
        return \App\Support\SqlTransactions::run(function () use ($callback) {
            Permission::where('code', config('authorization.bootstrap_permission'))->lockForUpdate()->first();
            $had = $this->permanentRoots() !== [];
            $result = $callback();
            if ($had && $this->permanentRoots() === []) {
                throw ValidationException::withMessages(['permissions' => 'Cần giữ ít nhất một quản trị viên cấp cao đang hoạt động và có đủ quyền.']);
            }

            return $result;
        });
    }
}
