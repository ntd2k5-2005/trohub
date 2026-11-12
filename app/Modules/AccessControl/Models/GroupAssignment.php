<?php

namespace App\Modules\AccessControl\Models;

use App\Models\User;
use App\Support\BusinessModel;

final class GroupAssignment extends BusinessModel
{
    protected $table = 'group_assignments';

    protected static function booted(): void
    {
        static::updated(fn () => app(\App\Modules\AccessControl\GrantGraph::class)->reconcile());
        static::creating(function ($assignment) {
            if ($assignment->workspace_id && $assignment->origin !== 'ownership' && \Illuminate\Support\Facades\Schema::hasColumn('group_assignments', 'membership_id')) {
                $assignment->membership_id ??= \App\Modules\Workspace\Models\Membership::where('workspace_id', $assignment->workspace_id)->where('user_id', $assignment->user_id)->where('status', '!=', 'ended')->value('id');
            }
        });
    }

    public function group()
    {
        return $this->belongsTo(AccessGroup::class, 'access_group_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
