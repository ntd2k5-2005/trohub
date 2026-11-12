<?php

namespace App\Modules\Billing;

use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Contracts\CashDepositRecorder;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\Models\RoomReservation;
use App\Modules\Rental\RentalService;
use App\Support\ScopedRepository;
use App\Support\SqlTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class CashDepositService implements CashDepositRecorder
{
    public function record(User $user, Rental $rental, array $input): void
    {
        app(ScopedRepository::class)->managedRoom($user, $rental->room_id, 'deposit.record');
        $data = Validator::make($input, ['amount' => 'required|integer|min:1|max:1000000000', 'reference' => 'required|string|min:3|max:100', 'confirm' => 'accepted'])->validate();
        app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($user, $rental->room_id, 'deposit.record', function () use ($user, $rental, $data) {
            Room::whereKey($rental->room_id)->lockForUpdate()->firstOrFail();
            $rental = Rental::whereKey($rental->id)->lockForUpdate()->firstOrFail();
            $reference = strtoupper(trim($data['reference']));
            if ($existing = DB::table('payment_request_transactions')->where('reference', $reference)->first()) {
                abort_unless((int) $existing->rental_id === $rental->id && $existing->method === 'cash' && (int) $existing->amount === (int) $data['amount'], 409, 'Mã phiếu đã được sử dụng cho khoản khác.');

                return;
            }
            abort_unless(in_array($rental->status, ['draft', 'active'], true), 409, 'Hồ sơ không còn nhận cọc. Cần đối soát riêng khoản đến muộn.');
            $hold = RoomReservation::where('rental_id', $rental->id)->lockForUpdate()->first();
            if ($rental->status === 'draft') {
                abort_unless($hold && $hold->active_room_id && $hold->status === 'holding' && $hold->hold_until->isFuture() && $hold->request->status === 'accepted', 409, 'Cần giữ phòng hợp lệ trước khi nhận cọc.');
            }
            $received = app(ReservationPayments::class)->received($rental);
            $remaining = $rental->deposit - $received;
            abort_unless($remaining > 0 && $data['amount'] <= $remaining, 422, 'Số tiền vượt số cọc còn phải thu.');
            if (! ($hold?->policy_snapshot['partial_deposit'] ?? false)) {
                abort_unless((int) $data['amount'] === $remaining, 422, 'Điều khoản yêu cầu thanh toán tiền cọc một lần.');
            }
            DB::table('payment_request_transactions')->insert(['payment_request_id' => null, 'rental_id' => $rental->id, 'kind' => 'deposit', 'method' => 'cash', 'amount' => $data['amount'], 'reference' => $reference, 'verified_by' => $user->id, 'verified_at' => now(), 'exception' => false, 'created_at' => now(), 'updated_at' => now()]);
            if ((int) $data['amount'] === $remaining) {
                app(RentalService::class)->receiveCashDeposit($user, $rental);
                $hold?->update(['status' => 'deposit_confirmed', 'version' => $hold->version + 1]);
            }
            // Old QR amounts are no longer payable after a cash receipt changes the balance.
            DB::table('payment_requests')->where('rental_id', $rental->id)->where('kind', 'deposit')->whereNotIn('status', ['verified', 'cancelled'])->update(['status' => (int) $data['amount'] === $remaining ? 'verified' : 'partial']);
            app(Journal::class)->record($user->id, 'deposit.cash_received', $rental, $rental->room->property->workspace_id, ['amount' => $data['amount'], 'reference' => $reference]);
            if ($rental->tenant_id) {
                app(Journal::class)->notify($rental->tenant_id, 'Đã ghi nhận cọc tiền mặt', 'Đã nhận '.number_format($data['amount'], 0, ',', '.').' đồng. Bạn có thể kiểm tra lịch sử trong hồ sơ thuê.', workspace: $rental->room->property->workspace_id);
            }
        });
    }
}
