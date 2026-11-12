<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\MeterReading;
use App\Modules\Billing\Models\PaymentRequest;
use App\Modules\Billing\Models\ServiceRate;
use App\Support\ProductPresenter;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        if ($slug === 'utilities') {
            $this->utilities();
        } else {
            $this->billing($slug);
        }
    }

    protected function billing(string $slug): void
    {
        if ($slug === 'payments') {
            $scope = $this->scope->rentals($this->user, $this->workspace, 'payment.view');
            $depositQuery=PaymentRequest::whereIn('rental_id',$scope->select('id'))->cloudOrVerified()->where('kind','deposit')->latest();
            if(preg_match('/^payment_requests:([1-9][0-9]*)$/',$this->request->query('item',''),$match))$depositQuery->whereKey($match[1]);
            $pager=$depositQuery->paginate(config('business.pagination'),['*'],'deposit_page',$this->request->filled('item')?1:null)->withQueryString();$this->vm['deposit_pagination']=$pager;
            $this->vm['standalone_payment_requests']=$pager->map(fn($payment)=>app(PaymentRequestPresenter::class)->present($this->user,$this->workspace,$payment))->all();
        }
        foreach (['invoice' => 'Xuất hóa đơn', 'payment' => 'Xuất thanh toán'] as $type => $label) {
            if ($this->can($type.'.export')) {
                $this->vm['links'][] = ['label' => $label, 'url' => route('billing.export', ['type' => $type, 'context' => $this->workspace ? 'workspace' : 'personal'] + ($this->workspace ? ['workspace_id' => $this->workspace->id] : []))];
            }
        }
        if ($this->can('invoice.manage')) {
            $this->vm['forms'][] = $this->form('Lập hóa đơn', 'invoice.create', [$this->field('rental_id', 'Hợp đồng thuê', 'select', $this->rentalOptions('invoice.manage')), $this->field('period', 'Kỳ hóa đơn', 'month'), $this->field('due_at', 'Hạn thanh toán', 'date')]);
        }
        $rentals = $this->scope->rentals($this->user, $this->workspace, $slug === 'payments' ? 'payment.view' : 'invoice.view');
        $invoiceId=$this->request->validate(['invoice_id'=>'nullable|integer|min:1'])['invoice_id']??null;
        $this->records(Invoice::with('rental.room', 'items', 'payments')->whereIn('rental_id', $rentals->select('id'))->when($invoiceId,fn($q)=>$q->whereKey($invoiceId))->when(! $this->workspace, fn ($q) => $q->whereNotNull('issued_at'))->latest(), function ($i) {
            $forms = [];
            $property = $i->rental->room->property_id;
            if ($this->workspace && $i->issued_at && $i->outstanding > 0 && $this->can('payment_request.issue', $property, $i->rental->room_id)) {
                $forms[] = $this->form('Phát hành thông tin chuyển khoản', 'payment_request.issue', [$this->field('kind', '', 'hidden', value: 'invoice')], $i->id);
            }
            if ($this->can('invoice.manage', $property, $i->rental->room_id)) {
                if (! $i->issued_at) {
                    $forms[] = $this->form('Phát hành hóa đơn', 'invoice.issue', [], $i->id);
                }
                $forms[] = $this->form('Điều chỉnh có lý do', 'invoice.adjust', [$this->field('amount', 'Số tiền điều chỉnh (+/−)', 'number'), $this->field('reason', 'Lý do', 'textarea')], $i->id);
            }
            if ($this->can('payment.record', $property, $i->rental->room_id) && $i->issued_at && $i->outstanding > 0) {
                $forms[] = $this->form('Ghi nhận thanh toán', 'payment.create', [$this->field('amount', 'Số tiền nhận (đồng)', 'number'), $this->field('method', 'Phương thức', 'select', ['cash' => 'Tiền mặt']), $this->field('reference', 'Mã giao dịch / số phiếu thu')], $i->id);
            }
            $facts = ['Trạng thái' => self::label($i->payment_state), 'Hạn thanh toán' => $i->due_at->format('d/m/Y'), 'Tổng phải thu' => self::money($i->total), 'Đã thanh toán' => self::money($i->paid), 'Còn phải trả' => self::money($i->outstanding)];
            foreach ($this->can('invoice.view', $property, $i->rental->room_id) ? $i->items : [] as $index => $item) {
                $facts[($index + 1).'. '.$item->description] = $item->quantity.' × '.self::money($item->unit_price).' = '.self::money($item->amount);
            }
            foreach ($this->can('payment.view', $property, $i->rental->room_id) ? $i->payments : [] as $payment) {
                $facts['Thanh toán '.$payment->reference] = self::money($payment->amount).' · '.$payment->paid_at;
            }

            return $this->card('Hóa đơn '.$i->period, $i->rental->room->name, $facts, $forms) + ['payment_requests' => $this->can('payment.view', $property, $i->rental->room_id) ? PaymentRequest::where('invoice_id', $i->id)->cloudOrVerified()->latest()->limit(3)->get()->map(fn ($payment) => app(PaymentRequestPresenter::class)->present($this->user, $this->workspace, $payment))->all() : []];
        });
    }

    protected function utilities(): void
    {
        $props = $this->propertyOptions('utility.rate');
        $viewProps = $this->propertyOptions('utility.view');
        $rooms = $this->roomOptions('utility.record');
        $viewRooms = $this->roomOptions('utility.view');
        $rates = ServiceRate::whereIn('property_id', array_unique([...array_keys($props), ...array_keys($viewProps)]))->get();
        if ($props) {
            $this->vm['forms'][] = $this->form('Thêm đơn giá dịch vụ', 'service.create', [$this->field('property_id', 'Nhà trọ', 'select', $props), $this->field('name', 'Tên dịch vụ'), $this->field('kind', 'Cách tính', 'select', ['electricity' => 'Theo số điện', 'water' => 'Theo số nước', 'fixed' => 'Cố định mỗi tháng']), $this->field('rate', 'Đơn giá (đồng)', 'number'), $this->field('effective_from', 'Áp dụng từ', 'date')]);
        }
        if ($rooms && $rates->isNotEmpty()) {
            $this->vm['forms'][] = $this->form('Ghi chỉ số mới', 'meter.create', [$this->field('room_id', 'Phòng', 'select', $rooms), $this->field('service_rate_id', 'Dịch vụ và đơn giá', 'select', $rates->whereIn('kind', ['electricity', 'water'])->mapWithKeys(fn ($s) => [$s->id => $s->name.' · '.self::money($s->rate).' · '.$s->effective_from->format('d/m/Y')])->all()), $this->field('period', 'Kỳ ghi', 'month'), $this->field('current', 'Chỉ số hiện tại', 'number'), $this->field('reason', 'Lý do thay/đặt lại công tơ', 'textarea', required: false)]);
        }
        $this->records(MeterReading::with('room', 'rate')->whereIn('room_id', array_keys($viewRooms))->latest(), fn ($m) => $this->card($m->room->name.' · '.$m->rate->name, $m->period, ['Chỉ số trước' => $m->previous, 'Chỉ số mới' => $m->current, 'Mức sử dụng' => $m->usage, 'Ghi chú' => $m->reason ?: 'Không có']));
    }
}
