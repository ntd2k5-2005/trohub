<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\AccessControl\GroupAssignments;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\Models\PermissionCategory;
use App\Modules\AccessControl\PermissionCodes;
use App\Modules\DataPlatform\Journal;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class AccessSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $added = [];
            foreach (config('authorization.catalog') as $code => $p) {
                $category = PermissionCategory::firstOrCreate(['name' => $p['category']]);
                $record = Permission::where('code', $code)->first();
                if (! $record) {
                    $added[] = $code;
                }
                if (! $record || ! $record->module) {
                    Permission::updateOrCreate(['code' => $code], ['name' => $p['name'], 'category' => $p['category'], 'category_id' => $category->id, 'module' => $p['module'], 'action' => $p['action'], 'scopes' => $p['scopes'], 'conditions' => $p['condition'], 'delegable' => $p['delegable'], 'active' => true]);
                } else {
                    $record->update(['module' => $p['module'], 'action' => $p['action'], 'scopes' => $p['scopes'], 'conditions' => $p['condition']]);
                }
            }
            foreach (config('authorization.legacy') as $code => $leaves) {
                $p = config('authorization.catalog')[$leaves[0]];
                $category = PermissionCategory::where('name', $p['category'])->firstOrFail();
                Permission::firstOrCreate(['code' => $code], ['name' => $p['name'], 'category' => $p['category'], 'category_id' => $category->id, 'module' => $p['module'], 'action' => 'legacy', 'scopes' => $p['scopes'], 'delegable' => true, 'active' => false]);
            }
            Permission::whereIn('code', array_keys(config('authorization.legacy')))->update(['active' => false]);
            foreach (config('authorization.groups') as $key => $defaults) {
                $g = AccessGroup::where('key', $key)->first() ?? AccessGroup::where('name', $defaults['name'])->whereNull('key')->whereNull('workspace_id')->first();
                if (! $g) {
                    $g = new AccessGroup;
                }
                // Defaults are initialized once; subsequent runs preserve configured group edits.
                if (! $g->key) {
                    $g->fill($defaults + ['active' => true]);
                }
                $g->key = $key;
                $g->permissions = PermissionCodes::expand($g->permissions ?? []);
                $g->save();
            }
            if ($added) {
                foreach (AccessGroup::where('protected', true)->get() as $group) {
                    $before = $group->permissions;
                    $after = array_values(array_unique([...$before, ...$added]));
                    if ($before !== $after) {
                        $group->update(['permissions' => $after]);
                        app(Journal::class)->record(null, 'access.catalog.synced', $group, null, ['summary' => 'Đồng bộ danh mục quyền quản trị', 'before' => $before, 'after' => $after]);
                    }
                }
            }
            $assignments = app(GroupAssignments::class);
            User::orderBy('id')->each(fn ($u) => $assignments->defaults($u));
            Workspace::whereNull('purged_at')->each(fn ($w) => $assignments->owner($w));
            User::whereIn('id', DB::table('rentals')->whereNotNull('tenant_id')->select('tenant_id'))->each(fn ($u) => $assignments->tenant($u));
        });
    }
}
