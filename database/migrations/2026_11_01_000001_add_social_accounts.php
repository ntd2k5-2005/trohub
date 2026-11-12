<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('password_login_enabled')->default(true);
        });
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $id = $table->string('provider_user_id', 191);
            if (DB::getDriverName() === 'mysql') {
                $id->collation('utf8mb4_bin');
            }
            $table->string('email')->nullable();
            $table->string('name', 100)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('password_login_enabled'));
    }
};
