<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $category = DB::table('permission_categories')->where('name', 'Cá nhân')->value('id');
        if (! $category) $category = DB::table('permission_categories')->insertGetId(['name' => 'Cá nhân', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->insertOrIgnore(['code' => 'membership.leave', 'name' => 'Rời khu quản lý của mình', 'module' => 'Workspace', 'category' => 'Cá nhân', 'category_id' => $category, 'scopes' => json_encode(['personal']), 'delegable' => false, 'active' => true, 'description' => 'Chỉ kết thúc lần tham gia của chính mình, bảo toàn công việc và nghĩa vụ.', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('access_groups')->where('key', 'ordinary')->orWhere('protected', true)->get() as $group) {
            $codes = json_decode($group->permissions, true) ?: [];
            if (! in_array('membership.leave', $codes, true)) {
                $codes[] = 'membership.leave';
                DB::table('access_groups')->where('id', $group->id)->update(['permissions' => json_encode($codes), 'updated_at' => now()]);
                DB::table('audit_entries')->insert(['action' => 'membership.leave.permission_added', 'resource_type' => 'access_group', 'resource_id' => $group->id, 'changes' => json_encode(['added' => 'membership.leave']), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Không xóa quyền đã dùng hoặc lịch sử rời khu quản lý.');
    }
};
