<?php

use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\Models\PermissionCategory;
use App\Modules\DataPlatform\Journal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $added=[];
            foreach (['subscription.plan.view','subscription.plan.edit','subscription.plan.publish','subscription.view','subscription.revenue.view','bank_account.delete'] as $code) {
                $definition=config('authorization.catalog')[$code];
                $category=PermissionCategory::firstOrCreate(['name'=>$definition['category']]);
                if (!Permission::where('code',$code)->exists()) {
                    Permission::create(['code'=>$code,'name'=>$definition['name'],'category'=>$definition['category'],'category_id'=>$category->id,'module'=>$definition['module'],'action'=>$definition['action'],'scopes'=>$definition['scopes'],'conditions'=>$definition['condition'],'delegable'=>$definition['delegable'],'active'=>true]);
                    $added[]=$code;
                }
            }
            // Same protected catalog synchronization rule as AccessSeeder; no ordinary group is expanded.
            foreach (AccessGroup::where('protected',true)->get() as $group) {
                if (!$added) break;
                $before=$group->permissions;$group->update(['permissions'=>array_values(array_unique([...$before,...$added]))]);
                app(Journal::class)->record(null,'access.catalog.synced',$group,null,['summary'=>'Bổ sung quyền con quản trị kinh doanh','before'=>$before,'after'=>$group->permissions]);
            }
        });
    }

    public function down(): void { throw new RuntimeException('Không xóa quyền đã được phân công hoặc nhật ký.'); }
};
