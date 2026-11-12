<?php

namespace App\Modules\Maintenance\Models;

use App\Modules\Property\Models\Room;
use App\Support\BusinessModel;

class MaintenanceRequest extends BusinessModel
{
    protected $table = 'maintenance_requests';

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
