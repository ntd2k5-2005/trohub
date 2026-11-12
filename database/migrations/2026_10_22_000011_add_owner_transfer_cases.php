<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up():void
    {
        Schema::create('owner_transfer_cases',function(Blueprint$t){$t->id();$t->foreignId('workspace_id')->constrained()->restrictOnDelete();$t->foreignId('source_owner_id')->constrained('users');$t->foreignId('recipient_id')->constrained('users');$t->string('reference',100)->unique();$t->text('reason');$t->string('status')->default('pending_consent');$t->unsignedInteger('version')->default(1);$t->unsignedInteger('workspace_version');$t->string('terms_hash',64);$t->json('consents');$t->json('reviews');$t->json('blockers');$t->timestamp('expires_at');$t->timestamps();});
        Schema::create('owner_transfer_evidence',function(Blueprint$t){$t->id();$t->foreignId('owner_transfer_case_id')->constrained()->restrictOnDelete();$t->foreignId('uploaded_by')->constrained('users');$t->unsignedInteger('version');$t->string('path');$t->string('filename');$t->string('mime');$t->unsignedBigInteger('bytes');$t->string('checksum',64);$t->timestamps();$t->unique(['owner_transfer_case_id','version']);});
        DB::table('permissions')->insertOrIgnore(['code'=>'workspace.transfer.review','name'=>'Thẩm tra hồ sơ bàn giao quyền quản lý','module'=>'Workspace','category'=>'Quản trị nền tảng','scopes'=>json_encode(['platform']),'delegable'=>false,'active'=>true,'version'=>1,'created_at'=>now(),'updated_at'=>now()]);
        foreach(DB::table('access_groups')->where('protected',true)->get()as$g){$codes=json_decode($g->permissions,true);if(!in_array('workspace.transfer.review',$codes,true)){DB::table('access_groups')->where('id',$g->id)->update(['permissions'=>json_encode([...$codes,'workspace.transfer.review'])]);DB::table('audit_entries')->insert(['action'=>'ownership.review.permission_added','resource_type'=>'access_group','resource_id'=>$g->id,'changes'=>json_encode(['added'=>'workspace.transfer.review']),'created_at'=>now(),'updated_at'=>now()]);}}
    }
    public function down():void{throw new RuntimeException('Giữ nguyên consent, chứng cứ và lịch sử thẩm tra.');}
};
