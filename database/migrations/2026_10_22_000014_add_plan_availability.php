<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{Schema::table('plan_versions',fn(Blueprint$t)=>$t->string('availability')->default('public'));}
    public function down():void{throw new RuntimeException('Không mở lại gói đã ngừng tiếp nhận bằng cách bỏ trạng thái.');}
};
