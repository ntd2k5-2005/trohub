<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $t) {
            $t->foreignId('category_id')->nullable()->constrained('permission_categories')->restrictOnDelete();
        });
        foreach (DB::table('permission_categories')->get() as $category) {
            DB::table('permissions')->where('category', $category->name)->update(['category_id' => $category->id]);
        }
        foreach (config('permission_categories') as $name => $codes) {
            $id = DB::table('permission_categories')->where('name', $name)->value('id');
            if (!$id) $id = DB::table('permission_categories')->insertGetId(['name'=>$name,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);
            DB::table('permissions')->whereIn('code', $codes)->update(['category_id'=>$id,'category'=>$name]);
        }
        foreach (['memberships', 'invitations'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->json('permissions')->nullable());
        }
        Schema::table('invitations', function (Blueprint $t) {
            $t->foreignId('inviter_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('valid_until')->nullable();
        });
        DB::table('permissions')->where('code', 'member.manage')->update(['delegable' => true]);
        if (!DB::table('access_groups')->where('name', 'Tùy chỉnh')->where('scope', 'workspace')->exists()) {
            DB::table('access_groups')->insert(['name'=>'Tùy chỉnh', 'scope'=>'workspace', 'permissions'=>'[]', 'active'=>true, 'created_at'=>now(), 'updated_at'=>now()]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->where('code', 'member.manage')->update(['delegable' => false]);
        Schema::table('invitations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('inviter_id');
            $t->dropColumn('valid_until');
        });
        foreach (['memberships', 'invitations'] as $table) Schema::table($table, fn (Blueprint $t) => $t->dropColumn('permissions'));
        Schema::table('permissions', fn (Blueprint $t) => $t->dropConstrainedForeignId('category_id'));
    }
};
