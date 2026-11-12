<?php

namespace App\Modules\Rental\Contracts;

use App\Models\User;
use App\Modules\Rental\Models\Rental;

interface ReservationPayments
{
    public function issue(User $actor, Rental $rental): bool;

    public function received(Rental $rental): int;

    public function hasPending(Rental $rental): bool;
}
