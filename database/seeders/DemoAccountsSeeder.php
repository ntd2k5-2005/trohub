<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo accounts are only available in local/testing environments.');
        }

        DB::transaction(function () {
            $owner = User::where('email', 'owner@trohub.test')->firstOrFail();
            $workspace = Workspace::where('owner_id', $owner->id)->where('provenance', 'synthetic')->firstOrFail();
            $property = $workspace->properties()->firstOrFail();
            $ordinary = AccessGroup::where('key', config('authorization.registration_group'))->firstOrFail();
            foreach (config('demo.accounts') as $key => $account) {
                $group = AccessGroup::where('name', $account['group'])->whereNull('workspace_id')->firstOrFail();
                $user = User::firstOrNew(['email' => $key.'@trohub.test']);
                if (! $user->exists) {
                    $user->name = $account['name'];
                }
                if (! $user->exists || ! Hash::check(config('demo.password'), $user->password)) {
                    $user->password = config('demo.password');
                    $user->remember_token = null;
                }
                $user->forceFill(['status' => 'active', 'access_group_id' => $group->scope !== 'workspace' ? $group->id : $ordinary->id])->save();
                if ($group->scope === 'workspace') {
                    $group = app(\App\Modules\AccessControl\WorkspaceGroupCopies::class)->resolve($workspace, $group->id);
                    Membership::where('status', '!=', 'ended')->firstOrCreate(['workspace_id' => $workspace->id, 'user_id' => $user->id], [
                        'access_group_id' => $group->id, 'status' => 'active', 'property_ids' => [$property->id], 'valid_until' => null,
                    ]);
                }
            }
        });
    }
}
