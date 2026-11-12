<?php

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;

class EmailChallenge extends Model
{
    protected $table = 'email_verification_challenges';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['token_hash', 'otp_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
