<?php

namespace App\Modules\Workspace\Models;

use App\Support\BusinessModel;

final class WorkAssignment extends BusinessModel
{
    protected static function booted(): void {static::creating(function($r){if(\Illuminate\Support\Facades\Schema::hasColumn('work_assignments','workspace_at_event'))$r->workspace_at_event??=$r->workspace_id;});}
    public function membership()
    {
        return $this->belongsTo(Membership::class);
    }
}
