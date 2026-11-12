<?php

namespace App\Modules\Rental;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Rental\Contracts\ReservationPayments;
use App\Modules\Rental\Models\DepositRefundDecision;
use App\Modules\Rental\Models\Rental;
use App\Modules\Workspace\Models\Workspace;
use App\Support\SqlTransactions;
use Illuminate\Validation\ValidationException;

final class RefundService
{
    public function __construct(private Authorization $access, private Journal $journal, private ReservationPayments $payments) {}

    public function propose(User $actor, Workspace $workspace, Rental $rental, int $deduction, string $reason): void
    {
        abort_unless($rental->room->property->workspace_id === $workspace->id, 403);
        $this->access->authorize($actor, 'deposit.settle', $workspace, $rental->room->property_id, $rental->room_id);
        SqlTransactions::run(function () use ($actor, $workspace, $rental, $deduction, $reason) {
            $rental = Rental::whereKey($rental->id)->lockForUpdate()->firstOrFail();
            abort_unless($rental->status === 'cancelled', 409, 'Chỉ lập hoàn cọc sau khi hủy hồ sơ và xử lý giữ phòng.');
            if (DepositRefundDecision::where('rental_id', $rental->id)->whereIn('status', ['proposed', 'accepted'])->exists()) {
                throw ValidationException::withMessages(['refund' => 'Đang có phương án hoàn cọc chờ giải quyết.']);
            }
            $balance = $this->payments->received($rental) - (int) DepositRefundDecision::where('rental_id', $rental->id)->where('status', 'paid')->sum('amount_received');
            abort_unless($balance > 0 && $deduction >= 0 && $deduction <= $balance && trim($reason) !== '' && mb_strlen($reason) <= 2000, 422, 'Số tiền hoặc lý do hoàn/khấu trừ không hợp lệ.');
            DepositRefundDecision::where('rental_id', $rental->id)->where('status', 'disputed')->update(['status' => 'superseded']);
            $decision = DepositRefundDecision::create(['rental_id' => $rental->id, 'amount_received' => $balance, 'deduction' => $deduction, 'reason' => $reason, 'created_by' => $actor->id]);
            $this->journal->record($actor->id, 'deposit.refund_proposed', $decision, $workspace->id, ['amount' => $balance, 'deduction' => $deduction, 'reason' => $reason]);
            $this->journal->notify($rental->tenant_id, 'Phương án hoàn cọc', 'Vui lòng kiểm tra số tiền hoàn, khoản khấu trừ và căn cứ trước khi xác nhận.', workspace: $workspace->id);
        });
    }

    public function respond(User $payer, DepositRefundDecision $decision, bool $accept, ?string $note): void
    {
        app(\App\Modules\Subscription\PropertyCloud::class)->requireRoom($decision->rental->room_id);
        abort_unless($payer->id === $decision->rental->tenant_id && $this->access->personal($payer, 'tenant.confirm'), 403);
        SqlTransactions::run(function () use ($payer, $decision, $accept, $note) {
            $decision = DepositRefundDecision::whereKey($decision->id)->lockForUpdate()->firstOrFail();
            abort_unless($decision->status === 'proposed', 409);
            abort_unless($accept || trim($note ?? '') !== '', 422, 'Vui lòng nêu lý do chưa đồng ý.');
            $decision->update(['status' => $accept ? 'accepted' : 'disputed', 'payer_accepted_at' => $accept ? now() : null, 'payer_note' => $note]);
            $this->journal->record($payer->id, $accept ? 'deposit.refund_accepted' : 'deposit.refund_disputed', $decision, $decision->rental->room->property->workspace_id);
            $this->journal->notify($decision->rental->room->property->workspace->owner_id, 'Phương án hoàn cọc có phản hồi', $accept ? 'Người thuê đã đồng ý phương án. Chỉ ghi nhận hoàn tiền sau khi thực hiện thực tế.' : 'Người thuê chưa đồng ý; cần thống nhất lại.', workspace: $decision->rental->room->property->workspace_id);
        });
    }

    public function paid(User $actor, Workspace $workspace, DepositRefundDecision $decision, string $reference): void
    {
        $rental = $decision->rental;
        abort_unless($rental->room->property->workspace_id === $workspace->id, 403);
        $this->access->authorize($actor, 'deposit.settle', $workspace, $rental->room->property_id, $rental->room_id);
        SqlTransactions::run(function () use ($actor, $workspace, $decision, $reference) {
            $decision = DepositRefundDecision::whereKey($decision->id)->lockForUpdate()->firstOrFail();
            if ($decision->status === 'paid' && $decision->refund_reference === $reference) {
                return;
            }
            abort_unless($decision->status === 'accepted' && $decision->payer_accepted_at && trim($reference) !== '' && mb_strlen($reference) <= 100, 409, 'Cần hai bên thống nhất phương án trước khi ghi nhận hoàn tiền.');
            abort_if(DepositRefundDecision::where('refund_reference', $reference)->exists(), 409, 'Mã hoàn tiền đã được sử dụng.');
            $decision->update(['status' => 'paid', 'refund_reference' => $reference, 'refunded_at' => now()]);
            $decision->rental->deposits()->whereNull('settled_at')->update(['deduction' => $decision->deduction, 'reason' => $decision->reason, 'settled_at' => now()]);
            $this->journal->record($actor->id, 'deposit.refund_recorded', $decision, $workspace->id, ['amount' => $decision->amount_received - $decision->deduction]);
            $this->journal->notify($decision->rental->tenant_id, 'Đã ghi nhận hoàn cọc', 'Chủ trọ đã ghi nhận kết quả hoàn cọc theo phương án hai bên thống nhất. Hãy kiểm tra giao dịch thực nhận.', workspace: $workspace->id);
        });
    }
}
