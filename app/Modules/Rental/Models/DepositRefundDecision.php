<?php

namespace App\Modules\Rental\Models;

use App\Support\BusinessModel;

class DepositRefundDecision extends BusinessModel
{
    protected function casts(): array
    {
        return parent::casts() + ['payer_accepted_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }
}
