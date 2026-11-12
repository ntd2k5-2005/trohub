<?php

namespace App\Modules\Workspace\Models;

use App\Models\User;
use App\Modules\Property\Models\Property;
use App\Support\BusinessModel;

class Workspace extends BusinessModel
{
    protected $table = 'workspaces';

    public function getEffectiveStatusAttribute(): string
    {
        if ($this->status === 'archived') return 'archived';
        if (in_array($this->lifecycle, ['draft', 'paused', 'archived'], true)) return $this->lifecycle;
        if ($this->status === 'pending') {
            return 'pending';
        }
        if ($this->purged_at) {
            return $this->status;
        }
        if ($this->expires_at->isFuture()) {
            return $this->status;
        }

        return 'suspended';
    }

    public function properties()
    {
        return $this->hasMany(Property::class, 'workspace_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
