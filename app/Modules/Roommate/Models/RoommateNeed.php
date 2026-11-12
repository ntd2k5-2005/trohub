<?php

namespace App\Modules\Roommate\Models;

use App\Modules\Property\Models\Room;
use App\Modules\Subscription\PropertyCloud;
use App\Support\BusinessModel;
use Illuminate\Database\Eloquent\Builder;

class RoommateNeed extends BusinessModel
{
    protected $table = 'roommate_needs';

    protected function casts(): array
    {
        return parent::casts() + ['budget' => 'integer', 'people_needed' => 'integer', 'duration_months' => 'integer'];
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function scopeCloudAccessible(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('room_id')->orWhereHas('room.property.workspace', function ($w) {
            $cloud = app(PropertyCloud::class);
            $cloud->eligible($w);
            if ($cloud->ready()) {
                $w->where(fn ($period) => $period->whereNull('cloud_resumed_at')->orWhereColumn('roommate_needs.updated_at', '>=', 'workspaces.cloud_resumed_at'));
            }
        }));
    }
}
