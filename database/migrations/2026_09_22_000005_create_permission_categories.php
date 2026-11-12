<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        foreach (DB::table('permissions')->distinct()->pluck('category') as $name) {
            DB::table('permission_categories')->insert(['name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_categories');
    }
};
