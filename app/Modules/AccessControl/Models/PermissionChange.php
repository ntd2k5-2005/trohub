<?php

namespace App\Modules\AccessControl\Models;

use App\Support\BusinessModel;

final class PermissionChange extends BusinessModel
{
    protected $table = 'permission_changes';

    protected function casts(): array { return parent::casts() + ['approvals' => 'array']; }

    public function permission() { return $this->belongsTo(Permission::class); }
}
