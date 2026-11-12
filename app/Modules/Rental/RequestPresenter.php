<?php

namespace App\Modules\Rental;

use App\Modules\Rental\Contracts\ReservationPayments;
use App\Modules\Rental\Models\RentalRequest;
use App\Support\ProductPresenter;

final class RequestPresenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $query = RentalRequest::with('room.property', 'user', 'reservation', 'rental');
        if ($this->workspace) {
            $query->where('workspace_id', $this->workspace->id)->whereIn('room_id', $this->access->roomIds($this->user, $this->workspace, 'rental.view'));
        } else {
            $query->where('user_id', $this->user->id)->whereHas('room.property.workspace', fn ($w) => app(\App\Modules\Subscription\PropertyCloud::class)->eligible($w));
        }
        $this->records($query->latest(), function ($r) {
            $terms = $r->terms_snapshot;
            $forms = [];
            $state = fn ($s) => config('rental_workflow.statuses.'.$s, self::label($s));
            if ($this->workspace && in_array($r->status, ['submitted', 'counter_offered'], true)) {
                $common = [$this->field('version', '', 'hidden', value: $r->version)];
                if ($r->status === 'submitted' && $r->room->status === 'vacant') {
                    $forms[] = $this->form('Chấp thuận và giữ phòng', 'rental_request.decide', [...$common, $this->field('decision', '', 'hidden', value: 'accept')], $r->id);
                }
                $forms[] = $this->form('Đề nghị điều chỉnh', 'rental_request.decide', [...$common, $this->field('decision', '', 'hidden', value: 'counter'), $this->field('rent', 'Giá thuê đề nghị (đồng)', 'number', value: $terms['rent']), $this->field('deposit', 'Tiền cọc đề nghị (đồng)', 'number', value: $terms['deposit']), $this->field('deposit_terms', 'Điều khoản cọc, hoàn và khấu trừ', 'textarea', value: $terms['deposit_terms']), $this->field('reason', 'Nội dung trao đổi', 'textarea')], $r->id);
                $forms[] = $this->form('Từ chối yêu cầu', 'rental_request.decide', [...$common, $this->field('decision', '', 'hidden', value: 'reject'), $this->field('reason', 'Lý do từ chối', 'textarea')], $r->id);
            }
            if (! $this->workspace && $r->status === 'counter_offered') {
                $forms[] = $this->form('Đồng ý điều kiện mới', 'rental_request.accept_offer', [$this->field('version', '', 'hidden', value: $r->version), $this->field('accept_terms', 'Tôi đồng ý giá thuê, tiền cọc và điều khoản nêu trên', 'checkbox')], $r->id);
            }
            if (! $this->workspace && in_array($r->status, ['submitted', 'counter_offered', 'accepted'], true) && (! $r->rental || $r->rental->status === 'draft')) {
                $forms[] = $this->form('Hủy yêu cầu thuê', 'rental_request.cancel', [$this->field('reason', 'Lý do hủy', 'textarea')], $r->id);
            }
            $facts = ['Người đề nghị' => $r->user->name, 'Trạng thái yêu cầu' => $state($r->status), 'Ngày dự kiến' => $terms['starts_at'].' → '.$terms['ends_at'], 'Giá thuê' => self::money($terms['rent']), 'Tiền cọc' => $terms['deposit'] > 0 ? self::money($terms['deposit']) : 'Miễn cọc', 'Số người' => $terms['people'], 'Điều khoản cọc' => $terms['deposit_terms'], 'Lời nhắn' => $r->message ?: 'Không có', 'Phản hồi' => $r->decision_reason ?: 'Chưa có'];
            if ($hold = $r->reservation) {
                $facts['Giữ phòng'] = $state($hold->status);
                $facts['Thời hạn giữ'] = $hold->hold_until->format('d/m/Y H:i');
                if ($hold->exception_until) {
                    $facts['Hạn xử lý'] = $hold->exception_until->format('d/m/Y H:i');
                }
                $facts['Đã nhận cọc'] = self::money(app(ReservationPayments::class)->received($hold->rental));
                if ($this->workspace && $hold->active_room_id) {
                    $base = [$this->field('version', '', 'hidden', value: $hold->version), $this->field('reason', 'Lý do xử lý', 'textarea')];
                    if ($hold->rental->deposit > 0 && in_array($hold->status, ['holding', 'deposit_confirmed'], true)) {
                        $forms[] = $this->form('Miễn tiền cọc', 'reservation.action', [...$base, $this->field('decision', '', 'hidden', value: 'waive')], $hold->id);
                    }
                    if ($hold->hold_until->isFuture() && $hold->extensions < $hold->policy_snapshot['max_extensions']) {
                        $forms[] = $this->form('Gia hạn giữ phòng', 'reservation.action', [...$base, $this->field('decision', '', 'hidden', value: 'extend'), $this->field('hours', 'Số giờ gia hạn', 'number', value: $hold->policy_snapshot['extension_hours'])], $hold->id);
                    }
                    $forms[] = $this->form('Giải phóng phòng và xử lý cọc riêng', 'reservation.action', [...$base, $this->field('decision', '', 'hidden', value: 'release')], $hold->id);
                    if (in_array($hold->status, ['manual_review', 'review_pending'], true) && $r->status === 'accepted') {
                        $forms[] = $this->form('Tiếp tục giữ để xử lý', 'reservation.action', [...$base, $this->field('decision', '', 'hidden', value: 'retain'), $this->field('until', 'Giữ đến', 'datetime-local')], $hold->id);
                    }
                }
            }

            return $this->card($r->room->name.' · Yêu cầu thuê', $r->room->property->name, $facts, $forms, $r->rental_id ? [$this->link('Hồ sơ và hợp đồng', 'rental-detail', ['id' => $r->rental_id])] : []);
        }, 'requests_page');
    }
}
