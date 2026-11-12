<?php

namespace App\Modules\Billing;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Billing\Models\PaymentRequest;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;

final class PaymentRequestPresenter
{
    public function present(User $user, ?Workspace $workspace, PaymentRequest $record): object
    {
        $service = app(PaymentRequestService::class);
        $service->authorize($user, $record, $workspace);
        $live = app(\App\Modules\Subscription\PropertyCloud::class)->active($record->workspace_id);
        $receipts = DB::table('payment_request_transactions')->where(function ($q) use ($record, $live) {
            $q->where('payment_request_id', $record->id);
            if (! $live && $record->kind === 'deposit') {
                $q->orWhere(fn ($cash) => $cash->whereNull('payment_request_id')->where('rental_id', $record->rental_id)->where('kind', 'deposit')->where('method', 'cash'));
            }
        })->whereNotNull('verified_at')->orderBy('id')->get();
        $context = ['context' => $workspace ? 'workspace' : 'personal'] + ($workspace ? ['workspace_id' => $workspace->id] : []);
        $handover=$workspace?\App\Modules\Workspace\Models\WorkAssignment::where('workspace_id',$workspace->id)->where('resource_type','payment_review')->where('resource_id',$record->id)->where('status','handover_pending')->first():null;

        return (object) [
            'id' => $record->id, 'room_label'=>$record->rental->room->name, 'kind' => $record->kind, 'status' => $record->status, 'amount' => $live ? $record->amount : $receipts->sum('amount'), 'payer_id' => $record->payer_id, 'history_only' => ! $live,
            'status_label' => ['awaiting_payment' => 'Chưa nhận tiền', 'verification_pending' => 'Chờ xác nhận', 'partial' => 'Đã nhận một phần', 'verified' => 'Đã xác nhận nhận tiền', 'exception' => 'Giao dịch cần xử lý riêng', 'cancelled' => 'Yêu cầu đã hủy'][$record->status] ?? 'Cần kiểm tra',
            'expires_at' => $live ? $record->expires_at : null, 'transfer_reference' => $live ? $record->transfer_reference : null, 'payee_snapshot' => $live ? $record->payee_snapshot : [],
            'usable' => $service->usable($record), 'remaining' => $service->remaining($record),
            'can_report' => $live && ! $workspace && $user->id === $record->payer_id,
            'can_verify' => $workspace && app(Authorization::class)->allowed($user, 'payment.verify', $workspace, $record->property_id, $record->rental->room_id),
            'can_claim_review' => $workspace && in_array($record->status,config('people.work_resources.payment_review.states'),true) && app(Authorization::class)->allowed($user,'payment.verify',$workspace,$record->property_id,$record->rental->room_id),
            'handover_work_id'=>$handover?->id,
            'qr_url' => route('payment-request.qr', ['paymentRequest' => $record->id] + $context),
            'receipts' => $receipts,
            'proofs' => DB::table('payment_transfer_reports')->where('payment_request_id', $record->id)->when(! $live, fn ($q) => $q->where('status', 'verified'))->orderByDesc('id')->get()->map(function ($proof) use ($record, $context) {
                $proof->url = $proof->evidence_path ? route('payment-request.proof', ['paymentRequest' => $record->id, 'report' => $proof->id] + $context) : null;
                unset($proof->evidence_path);

                return $proof;
            }),
        ];
    }
}
