<?php

namespace App\Modules\Rental\Models;

use App\Models\User;
use App\Support\BusinessModel;

class Occupant extends BusinessModel
{
    protected $table = 'occupants';

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
