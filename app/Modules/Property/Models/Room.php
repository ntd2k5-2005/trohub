<?php

namespace App\Modules\Property\Models;

use App\Modules\Listing\Models\Listing;
use App\Modules\Rental\Models\Rental;
use App\Support\BusinessModel;

class Room extends BusinessModel
{
    protected $table = 'rooms';

    protected static function booted(): void
    {
        static::updating(function ($r) { if (\Illuminate\Support\Facades\Schema::hasColumn('rooms', 'version')) $r->version = (int) ($r->getOriginal('version') ?? 1) + 1; });
        static::updated(function ($room) {
            if ($room->wasChanged('property_id')) app(\App\Modules\AccessControl\GrantGraph::class)->reconcile();
        });
    }

    public function property()
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    public function rentals()
    {
        return $this->hasMany(Rental::class, 'room_id');
    }

    public function listings()
    {
        return $this->hasMany(Listing::class, 'room_id');
    }
}
