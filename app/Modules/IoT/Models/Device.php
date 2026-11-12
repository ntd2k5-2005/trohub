<?php

namespace App\Modules\IoT\Models;

use App\Support\BusinessModel;

class Device extends BusinessModel
{
    protected $table = 'devices';

    public function assignments()
    {
        return $this->hasMany(DeviceAssignment::class, 'device_id');
    }

    public function configs()
    {
        return $this->hasMany(DeviceConfig::class, 'device_id');
    }
}
