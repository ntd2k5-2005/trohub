<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up(): void
    {
        Schema::create('property_moves',function(Blueprint $t){$t->id();$t->foreignId('property_id')->constrained()->restrictOnDelete();$t->foreignId('source_workspace_id')->constrained('workspaces');$t->foreignId('target_workspace_id')->constrained('workspaces');$t->foreignId('proposer_id')->constrained('users');$t->string('reference',100)->unique();$t->json('snapshot');$t->string('snapshot_hash',64);$t->json('blockers');$t->text('reason');$t->string('status');$t->timestamp('completed_at')->nullable();$t->timestamps();});
        foreach(['payment_requests','rental_requests','work_assignments']as$table)Schema::table($table,fn(Blueprint $t)=>$t->foreignId('workspace_at_event')->nullable()->constrained('workspaces')->restrictOnDelete());
        foreach(['payment_requests','rental_requests','work_assignments']as$table)DB::table($table)->whereNull('workspace_at_event')->update(['workspace_at_event'=>DB::raw('workspace_id')]);
        DB::table('permissions')->insertOrIgnore(['code'=>'property.move','name'=>'Chuyển nhà sang khu quản lý khác','module'=>'Property','category'=>'Nhà và phòng','scopes'=>json_encode(['platform','workspace','property']),'delegable'=>true,'active'=>true,'version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $template=DB::table('access_groups')->where('key','owner')->value('id');
        foreach(DB::table('access_groups')->where('protected',true)->orWhere('id',$template)->orWhere('source_template_id',$template)->get()as$g){$codes=json_decode($g->permissions,true);if(!in_array('property.move',$codes,true)){DB::table('access_groups')->where('id',$g->id)->update(['permissions'=>json_encode([...$codes,'property.move'])]);DB::table('audit_entries')->insert(['action'=>'property.move.permission_added','resource_type'=>'access_group','resource_id'=>$g->id,'changes'=>json_encode(['added'=>'property.move']),'created_at'=>now(),'updated_at'=>now()]);}}
    }
    public function down():void{throw new RuntimeException('Không xóa hồ sơ chuyển nhà và phạm vi lịch sử.');}
};
