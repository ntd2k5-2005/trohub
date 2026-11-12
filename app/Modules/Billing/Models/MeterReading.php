<?php

namespace App\Modules\Billing\Models;

use App\Modules\Property\Models\Room;
use App\Support\BusinessModel;

class MeterReading extends BusinessModel
{
    protected $table = 'meter_readings';

    protected function casts(): array
    {
        // The shared monetary attribute name must not shadow this relationship.
        return array_diff_key(parent::casts(), ['rate' => true]);
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function rate()
    {
        return $this->belongsTo(ServiceRate::class, 'service_rate_id');
    }
}
