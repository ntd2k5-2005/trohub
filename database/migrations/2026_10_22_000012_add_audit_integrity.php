<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
    public function up():void
    {
        Schema::table('audit_entries',function(Blueprint$t){$t->unsignedBigInteger('integrity_sequence')->nullable()->unique();$t->string('previous_hash',64)->nullable();$t->string('integrity_hash',64)->nullable();});
        Schema::create('audit_integrity_head',function(Blueprint$t){$t->unsignedInteger('id')->primary();$t->unsignedBigInteger('sequence')->default(0);$t->string('last_hash',64);$t->string('legacy_digest',64);});
        $digest=app(\App\Modules\DataPlatform\AuditIntegrity::class)->legacyDigest();
        DB::table('audit_integrity_head')->insert(['id'=>1,'sequence'=>0,'last_hash'=>$digest,'legacy_digest'=>$digest]);
    }
    public function down():void{throw new RuntimeException('Không xóa chuỗi kiểm định nhật ký.');}
};
