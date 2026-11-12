<?php

namespace App\Modules\IoT\Models;

use App\Modules\Property\Models\Room;
use App\Support\BusinessModel;

class SafetyEvent extends BusinessModel
{
    protected $table = 'safety_events';

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function device()
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
