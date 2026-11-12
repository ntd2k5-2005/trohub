<?php

namespace App\Modules\Workspace\Models;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Support\BusinessModel;

class Membership extends BusinessModel
{
    protected $table = 'memberships';

    protected static function booted(): void
    {
        static::updated(function ($m) {
            if ($m->wasChanged(['status', 'permissions', 'permissions_mode', 'property_ids', 'room_ids', 'valid_until', 'access_group_id', 'permission_origin', 'source_revision'])) app(\App\Modules\AccessControl\GrantGraph::class)->reconcile();
        });
        static::saving(function ($m) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('memberships', 'current_key')) {
                $m->current_key = $m->status === 'ended' ? null : $m->workspace_id.':'.$m->user_id;
                if (! $m->exists) $m->joined_at ??= now();
            }
        });
    }

    public function group()
    {
        return $this->belongsTo(AccessGroup::class, 'access_group_id');
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
