<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', fn (Blueprint $t) => $t->unsignedInteger('version')->default(1));
        Schema::create('permission_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('permission_id')->constrained()->restrictOnDelete();
            $t->foreignId('proposer_id')->constrained('users')->restrictOnDelete();
            $t->json('before'); $t->json('after'); $t->json('impact'); $t->json('approvals');
            $t->string('impact_hash', 64); $t->unsignedInteger('expected_version');
            $t->text('reason'); $t->string('status')->default('pending_review');
            $t->timestamp('applied_at')->nullable(); $t->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Giữ nguyên lịch sử chính sách quyền và quyết định phê duyệt.');
    }
};
