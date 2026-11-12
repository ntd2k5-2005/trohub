<?php

namespace App\Modules\Rental;

use App\Modules\Billing\Models\PaymentRequest;
use App\Modules\Billing\PaymentRequestPresenter;
use App\Modules\Rental\Contracts\ReservationPayments;
use App\Modules\Rental\Models\DepositRefundDecision;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\Models\RoomReservation;
use App\Modules\Subscription\PropertyCloud;
use App\Modules\Trust\Models\Review;
use App\Modules\Trust\ReviewDetailsService;
use App\Support\ProductPresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $this->rentals($slug, $q);
        if ($slug === 'rentals') {
            $requestScreen = app(RequestPresenter::class)->present($this->request, $slug);
            $this->vm['rental_requests'] = $requestScreen['records'];
            $this->vm['request_pagination'] = $requestScreen['pagination'];
        }
    }

    protected function rentals(string $slug, string $q): void
    {
        if ($this->can('rental.manage')) {
            $this->vm['forms'][] = $this->form('Lập hồ sơ thuê', 'rental.create', [$this->field('room_id', 'Phòng', 'select', $this->roomOptions('rental.manage')), $this->field('tenant_name', 'Họ tên người thuê'), $this->field('tenant_email', 'Email người thuê', 'email'), $this->field('connection_id', 'Liên hệ', 'hidden', value: $this->request->input('connection_id'), required: false), $this->field('starts_at', 'Ngày bắt đầu', 'date'), $this->field('ends_at', 'Ngày kết thúc', 'date'), $this->field('rent', 'Giá thuê (đồng)', 'number'), $this->field('deposit', 'Tiền cọc (đồng)', 'number', value: 0), $this->field('terms', 'Điều khoản', 'textarea')]);
        }
        $query = (! $this->workspace && ! $this->access->personal($this->user, 'rental.view') ? Rental::whereRaw('1=0') : $this->scope->rentals($this->user, $this->workspace))->with('room.property', 'tenant', 'versions', 'deposits', 'occupants.user');
        if ($slug === 'rental-detail') {
            $query->whereKey($this->request->integer('id'));
        }
        $this->records($query->latest(), function ($r) {
            if (! $this->workspace && ! app(PropertyCloud::class)->active($r->room->property->workspace_id)) {
                $confirmed = $r->confirmed_at ? [
                    'Thời hạn' => $r->starts_at.' → '.$r->ends_at,
                    'Giá thuê' => self::money($r->rent), 'Tiền cọc theo hợp đồng' => self::money($r->deposit),
                    'Điều khoản đã xác nhận' => $r->terms, 'Ngày xác nhận' => $r->confirmed_at->format('d/m/Y H:i'),
                ] : [];
                foreach (DB::table('payment_request_transactions')->where('rental_id', $r->id)->where('kind', 'deposit')->whereNotNull('verified_at')->orderBy('id')->get() as $receipt) {
                    $confirmed['Phiếu '.$receipt->reference] = self::money($receipt->amount).' · '.Carbon::parse($receipt->verified_at)->format('d/m/Y H:i');
                }
                if ($deposit = $r->deposits->first()) {
                    $confirmed['Cọc đã xác nhận'] = self::money($deposit->amount);
                }

                return $this->card($r->room->name, $r->confirmed_at ? 'Hợp đồng đã xác nhận' : 'Chứng từ thuê phòng', ['Người thuê' => $this->user->name] + $confirmed);
            }
            $forms = [];
            $refunds = DepositRefundDecision::where('rental_id', $r->id)->latest()->get();
            $reservation = RoomReservation::where('rental_id', $r->id)->first();
            if ($this->workspace && $r->status === 'cancelled' && $this->can('deposit.settle', $r->room->property_id, $r->room_id) && app(ReservationPayments::class)->received($r) - (int) $refunds->where('status', 'paid')->sum('amount_received') > 0 && $refunds->whereIn('status', ['proposed', 'accepted'])->isEmpty()) {
                $forms[] = $this->form('Đề xuất hoàn và khấu trừ cọc', 'refund.propose', [$this->field('deduction', 'Khấu trừ dự kiến (đồng)', 'number', value: 0), $this->field('reason', 'Căn cứ theo điều khoản đã thống nhất', 'textarea')], $r->id);
            }

            foreach ($refunds as $refund) {
                if (! $this->workspace && $refund->status === 'proposed') {
                    $forms[] = $this->form('Phản hồi phương án hoàn cọc', 'refund.respond', [$this->field('decision', 'Ý kiến của bạn', 'select', ['accept' => 'Đồng ý phương án', 'dispute' => 'Chưa đồng ý']), $this->field('note', 'Lý do hoặc lời nhắn', 'textarea', required: false)], $refund->id);
                }
                if ($this->workspace && $refund->status === 'accepted') {
                    $forms[] = $this->form('Ghi nhận đã hoàn tiền thực tế', 'refund.paid', [$this->field('reference', 'Mã giao dịch hoàn tiền'), $this->field('confirm', 'Tôi đã hoàn tiền theo phương án hai bên thống nhất', 'checkbox')], $refund->id);
                }
            }
            if (($r->status === 'active' || $reservation?->active_room_id && $reservation->hold_until->isFuture()) && $this->workspace && $r->deposit > 0 && $r->deposits->isEmpty() && in_array($r->status, ['draft', 'active'], true) && $this->can('payment_request.issue', $r->room->property_id, $r->room_id)) {
                $forms[] = $this->form('Phát hành yêu cầu cọc', 'payment_request.issue', [$this->field('kind', '', 'hidden', value: 'deposit')], $r->id);
            }
            if ($r->tenant_id === $this->user->id && $r->status === 'draft' && (! $r->confirmed_at || ! app(RentalService::class)->hasPolicySnapshot($r))) {
                $forms[] = $this->form('Xác nhận thông tin thuê', 'rental.confirm', [$this->field('terms_quote', '', 'hidden', value: app(RentalService::class)->reviewQuote($r)), $this->field('confirm_terms', 'Tôi đã đọc và đồng ý thời hạn, giá thuê, tiền cọc, chính sách giữ phòng và các điều khoản của hồ sơ này', 'checkbox')], $r->id);
            }
            if ($this->can('rental.manage', $r->room->property_id, $r->room_id)) {
                if (! $r->tenant_id && $r->resident_profile_id) {
                    $forms[] = $this->form('Mời người thuê liên kết tài khoản', 'rental.invite', [], $r->id);
                }
                if ($r->status === 'draft' && app(RentalService::class)->activationBlocker($r) === null) {
                    $forms[] = $this->form('Kích hoạt thuê', 'rental.activate', [], $r->id);
                }
                if ($r->status === 'draft' && ! $reservation && $r->confirmed_at && $r->tenant_id && app(RentalService::class)->hasPolicySnapshot($r)) {
                    $forms[] = $this->form('Xác nhận giữ phòng', 'rental.reserve', [], $r->id);
                }
                if ($r->status === 'draft' && ! $reservation) {
                    $forms[] = $this->form('Hủy hồ sơ, đối soát cọc riêng', 'rental.cancel', [$this->field('reason', 'Lý do hủy', 'textarea')], $r->id);
                }
                if (($r->status === 'active' || $reservation?->active_room_id && $reservation->status === 'holding' && $reservation->hold_until->isFuture()) && $r->deposit > 0 && $r->deposits->isEmpty()) {
                    $forms[] = $this->form('Nhận cọc tiền mặt', 'rental.deposit', [$this->field('amount', 'Số tiền thực nhận (đồng)', 'number', value: max(0, $r->deposit - app(ReservationPayments::class)->received($r))), $this->field('reference', 'Mã phiếu nhận tiền'), $this->field('confirm', 'Tôi đã thực nhận khoản tiền mặt này', 'checkbox')], $r->id);
                }
                if ($r->status === 'active') {
                    $forms[] = $this->form('Gia hạn hợp đồng', 'rental.renew', [$this->field('ends_at', 'Ngày kết thúc mới', 'date')], $r->id);
                    $forms[] = $this->form('Thêm người cư trú', 'rental.occupant', [$this->field('email', 'Email người cư trú', 'email')], $r->id);
                }
                if ($r->status === 'move_out_pending') {
                    if ($r->deposits->first() && ! $r->deposits->first()->settled_at) {
                        $forms[] = $this->form('Quyết toán tiền cọc', 'rental.settle', [$this->field('deduction', 'Khấu trừ (đồng)', 'number', value: 0), $this->field('reason', 'Lý do khấu trừ', 'textarea', required: false)], $r->id);
                    }
                    $forms[] = $this->form('Hoàn tất trả phòng', 'rental.close', [$this->field('handover_complete', 'Đã kiểm tra và bàn giao phòng', 'checkbox', value: 1)], $r->id);
                }
            }
            if ($r->status === 'active' && ($this->can('rental.manage', $r->room->property_id, $r->room_id) || $r->tenant_id === $this->user->id)) {
                $forms[] = $this->form('Yêu cầu trả phòng', 'rental.request_move_out', [$this->field('move_out_at', 'Ngày dự kiến trả', 'date')], $r->id);
            }
            if ($r->status === 'closed' && $r->tenant_id === $this->user->id) {
                $review = Review::where('rental_id', $r->id)->first();
                if (! $review) {
                    $forms[] = $this->form('Đánh giá lần thuê', 'review.create', [$this->field('rental_id', 'Hồ sơ', 'hidden', value: $r->id), $this->field('rating', 'Số sao', 'select', [1 => '1 sao', 2 => '2 sao', 3 => '3 sao', 4 => '4 sao', 5 => '5 sao']), $this->field('comment', 'Nhận xét', 'textarea')]);
                } else {
                    $details = app(ReviewDetailsService::class)->read($review);
                    if ($details['available']) {
                        $document = $details['document'];
                        $forms[] = $this->form('Bổ sung đánh giá công khai', 'review.details.save', [
                            $this->field('handover_comment', 'Trải nghiệm bàn giao', 'textarea', value: data_get($document, 'aspects.handover.comment'), required: false),
                            $this->field('shared_area_suggestions', 'Góp ý khu vực chung (mỗi dòng một ý)', 'textarea', value: implode("\n", data_get($document, 'aspects.shared_areas.suggestions', [])), required: false),
                            $this->field('would_recommend', 'Bạn có giới thiệu chỗ ở này?', 'select', [1 => 'Có', 0 => 'Chưa phù hợp'], data_get($document, 'would_recommend') === null ? null : (int) $document['would_recommend'], false),
                        ], $review->id);
                        if ($document) {
                            $forms[] = $this->form('Xóa đánh giá bổ sung', 'review.details.delete', [$this->field('confirm', 'Tôi muốn xóa nội dung bổ sung; giữ nguyên số sao và nhận xét cơ bản', 'checkbox')], $review->id);
                        }
                    } else {
                        $this->vm['notice'] = 'Đánh giá bổ sung tạm chưa sẵn sàng. Số sao và nhận xét cơ bản của bạn vẫn được giữ.';
                    }
                }
            }
            $facts = ['Người thuê' => $r->tenant?->name ?? $r->resident?->name ?? 'Chờ liên kết', 'Trạng thái' => self::label($r->status), 'Thời hạn' => $r->starts_at.' → '.$r->ends_at, 'Giá thuê' => self::money($r->rent), 'Cọc' => self::money($r->deposit), 'Điều khoản' => $r->terms, 'Xác nhận' => $r->confirmed_at ? 'Đã xác nhận' : 'Chờ người thuê xác nhận', 'Người cư trú' => $r->occupants->map(fn ($o) => $o->user->name)->implode(', '), 'Phiên bản hợp đồng' => $r->versions->count()];
            foreach ($refunds as $refund) {
                $facts['Phương án hoàn cọc '.$refund->id] = self::money($refund->amount_received - $refund->deduction).' · Khấu trừ '.self::money($refund->deduction).' · '.(['proposed' => 'Chờ người thuê', 'accepted' => 'Đã thống nhất, chờ hoàn tiền', 'disputed' => 'Chưa thống nhất', 'superseded' => 'Đã thay phương án', 'paid' => 'Đã ghi nhận hoàn tiền'][$refund->status] ?? 'Chờ xử lý').' · '.$refund->reason;
            }
            if ($r->status === 'draft') {
                $policy = app(RentalService::class)->reviewPolicy($r);
                $facts['Chính sách giữ phòng'] = 'Giữ '.$policy['hold_hours'].' giờ sau khi được chấp thuận; gia hạn tối đa '.$policy['max_extensions'].' lần, mỗi lần không quá '.$policy['extension_hours'].' giờ. '.($policy['partial_deposit'] ? 'Cho phép trả cọc từng phần.' : 'Thanh toán cọc một lần.').' Ngoại lệ được xem xét trong '.$policy['exception_hours'].' giờ trước khi chuyển xử lý thủ công.';
                $facts['Bước tiếp theo'] = app(RentalService::class)->activationBlocker($r) ?? 'Chờ bên cho thuê xác nhận kích hoạt';
            }
            if ($d = $r->deposits->first()) {
                $facts['Quyết toán cọc'] = $d->settled_at ? 'Hoàn '.self::money($d->amount - $d->deduction).' · '.$d->reason : 'Đã nhận '.self::money($d->amount);
            }
            if ($this->can('payment.view', $r->room->property_id, $r->room_id)) {
                foreach (DB::table('payment_request_transactions')->where('rental_id', $r->id)->where('kind', 'deposit')->orderByDesc('id')->limit(20)->get() as $receipt) {
                    $facts['Phiếu '.$receipt->reference] = self::money($receipt->amount).' · '.($receipt->method === 'cash' ? 'Tiền mặt' : 'Chuyển khoản').' · '.Carbon::parse($receipt->verified_at)->format('d/m/Y H:i');
                }
            }
            $facts['Người thuê xác nhận ngày'] = $r->confirmed_at?->format('d/m/Y H:i') ?? 'Chưa xác nhận';
            $facts['Bên cho thuê xác nhận ngày'] = $r->signed_at?->format('d/m/Y H:i') ?? 'Chưa xác nhận';
            $facts['Bắt đầu cư trú'] = $r->residency_started_at?->format('d/m/Y H:i') ?? 'Chưa bắt đầu';

            return $this->card($r->room->name, $r->room->property->name, $facts, $forms, [$this->link('Chi tiết hồ sơ', 'rental-detail', ['id' => $r->id])]) + ['payment_requests' => $this->can('payment.view', $r->room->property_id, $r->room_id) ? PaymentRequest::where('rental_id', $r->id)->where('kind', 'deposit')->latest()->limit(3)->get()->map(fn ($payment) => app(PaymentRequestPresenter::class)->present($this->user, $this->workspace, $payment))->all() : []];
        });
        if ($slug === 'rental-detail' && ! $this->vm['records']) {
            abort(404);
        }
    }
}
