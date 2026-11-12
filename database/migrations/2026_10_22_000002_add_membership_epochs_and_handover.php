<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $t) {
            $t->string('current_key', 100)->nullable()->unique();
            $t->unsignedInteger('version')->default(1);
            $t->json('requested_group_ids')->nullable();
            $t->foreignId('inviter_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('invitation_id')->nullable()->constrained('invitations')->restrictOnDelete();
            $t->timestamp('joined_at')->nullable();
            $t->timestamp('suspended_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->text('suspension_reason')->nullable();
        });
        DB::table('memberships')->orderBy('id')->each(function ($m) {
            DB::table('memberships')->where('id', $m->id)->update([
                'current_key' => $m->status === 'ended' ? null : $m->workspace_id.':'.$m->user_id,
                'joined_at' => $m->created_at,
            ]);
        });
        // InnoDB may use the old composite unique key to support workspace_id's FK.
        // Install its replacement before removing uniqueness across membership epochs.
        Schema::table('memberships', fn (Blueprint $t) => $t->index('workspace_id', 'memberships_workspace_history_idx'));
        Schema::table('memberships', fn (Blueprint $t) => $t->dropUnique(['workspace_id', 'user_id']));
        foreach (['direct_grants', 'group_assignments'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('membership_id')->nullable()->constrained('memberships')->restrictOnDelete());
            DB::table($table)->whereNotNull('workspace_id')->orderBy('id')->each(function ($row) use ($table) {
                if ($table === 'group_assignments' && $row->origin === 'ownership') return;
                $membership = DB::table('memberships')->where('workspace_id', $row->workspace_id)->where('user_id', $row->user_id)->value('id');
                if ($membership) DB::table($table)->where('id', $row->id)->update(['membership_id' => $membership]);
            });
        }
        Schema::create('membership_handovers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $t->foreignId('membership_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('status')->default('pending');
            $t->text('reason');
            $t->json('snapshot');
            $t->timestamps();
        });
        Schema::create('work_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $t->foreignId('membership_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('handover_id')->nullable()->constrained('membership_handovers')->restrictOnDelete();
            $t->string('resource_type', 80);
            $t->unsignedBigInteger('resource_id');
            $t->string('permission');
            $t->string('current_key', 120)->nullable()->unique();
            $t->string('status')->default('open');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['membership_id', 'status']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Giữ Membership và lịch sử bàn giao; không gộp các lần tham gia khi rollback.');
    }
};
