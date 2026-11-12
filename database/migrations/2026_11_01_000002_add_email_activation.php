<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('email_activation_required')->default(false));
        Schema::create('email_verification_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->string('email');
            $table->string('token_hash', 64);
            $table->string('otp_hash');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('auth_mail_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('challenge_id')->nullable()->index();
            $table->string('purpose', 32);
            $table->string('status', 32)->default('pending');
            $table->string('acceptance', 40)->nullable();
            $table->text('payload')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->uuid('lease')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_mail_deliveries');
        Schema::dropIfExists('email_verification_challenges');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_activation_required'));
    }
};
