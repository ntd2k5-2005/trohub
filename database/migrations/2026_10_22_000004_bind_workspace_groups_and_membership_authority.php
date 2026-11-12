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
            $t->string('permission_origin')->default('legacy');
            $t->unsignedInteger('source_revision')->default(1);
        });
        Schema::table('group_assignments', fn (Blueprint $t) => $t->string('permission_origin')->default('legacy'));
        Schema::table('direct_grants', function (Blueprint $t) {
            $t->string('origin_kind')->default('direct');
            $t->unsignedBigInteger('origin_id')->nullable();
            $t->index(['origin_kind', 'origin_id']);
        });
        Schema::table('access_groups', fn (Blueprint $t) => $t->unique(['workspace_id', 'source_template_id'], 'workspace_group_template_unique'));
        foreach (DB::table('workspaces')->orderBy('id')->get() as $w) {
            $ids = DB::table('memberships')->where('workspace_id', $w->id)->pluck('access_group_id')
                ->merge(DB::table('group_assignments')->where('workspace_id', $w->id)->pluck('access_group_id'))
                ->merge(DB::table('invitations')->where('workspace_id', $w->id)->pluck('access_group_id'))->filter()->unique();
            foreach (DB::table('invitations')->where('workspace_id', $w->id)->get() as $invite) $ids = $ids->merge(json_decode($invite->access_group_ids ?? '[]', true) ?: [])->unique();
            $map = [];
            foreach (DB::table('access_groups')->whereIn('id', $ids)->whereNull('workspace_id')->where('scope', 'workspace')->get() as $g) {
                $copy = (array) $g; unset($copy['id']);
                $copy['key'] = null; $copy['protected'] = false; $copy['workspace_id'] = $w->id; $copy['source_template_id'] = $g->id;
                $map[$g->id] = DB::table('access_groups')->insertGetId($copy);
                foreach (['memberships', 'group_assignments', 'invitations'] as $table) DB::table($table)->where('workspace_id', $w->id)->where('access_group_id', $g->id)->update(['access_group_id' => $map[$g->id]]);
            }
            foreach (['memberships' => 'requested_group_ids', 'invitations' => 'access_group_ids'] as $table => $column) {
                foreach (DB::table($table)->where('workspace_id', $w->id)->whereNotNull($column)->get() as $row) {
                    $mapped = array_map(fn ($id) => $map[$id] ?? $id, json_decode($row->$column, true) ?: []);
                    DB::table($table)->where('id', $row->id)->update([$column => json_encode($mapped)]);
                }
            }
            foreach (DB::table('direct_grants')->where('workspace_id', $w->id)->get() as $grant) {
                $changes = [];
                foreach (['source_snapshot', 'delegation_snapshot'] as $column) {
                    $snapshot = json_decode($grant->$column ?? 'null', true);
                    if ($snapshot && isset($map[$snapshot['group_id'] ?? null])) {
                        $snapshot['group_id'] = $map[$snapshot['group_id']];
                        $changes[$column] = json_encode($snapshot);
                    }
                }
                if ($changes) DB::table('direct_grants')->where('id', $grant->id)->update($changes);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Không gộp nhóm riêng về nhóm mẫu hoặc xóa nguồn quyền khi rollback.');
    }
};
