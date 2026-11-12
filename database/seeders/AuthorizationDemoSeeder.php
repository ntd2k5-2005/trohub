<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\AccessControl\BootstrapAdmin;
use App\Modules\AccessControl\GroupAssignments;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\AccessControl\RootProtection;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Models\Rental;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Seeder;

final class AuthorizationDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Không khởi tạo tài khoản trình diễn trên môi trường thực tế.');
        }
        User::whereIn('id', Rental::whereNotNull('tenant_id')->select('tenant_id'))->each(fn ($u) => app(GroupAssignments::class)->tenant($u));
        if (! app(RootProtection::class)->permanentRoots()) {
            app(BootstrapAdmin::class)->create(config('authorization.demo_supreme_email'), config('business_history.display_names')[config('authorization.demo_supreme_email')][1]??'Ngô Tấn Đạt', config('demo.password'));
        }
        $normal = AccessGroup::where('key', 'ordinary')->firstOrFail();
        $w = Workspace::where('owner_id', User::where('email', 'owner@trohub.test')->value('id'))->orderBy('id')->firstOrFail();
        $rooms = Room::whereHas('property', fn ($q) => $q->where('workspace_id', $w->id))->where('status', 'vacant')->orderBy('id')->get();
        foreach (['user' => 'Người dùng trình diễn', 'multi' => 'Nhân viên kiêm kế toán', 'grant' => 'Nhân viên có quyền bổ sung', 'roomstaff' => 'Kỹ thuật viên theo phòng', 'expired' => 'Nhân viên hết quyền'] as $key => $name) {
            $u = User::firstOrCreate(['email' => $key.'@trohub.test'], ['name' => (config('business_history.display_names')[$key.'@trohub.test'][1]??$name), 'password' => config('demo.password'), 'access_group_id' => $normal->id]);
            if ($key === 'user') {
                continue;
            }
            $groupKey = ['multi' => 'manager', 'grant' => 'custom', 'roomstaff' => 'technician', 'expired' => 'custom'][$key];
            $g = AccessGroup::where('key', $groupKey)->firstOrFail();
            $g = app(\App\Modules\AccessControl\WorkspaceGroupCopies::class)->resolve($w, $g->id);
            $accounting = app(\App\Modules\AccessControl\WorkspaceGroupCopies::class)->resolve($w, (int) AccessGroup::where('key', 'accountant')->value('id'));
            $membership = Membership::where('status', '!=', 'ended')->firstOrCreate(['workspace_id' => $w->id, 'user_id' => $u->id], ['access_group_id' => $g->id, 'status' => 'active', 'property_ids' => [$rooms->first()->property_id], 'room_ids' => $key === 'roomstaff' ? [$rooms->first()->id] : null]);
            if ($key === 'multi') {
                GroupAssignment::firstOrCreate(['user_id' => $u->id, 'access_group_id' => $accounting->id, 'scope_key' => 'workspace:'.$w->id.':membership:'.$membership->id], ['workspace_id' => $w->id, 'membership_id' => $membership->id]);
            }
            if (in_array($key, ['grant', 'expired'], true)) {
                DirectGrant::firstOrCreate(['user_id' => $u->id, 'workspace_id' => $w->id, 'membership_id' => $membership->id, 'permission' => 'invoice.view', 'reason' => 'Trình diễn quyền riêng: '.$key], ['grantor_id' => $w->owner_id, 'property_id' => $rooms->first()->property_id, 'valid_from' => now()->subDays(2), 'valid_until' => $key === 'expired' ? now()->subDay() : now()->addYear()]);
            }
            if ($key === 'expired') {
                GroupAssignment::firstOrCreate(['user_id' => $u->id, 'access_group_id' => $accounting->id, 'scope_key' => 'workspace:'.$w->id.':membership:'.$membership->id], ['workspace_id' => $w->id, 'membership_id' => $membership->id, 'valid_until' => now()->subDay()]);
            }
        }
    }
}
