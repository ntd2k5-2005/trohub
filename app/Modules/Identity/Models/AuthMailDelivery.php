<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;

class AuthMailDelivery extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['payload', 'lease'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'datetime', 'processing_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
