<?php

namespace App\Modules\Subscription\Models;

use App\Support\BusinessModel;

final class SubscriptionQuote extends BusinessModel
{
    protected $table = 'subscription_quotes';

    protected function casts(): array
    {
        return parent::casts() + ['payee_snapshot' => 'encrypted:array', 'payer_reported_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function planVersion()
    {
        return $this->belongsTo(PlanVersion::class);
    }

    public function accountSubscription()
    {
        return $this->belongsTo(AccountSubscription::class);
    }
}
