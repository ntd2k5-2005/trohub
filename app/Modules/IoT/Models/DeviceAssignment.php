<?php

namespace App\Modules\IoT\Models;

use App\Modules\Property\Models\Room;
use App\Support\BusinessModel;

class DeviceAssignment extends BusinessModel
{
    protected $table = 'device_assignments';

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
