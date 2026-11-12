<?php

namespace App\Modules\Subscription;

use App\Contracts\Authorization;
use App\Models\User;

/** Existing registry and effective authority; no role/email bypass. */
final class BusinessAccess
{
    public function can(User $actor, string $ability): bool
    {
        $codes = match ($ability) {
            'plans.view' => ['subscription.plan.view', 'subscription.plan.edit', 'subscription.plan.publish', 'subscription.plan.manage'],
            'plans.edit' => ['subscription.plan.edit', 'subscription.plan.manage'],
            'plans.publish' => ['subscription.plan.publish', 'subscription.plan.manage'],
            'subscriptions.view' => ['subscription.view', 'subscription.approve'],
            'payments.verify' => ['payment.verify', 'subscription.approve'],
            'payments.view' => ['payment.view', 'payment.verify', 'subscription.approve'],
            'orders.view' => ['subscription.view', 'payment.view', 'payment.verify', 'subscription.approve', 'subscription.revenue.view'],
            default => [$ability],
        };
        return collect($codes)->contains(fn ($code) => app(Authorization::class)->global($actor, $code));
    }

    public function authorize(User $actor, string $ability): void
    {
        abort_unless($this->can($actor, $ability), 403);
    }
}
