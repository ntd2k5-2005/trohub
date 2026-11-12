<?php

namespace App\Modules\Rental\Models;

use App\Models\User;
use App\Modules\Property\Models\Room;
use App\Support\BusinessModel;

class RentalRequest extends BusinessModel
{
    public function getTitleAttribute():string{return 'Thương lượng thuê · '.$this->room->name;}
    protected static function booted(): void {static::creating(function($r){if(\Illuminate\Support\Facades\Schema::hasColumn('rental_requests','workspace_at_event'))$r->workspace_at_event??=$r->workspace_id;});}
    protected function casts(): array
    {
        return parent::casts() + ['terms_snapshot' => 'array', 'terms_accepted_at' => 'datetime'];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function reservation()
    {
        return $this->hasOne(RoomReservation::class);
    }
}
