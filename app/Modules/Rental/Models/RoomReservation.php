<?php

namespace App\Modules\Rental\Models;

use App\Modules\Property\Models\Room;
use App\Support\BusinessModel;

class RoomReservation extends BusinessModel
{
    protected function casts(): array
    {
        return parent::casts() + ['policy_snapshot' => 'array', 'hold_until' => 'datetime', 'exception_until' => 'datetime'];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function request()
    {
        return $this->belongsTo(RentalRequest::class, 'rental_request_id');
    }
}
