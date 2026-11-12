<?php

namespace App\Modules\Connection\Models;

use App\Models\User;
use App\Modules\Listing\Models\Listing;
use App\Support\BusinessModel;

class Connection extends BusinessModel
{
    protected $table = 'connections';

    public function listing()
    {
        return $this->belongsTo(Listing::class, 'listing_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
