<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void{Schema::table('users',fn(Blueprint$t)=>$t->unsignedInteger('auth_version')->default(0));}
    public function down():void{throw new RuntimeException('Không phục hồi các phiên đã bị thu hồi.');}
};
