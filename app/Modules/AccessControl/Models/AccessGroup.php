<?php

namespace App\Modules\AccessControl\Models;

use App\Support\BusinessModel;

class AccessGroup extends BusinessModel
{
    protected $table = 'access_groups';
    protected $fillable = ['key', 'name', 'scope', 'permissions', 'active', 'status', 'version', 'source_template_id', 'workspace_id', 'protected'];

    protected static function booted(): void
    {
        static::deleting(function ($g) {
            $used = $g->protected || \App\Models\User::where('access_group_id', $g->id)->exists()
                || \App\Modules\Workspace\Models\Membership::where('access_group_id', $g->id)->exists()
                || GroupAssignment::where('access_group_id', $g->id)->exists()
                || GroupChange::where('access_group_id', $g->id)->exists()
                || \App\Modules\Workspace\Models\Invitation::where('access_group_id', $g->id)->exists()
                || \App\Modules\DataPlatform\Models\AuditEntry::where('resource_type', $g->getMorphClass())->where('resource_id', $g->id)->exists()
                || DirectGrant::where('source_snapshot->group_id', $g->id)->orWhere('delegation_snapshot->group_id', $g->id)->exists();
            abort_if($used, 409, 'Nhóm đã có hoạt động. Hãy ngừng mở rộng hoặc tạm ngừng để giữ lịch sử.');
        });
        static::saving(function ($g) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn('access_groups', 'status')) return;
            if ($g->isDirty('status')) $g->active = $g->status !== 'disabled';
            elseif ($g->isDirty('active')) $g->status = $g->active ? 'active' : 'disabled';
        });
        static::updated(function ($g) {
            if ($g->wasChanged(['permissions', 'status', 'active'])) app(\App\Modules\AccessControl\GrantGraph::class)->reconcile();
        });
    }
}
