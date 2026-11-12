<?php

namespace App\Modules\Notification\Models;

use App\Support\BusinessModel;

class UserNotification extends BusinessModel
{
    protected $table = 'user_notifications';

    protected static function booted(): void
    {
        static::addGlobalScope('property_cloud', fn ($query) => app(\App\Modules\Subscription\PropertyCloud::class)->visibleNotifications($query));
    }

    public function workspace()
    {
        return $this->belongsTo(\App\Modules\Workspace\Models\Workspace::class);
    }
}
