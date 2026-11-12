<?php

namespace App\Modules\Subscription\Models;

use App\Support\BusinessModel;

final class LegacySubscriptionReview extends BusinessModel
{
    protected $table = 'legacy_subscription_reviews';

    protected function casts(): array
    {
        return parent::casts() + ['snapshot' => 'array'];
    }
}
