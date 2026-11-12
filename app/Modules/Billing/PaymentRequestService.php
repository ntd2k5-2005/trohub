<?php

namespace App\Modules\Billing;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\PaymentRequest;
use App\Modules\DataPlatform\Journal;
use App\Modules\Notification\NotificationService;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\Models\RoomReservation;
use App\Modules\Rental\RentalService;
use App\Modules\Workspace\Models\Workspace;
use App\Support\SqlTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PaymentRequestService
{
    public function __construct(private Authorization $access, private ReceivingAccountService $accounts, private Journal $journal) {}

    public function issue(User $user, Workspace $workspace, string $kind, int $id): PaymentRequest
    {
        abort_unless(in_array($kind, ['invoice', 'deposit'], true), 422);
        $invoice = $kind === 'invoice' ? Invoice::findOrFail($id) : null;
        $rental = $invoice?->rental ?? Rental::findOrFail($id);
        $room = $rental->room;
        abort_unless($room->property->workspace_id === $workspace->id, 403);
        $this->access->authorize($user, 'payment_request.issue', $workspace, $room->property_id, $room->id);

        return app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($user, $room->id, 'payment_request.issue', function () use ($user, $workspace, $kind, $id, $room, $rental, $invoice) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            $rental = Rental::whereKey($rental->id)->lockForUpdate()->firstOrFail();
            $invoice = $invoice ? Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail() : null;
            $received = app(ReservationPayments::class)->received($rental);
            if ($kind === 'deposit' && $rental->status === 'draft') {
                abort_unless(RoomReservation::where('rental_id', $rental->id)->whereNotNull('active_room_id')->whereIn('status', ['holding', 'deposit_confirmed'])->where('hold_until', '>', now())->exists(), 409, 'Cần giữ phòng hợp lệ trước khi phát hành yêu cầu cọc.');
            }
            $amount = $invoice ? $invoice->outstanding : max(0, $rental->deposit - $received);
            if (! $rental->tenant_id || $amount <= 0 || ($invoice ? ! $invoice->issued_at : ! in_array($rental->status, ['draft', 'active'], true))) {
                throw ValidationException::withMessages(['payment' => 'Khoản thu không đủ điều kiện phát hành hoặc đã được thanh toán.']);
            }
            // One immutable request per obligation/balance. Retrying reuses the same reference and payee.
            $key = $kind.':'.$id.':'.($invoice ? $invoice->paid.':'.$invoice->total : $received.':'.$rental->deposit);
            if ($existing = PaymentRequest::where('obligation_key', $key)->first()) {
                return $existing;
            }
            $account = $this->accounts->selected($workspace, $room->property_id, $room->id);
            if (! $account) {
                throw ValidationException::withMessages(['payment' => 'Chưa thiết lập tài khoản nhận tiền đang sử dụng cho nhà này. Vui lòng kiểm tra Cài đặt khu quản lý.']);
            }
            $request = PaymentRequest::create([
                'workspace_id' => $workspace->id, 'property_id' => $room->property_id, 'rental_id' => $rental->id, 'invoice_id' => $invoice?->id, 'payer_id' => $rental->tenant_id,
                'receiving_account_id' => $account->id, 'kind' => $kind, 'obligation_key' => $key, 'amount' => $amount, 'transfer_reference' => 'TH'.Str::upper(Str::random(14)),
                'payee_snapshot' => $account->only(['bank_bin', 'bank_name', 'account_no', 'account_holder_name']),
                'expires_at' => $invoice ? null : RoomReservation::where('rental_id', $rental->id)->first()?->hold_until,
            ]);
            $this->journal->record($user->id, 'payment_request.issued', $request, $workspace->id, ['kind' => $kind, 'amount' => $amount]);
            app(NotificationService::class)->once($rental->tenant_id, 'payment-request:'.$request->id, 'Thông tin chuyển khoản mới', 'Bạn có khoản '.($kind === 'deposit' ? 'tiền cọc' : 'hóa đơn').' cần thanh toán. Kiểm tra thông tin người nhận trước khi chuyển khoản.', workspace: $workspace->id);

            return $request;
        });
    }

    public function authorize(User $user, PaymentRequest $request, ?Workspace $workspace = null): void
    {
        if (! $workspace && $request->payer_id === $user->id) {
            abort_unless($user->status === 'active' && $this->access->personal($user, 'payment.view'), 403);
            abort_unless(app(\App\Modules\Subscription\PropertyCloud::class)->active($request->workspace_id)
                || $request->rental->tenant_id === $user->id && PaymentRequest::cloudOrVerified()->whereKey($request->id)->exists(), 403);

            return;
        }
        abort_unless($workspace && $workspace->id === $request->workspace_id, 403);
        $this->access->authorize($user, 'payment.view', $workspace, $request->property_id, $request->rental->room_id);
    }

    public function usable(PaymentRequest $request): bool
    {
        if (! app(\App\Modules\Subscription\PropertyCloud::class)->active($request->workspace_id) || ! in_array($request->status, ['awaiting_payment', 'verification_pending', 'partial'], true) || $request->expires_at?->isPast()) {
            return false;
        }
        if ($request->invoice_id) {
            return $request->invoice->issued_at && $request->invoice->outstanding > 0 && $request->invoice->outstanding <= $request->amount;
        }

        $remaining = $this->remaining($request);
        return in_array($request->rental->status, ['draft', 'active'], true) && $remaining > 0 && $remaining <= $request->amount;
    }

    public function remaining(PaymentRequest $request): int
    {
        return $request->invoice_id ? $request->invoice->outstanding : max(0, $request->rental->deposit - app(ReservationPayments::class)->received($request->rental));
    }

    public function report(User $user, PaymentRequest $request, string $reference, ?string $note, ?UploadedFile $evidence): void
    {
        $this->authorize($user, $request);
        app(\App\Modules\Subscription\PropertyCloud::class)->requireActive($request->workspace_id);
        abort_unless($user->id === $request->payer_id, 403);
        app(\App\Modules\Subscription\PropertyCloud::class)->transaction($request->workspace_id, function () use ($user, $request, $reference, $note, $evidence) {
            $request = PaymentRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            // Proof arriving late is retained for review; it never restores a released reservation.
            $existing = DB::table('payment_transfer_reports')->where('payment_request_id', $request->id)->where('reference', $reference)->exists();
            if ($existing) {
                return;
            }
            DB::table('payment_transfer_reports')->insert(['payment_request_id' => $request->id, 'user_id' => $user->id, 'reference' => $reference, 'note' => $note, 'evidence_path' => $evidence?->store('payment-evidence', 'local'), 'created_at' => now(), 'updated_at' => now()]);
            if ($request->status !== 'verified') {
                $request->update(['status' => 'verification_pending']);
            }
            $this->journal->record($user->id, 'payment.reported', $request, $request->workspace_id);
            $this->journal->notify($request->rental->room->property->workspace->owner_id, 'Cần đối soát chuyển khoản', 'Người thuê đã gửi thông tin chuyển khoản. Hãy kiểm tra giao dịch thực nhận trước khi xác nhận.', workspace: $request->workspace_id);
        });
    }

    public function beginReview(User $user,Workspace $workspace,PaymentRequest $request):void
    {
        abort_unless($workspace->id===$request->workspace_id,403);
        app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($user,$request->rental->room_id,'payment.verify',function()use($user,$workspace,$request){
            $request=PaymentRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_if(\App\Modules\Workspace\Models\WorkAssignment::where('workspace_id',$workspace->id)->where('resource_type','payment_review')->where('resource_id',$request->id)->where('status','handover_pending')->exists(),409,'Khoản này đang chờ bàn giao. Hãy tiếp nhận trong danh sách bàn giao.');
            app(\App\Contracts\WorkAssignments::class)->claim($user,$workspace->id,'payment_review',$request->id,'payment.verify');
        });
    }

    public function verify(User $user, Workspace $workspace, PaymentRequest $request, string $reference, ?int $amount = null, ?string $reason = null): void
    {
        abort_unless($workspace->id === $request->workspace_id, 403);
        $this->access->authorize($user, 'payment.verify', $workspace, $request->property_id, $request->rental->room_id);
        app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($user, $request->rental->room_id, 'payment.verify', function () use ($user, $workspace, $request, $reference, $amount, $reason) {
            Room::whereKey($request->rental->room_id)->lockForUpdate()->firstOrFail();
            $request = PaymentRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($existing = DB::table('payment_request_transactions')->where('reference', $reference)->first()) {
                $amount ??= (int) $existing->amount;
                abort_unless($existing->payment_request_id === $request->id && (int) $existing->amount === $amount, 409, 'Mã giao dịch đã được sử dụng cho khoản khác.');

                return;
            }
            app(\App\Contracts\WorkAssignments::class)->assertCurrent($user,$workspace->id,'payment_review',$request->id);
            $amount ??= $this->remaining($request);
            abort_unless($amount > 0 && $amount <= 1000000000, 422, 'Số tiền thực nhận không hợp lệ.');
            $reservation = RoomReservation::where('rental_id', $request->rental_id)->lockForUpdate()->first();
            $exception = $request->kind === 'deposit' && ($request->expires_at?->isPast() || ! in_array($request->rental->status, ['draft', 'active'], true) || $reservation && (! $reservation->active_room_id || in_array($reservation->status, ['review_pending', 'manual_review'], true)));
            $remaining = $this->remaining($request);
            $exception = $exception || $amount > $remaining;
            if (! $exception) {
                abort_unless($amount <= $remaining, 422, 'Số tiền vượt khoản còn phải thu. Cần xử lý riêng giao dịch nhận dư.');
                if ($request->kind === 'deposit' && ! ($reservation?->policy_snapshot['partial_deposit'] ?? false)) {
                    abort_unless($amount === $remaining, 422, 'Điều khoản yêu cầu thanh toán tiền cọc một lần.');
                }
            } else {
                abort_unless($reason && mb_strlen(trim($reason)) >= 5, 422, 'Cần ghi rõ lý do đối soát giao dịch đến muộn hoặc cần xử lý riêng.');
            }
            DB::table('payment_request_transactions')->insert(['payment_request_id' => $request->id, 'rental_id' => $request->rental_id, 'kind' => $request->kind, 'method' => 'transfer', 'amount' => $amount, 'reference' => $reference, 'verified_by' => $user->id, 'verified_at' => now(), 'reason' => $reason, 'exception' => $exception, 'created_at' => now(), 'updated_at' => now()]);
            if ($request->invoice_id && ! $exception) {
                app(BillingService::class)->verifyPayment($user, $request->invoice, ['amount' => $amount, 'reference' => $reference, 'method' => 'transfer']);
            } elseif (! $exception && $amount === $remaining) {
                app(RentalService::class)->receiveVerifiedDeposit($user, $request->rental);
                if ($reservation) {
                    $reservation->update(['status' => 'deposit_confirmed', 'version' => $reservation->version + 1]);
                }
            }
            $request->update(['status' => $exception ? 'exception' : ($amount === $remaining ? 'verified' : 'partial'), 'verified_at' => now(), 'verified_by' => $user->id]);
            if(!$exception&&$amount===$remaining)app(\App\Contracts\WorkAssignments::class)->complete($workspace->id,'payment_review',$request->id);
            DB::table('payment_transfer_reports')->where('payment_request_id', $request->id)->where('reference', $reference)->update(['status' => 'verified', 'reviewed_by' => $user->id, 'updated_at' => now()]);
            $this->journal->record($user->id, $exception ? 'payment.exception_recorded' : 'payment.verified', $request, $workspace->id, ['amount' => $amount]);
            app(NotificationService::class)->once($request->payer_id, 'payment:'.$request->id.':'.hash('sha256', $reference), 'Đã ghi nhận tiền thực nhận', $exception ? 'Giao dịch cần được xử lý riêng. Việc nhận tiền không tự khôi phục giữ phòng hoặc kích hoạt thuê.' : 'Khoản thu đã được đối soát. Hãy kiểm tra số tiền còn thiếu và bước tiếp theo trong hồ sơ thuê.', workspace: $workspace->id);
        });
    }

    public function rejectProof(User $user, Workspace $workspace, PaymentRequest $request, int $reportId, string $reason): void
    {
        abort_unless($request->workspace_id === $workspace->id, 403);
        $this->access->authorize($user, 'payment.verify', $workspace, $request->property_id, $request->rental->room_id);
        abort_unless(trim($reason) !== '' && mb_strlen($reason) <= 2000, 422);
        app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($user, $request->rental->room_id, 'payment.verify', function () use ($user, $request, $reportId, $reason) {
            PaymentRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            app(\App\Contracts\WorkAssignments::class)->assertCurrent($user,$request->workspace_id,'payment_review',$request->id);
            $report = DB::table('payment_transfer_reports')->where('payment_request_id', $request->id)->where('id', $reportId)->where('status', 'pending')->first();
            abort_unless($report, 409);
            DB::table('payment_transfer_reports')->where('id', $reportId)->update(['status' => 'rejected', 'review_reason' => $reason, 'reviewed_by' => $user->id, 'updated_at' => now()]);
            $this->journal->record($user->id, 'payment.proof_rejected', $request, $request->workspace_id, ['report_id' => $reportId, 'reason' => $reason]);
            $this->journal->notify($request->payer_id, 'Cần kiểm tra chứng từ chuyển khoản', $reason, workspace: $request->workspace_id);
        });
    }
}
