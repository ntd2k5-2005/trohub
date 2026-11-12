<?php

namespace App\Modules\AccessControl\Models;

use App\Support\BusinessModel;

class Permission extends BusinessModel
{
    protected $table = 'permissions';
    protected $fillable = ['code', 'name', 'category', 'category_id', 'module', 'action', 'scopes', 'conditions', 'description', 'delegable', 'active', 'max_depth', 'version'];

    protected static function booted(): void
    {
        static::updating(function ($p) {
            if ($p->isDirty(['name', 'category_id', 'description', 'active', 'delegable', 'max_depth']) && \Illuminate\Support\Facades\Schema::hasColumn('permissions', 'version')) $p->version = (int) ($p->getOriginal('version') ?? 1) + 1;
        });
        static::updated(function ($p) {
            if ($p->wasChanged(['active', 'delegable', 'max_depth'])) app(\App\Modules\AccessControl\GrantGraph::class)->reconcile();
        });
    }

    public function categoryRecord()
    {
        return $this->belongsTo(PermissionCategory::class, 'category_id');
    }
}
