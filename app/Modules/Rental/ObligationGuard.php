<?php

namespace App\Modules\Rental;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Rental\Contracts\ReservationPayments;
use App\Modules\Rental\Models\{DepositRefundDecision, Rental, RentalRequest, RoomReservation};

/** Shared check, never a settlement or financial mutation. */
final class ObligationGuard
{
    public function settled(array $roomIds): void
    {
        $rentals = Rental::whereIn('room_id', $roomIds)->get();
        $open = $rentals->contains(fn ($r) => ! in_array($r->status, ['closed', 'cancelled'], true))
            || Invoice::whereIn('rental_id', $rentals->pluck('id'))->where('total', '>', function ($query) {
                $query->from('payments')->selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('payments.invoice_id', 'invoices.id');
            })->exists()
            || MaintenanceRequest::whereIn('room_id', $roomIds)->where('status', '!=', 'closed')->exists()
            || RoomReservation::whereIn('room_id', $roomIds)->whereNotNull('active_room_id')->exists()
            || RentalRequest::whereIn('room_id', $roomIds)->whereIn('status', ['submitted', 'counter_offered'])->exists();
        foreach ($rentals as $r) {
            $settled = max((int) DepositRefundDecision::where('rental_id', $r->id)->where('status', 'paid')->sum('amount_received'), (int) $r->deposits()->whereNotNull('settled_at')->sum('amount'));
            if (app(ReservationPayments::class)->received($r) > $settled || app(ReservationPayments::class)->hasPending($r)) $open = true;
        }
        abort_if($open, 409, 'Cần hoàn tất hồ sơ thuê, công nợ, cọc, yêu cầu và công việc còn mở trước khi ngừng khai thác hoặc lưu trữ.');
    }
}
