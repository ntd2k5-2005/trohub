<?php

namespace App\Modules\Rental\Models;

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Property\Models\Room;
use App\Support\BusinessModel;

class Rental extends BusinessModel
{
    protected $table = 'rentals';

    public function resident()
    {
        return $this->belongsTo(ResidentProfile::class, 'resident_profile_id');
    }

    protected function casts(): array
    {
        return array_diff_key(parent::casts(), ['terms' => true]) + ['signed_at' => 'datetime', 'residency_started_at' => 'datetime'];
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function tenant()
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'rental_id');
    }

    public function occupants()
    {
        return $this->hasMany(Occupant::class, 'rental_id');
    }

    public function versions()
    {
        return $this->hasMany(ContractVersion::class, 'rental_id');
    }

    public function deposits()
    {
        return $this->hasMany(Deposit::class, 'rental_id');
    }
}
