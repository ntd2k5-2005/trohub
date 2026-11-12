<?php

namespace App\Modules\AccessControl\Models;

use App\Support\BusinessModel;

final class GroupChange extends BusinessModel
{
    protected $table = 'group_changes';

    public function group()
    {
        return $this->belongsTo(AccessGroup::class, 'access_group_id');
    }
}
