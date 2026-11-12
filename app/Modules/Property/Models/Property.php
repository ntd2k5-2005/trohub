<?php

namespace App\Modules\Property\Models;

use App\Modules\Workspace\Models\Workspace;
use App\Support\BusinessModel;

class Property extends BusinessModel
{
    protected $table = 'properties';

    protected static function booted(): void
    {
        static::updating(function ($p) { if (\Illuminate\Support\Facades\Schema::hasColumn('properties', 'version')) $p->version = (int) ($p->getOriginal('version') ?? 1) + 1; });
        static::updated(function ($property) {
            if ($property->wasChanged('workspace_id')) app(\App\Modules\AccessControl\GrantGraph::class)->reconcile();
        });
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function rooms()
    {
        return $this->hasMany(Room::class, 'property_id');
    }
}
