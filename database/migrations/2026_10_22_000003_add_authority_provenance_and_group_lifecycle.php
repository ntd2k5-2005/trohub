<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', fn (Blueprint $t) => $t->unsignedSmallInteger('max_depth')->nullable());
        Schema::table('access_groups', function (Blueprint $t) {
            $t->string('status')->default('active');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('source_template_id')->nullable()->constrained('access_groups')->restrictOnDelete();
        });
        DB::table('access_groups')->where('active', false)->update(['status' => 'disabled']);
        Schema::table('direct_grants', function (Blueprint $t) {
            $t->foreignId('issuer_membership_id')->nullable()->constrained('memberships')->restrictOnDelete();
            $t->string('authority_kind')->default('legacy');
            $t->string('source_kind')->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->json('source_snapshot')->nullable();
            $t->string('delegation_source_kind')->nullable();
            $t->unsignedBigInteger('delegation_source_id')->nullable();
            $t->json('delegation_snapshot')->nullable();
            $t->unsignedSmallInteger('depth')->default(1);
            $t->unsignedInteger('version')->default(1);
            $t->string('status')->default('active');
            $t->string('revocation_reason')->nullable();
            $t->index(['source_kind', 'source_id']);
            $t->index(['delegation_source_kind', 'delegation_source_id'], 'grants_delegate_source_idx');
        });
        DB::table('direct_grants')->whereNotNull('revoked_at')->update(['status' => 'revoked']);
        Schema::create('group_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('access_group_id')->constrained()->restrictOnDelete();
            $t->foreignId('proposer_id')->constrained('users')->restrictOnDelete();
            $t->json('before');
            $t->json('after');
            $t->json('impact');
            $t->string('impact_hash', 64);
            $t->unsignedInteger('expected_version');
            $t->unsignedSmallInteger('required_approvals');
            $t->string('status')->default('pending_review');
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
        });
        Schema::create('group_change_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_change_id')->constrained()->restrictOnDelete();
            $t->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $t->string('impact_hash', 64);
            $t->timestamps();
            $t->unique(['group_change_id', 'reviewer_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không xóa nguồn quyền, quyết định duyệt hoặc lịch sử chuỗi ủy quyền khi rollback.');
    }
};
