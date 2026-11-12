<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plan_versions',function(Blueprint $t){$t->timestamp('published_at')->nullable();$t->foreignId('published_by')->nullable()->constrained('users');$t->foreignId('predecessor_id')->nullable()->constrained('plan_versions')->restrictOnDelete();});
        Schema::create('owner_quota_versions',function(Blueprint $t){$t->id();$t->foreignId('owner_id')->constrained('users');$t->foreignId('active_owner_id')->nullable()->unique()->constrained('users');$t->foreignId('plan_version_id')->constrained()->restrictOnDelete();$t->foreignId('assigned_by')->constrained('users');$t->text('reason');$t->timestamp('ended_at')->nullable();$t->timestamps();});
        $category=DB::table('permission_categories')->where('name','Gói dịch vụ')->value('id');
        if(!$category)$category=DB::table('permission_categories')->insertGetId(['name'=>'Gói dịch vụ','active'=>true,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('permissions')->insertOrIgnore(['code'=>'subscription.plan.manage','name'=>'Quản lý phiên bản gói dịch vụ','module'=>'Subscription','category'=>'Gói dịch vụ','category_id'=>$category,'scopes'=>json_encode(['platform']),'delegable'=>false,'active'=>true,'version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        foreach(DB::table('access_groups')->where('protected',true)->get() as $g){$codes=json_decode($g->permissions,true);if(!in_array('subscription.plan.manage',$codes,true)){DB::table('access_groups')->where('id',$g->id)->update(['permissions'=>json_encode([...$codes,'subscription.plan.manage'])]);DB::table('audit_entries')->insert(['action'=>'plan.management.permission_added','resource_type'=>'access_group','resource_id'=>$g->id,'changes'=>json_encode(['added'=>'subscription.plan.manage']),'created_at'=>now(),'updated_at'=>now()]);}}
    }
    public function down(): void{throw new RuntimeException('Không xóa phiên bản và lịch sử cấp hạn mức.');}
};
