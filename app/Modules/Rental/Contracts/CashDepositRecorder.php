<?php

namespace App\Modules\Rental\Contracts;

use App\Models\User;
use App\Modules\Rental\Models\Rental;

interface CashDepositRecorder
{
    public function record(User $user, Rental $rental, array $input): void;
}
