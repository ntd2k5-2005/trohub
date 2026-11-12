<?php

namespace App\Modules\Billing\Models;

use App\Support\BusinessModel;

class Payment extends BusinessModel
{
    protected $table = 'payments';

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
