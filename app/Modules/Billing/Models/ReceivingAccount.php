<?php

namespace App\Modules\Billing\Models;

use App\Support\BusinessModel;

class ReceivingAccount extends BusinessModel
{
    protected $hidden = ['account_no', 'account_fingerprint', 'platform_fingerprint'];

    protected function casts(): array
    {
        return parent::casts() + ['account_no' => 'encrypted', 'disabled_at' => 'datetime'];
    }

    public function getMaskedNumberAttribute(): string
    {
        return '•••• '.substr($this->account_no, -4);
    }
}
