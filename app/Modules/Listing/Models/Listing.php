<?php

namespace App\Modules\Listing\Models;

use App\Modules\Property\Models\Room;
use App\Support\BusinessModel;

class Listing extends BusinessModel
{
    protected $table = 'listings';

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
