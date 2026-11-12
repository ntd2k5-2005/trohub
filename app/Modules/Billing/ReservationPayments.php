<?php

namespace App\Modules\Billing;

use App\Models\User;
use App\Modules\Rental\Contracts\ReservationPayments as Contract;
use App\Modules\Rental\Models\Rental;
use Illuminate\Support\Facades\DB;

final class ReservationPayments implements Contract
{
    public function issue(User $actor, Rental $rental): bool
    {
        $workspace = $rental->room->property->workspace;
        if (! app(ReceivingAccountService::class)->selected($workspace, $rental->room->property_id, $rental->room_id)) {
            return false;
        }
        app(PaymentRequestService::class)->issue($actor, $workspace, 'deposit', $rental->id);

        return true;
    }

    public function received(Rental $rental): int
    {
        $transactions = DB::table('payment_request_transactions')->where('rental_id', $rental->id)->where('kind', 'deposit')->sum('amount');

        return max((int) $transactions, (int) $rental->deposits()->sum('amount'));
    }

    public function hasPending(Rental $rental): bool
    {
        return DB::table('payment_transfer_reports')->whereIn('payment_request_id', DB::table('payment_requests')->where('rental_id', $rental->id)->select('id'))->where('status', 'pending')->exists();
    }
}
