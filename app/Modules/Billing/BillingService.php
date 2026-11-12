<?php

namespace App\Modules\Billing;

use App\Contracts\Authorization as AccessService;
use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\MeterReading;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\ServiceRate;
use App\Modules\DataPlatform\Journal;
use App\Modules\Notification\NotificationService;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Models\Rental;
use App\Support\ScopedRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BillingService
{
    public function __construct(private ScopedRepository $scope, private Journal $journal) {}

    public function remindDueInvoices(): int
    {
        $sent = 0;
        Invoice::with('rental.room')->whereNotNull('issued_at')->where('due_at', '<=', today()->addDays(config('business.payment_reminder_days', 3))->endOfDay())->chunkById(100, function ($invoices) use (&$sent) {
            foreach ($invoices as $invoice) {
                if (! app(\App\Modules\Subscription\PropertyCloud::class)->currentOperation($invoice->rental->room->property->workspace, $invoice->issued_at, $invoice->cloud_epoch)) continue;
                if ($invoice->outstanding <= 0 || ! $invoice->rental->tenant_id) {
                    continue;
                }
                $sent += (int) app(NotificationService::class)->once($invoice->rental->tenant_id, 'invoice:'.$invoice->id.':due:'.today()->toDateString(), $invoice->due_at->isPast() ? 'Hóa đơn đã quá hạn' : 'Hóa đơn sắp đến hạn', 'Hóa đơn '.$invoice->period.' của phòng '.$invoice->rental->room->name.' còn '.number_format($invoice->outstanding, 0, ',', '.').' đồng. Hạn thanh toán '.$invoice->due_at->format('d/m/Y').'.', workspace: $invoice->rental->room->property->workspace_id);
            }
        });

        return $sent;
    }

    public function createRate(User $user, array $data): void
    {
        $property = Property::findOrFail($data['property_id']);
        app(AccessService::class)->authorize($user, 'utility.rate', $property->workspace, $property->id);
        app(\App\Modules\Subscription\PropertyCloud::class)->transaction($property->workspace_id, function () use ($user, $property, $data) {
            Property::whereKey($property->id)->lockForUpdate()->firstOrFail();
            $rate = ServiceRate::create($data);
            $this->journal->record($user->id, 'service.created', $rate, $property->workspace_id);
        }, function () use ($user, $property) {
            $current = Property::with('workspace')->findOrFail($property->id);
            abort_unless($current->workspace_id === $property->workspace_id && ! $current->retired_at, 409);
            app(AccessService::class)->authorize($user, 'utility.rate', $current->workspace, $current->id);
        });
    }

    private function ensure(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['billing' => $message]);
        }
    }

    public function reading(User $user, array $data): MeterReading
    {
        $room = $this->scope->managedRoom($user, $data['room_id'], 'utility.record');

        return app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($user, $room->id, 'utility.record', function () use ($user, $room, $data) {
            Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            $rate = ServiceRate::where('property_id', $room->property_id)->findOrFail($data['service_rate_id']);
            $effective = ServiceRate::where('property_id', $room->property_id)->where('name', $rate->name)->whereDate('effective_from', '<=', $data['period'].'-01')->orderByDesc('effective_from')->first();
            $this->ensure($effective?->id === $rate->id, 'Hãy chọn đơn giá có hiệu lực tại đầu kỳ ghi chỉ số.');
            $this->ensure(in_array($rate->kind, ['electricity', 'water'], true), 'Dịch vụ không ghi chỉ số.');
            $latest = MeterReading::where('room_id', $room->id)->whereHas('rate', fn ($q) => $q->where('name', $rate->name))->orderByDesc('period')->first();
            $this->ensure(! $latest || $data['period'] > $latest->period, 'Kỳ ghi phải sau kỳ gần nhất; chỉ số đã chốt không được ghi đè.');
            $previous = $latest?->current ?? 0;
            $this->ensure($data['current'] >= $previous || ! empty($data['reason']), 'Chỉ số nhỏ hơn kỳ trước cần lý do thay hoặc đặt lại công tơ.');
            $reading = MeterReading::create($data + ['previous' => $previous, 'usage' => $data['current'] >= $previous ? $data['current'] - $previous : $data['current'], 'actor_id' => $user->id]);
            $this->journal->record($user->id, 'meter.recorded', $reading, $room->property->workspace_id);

            return $reading;
        });
    }

    public function generate(User $user, Rental $rental, string $period, string $due): Invoice
    {
        $room = $this->scope->managedRoom($user, $rental->room_id, 'invoice.create');

        return app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($user, $room->id, 'invoice.create', function () use ($user, $rental, $room, $period, $due) {
            Rental::whereKey($rental->id)->lockForUpdate()->firstOrFail();
            $this->ensure(in_array($rental->status, ['active', 'move_out_pending'], true), 'Chỉ lập hóa đơn cho quan hệ thuê đang hiệu lực.');
            $this->ensure(! $rental->invoices()->where('period', $period)->exists(), 'Kỳ này đã có hóa đơn.');
            $this->ensure($period >= substr($rental->starts_at, 0, 7), 'Kỳ hóa đơn trước ngày bắt đầu thuê.');
            $invoice = Invoice::create(['rental_id' => $rental->id, 'period' => $period, 'due_at' => $due, 'total' => 0, 'cloud_epoch' => $room->property->workspace->cloud_epoch]);
            $lines = [['description' => 'Tiền phòng', 'quantity' => 1, 'unit_price' => $rental->rent, 'amount' => $rental->rent]];
            $rates = ServiceRate::where('property_id', $room->property_id)->whereDate('effective_from', '<=', $period.'-01')->orderByDesc('effective_from')->get()->unique('name');
            foreach ($rates as $rate) {
                $qty = 1;
                if (in_array($rate->kind, ['electricity', 'water'], true)) {
                    $reading = MeterReading::where('room_id', $room->id)->where('period', $period)->where('service_rate_id', $rate->id)->first();
                    $this->ensure($reading !== null, 'Thiếu chỉ số '.$rate->name.' cho kỳ hóa đơn.');
                    $qty = $reading->usage;
                }
                $lines[] = ['description' => $rate->name, 'quantity' => $qty, 'unit_price' => $rate->rate, 'amount' => (int) round($qty * $rate->rate)];
            }
            foreach ($lines as $line) {
                $invoice->items()->create($line + ['actor_id' => $user->id]);
            }
            $invoice->update(['total' => array_sum(array_column($lines, 'amount'))]);
            app(\App\Contracts\WorkAssignments::class)->claim($user,$room->property->workspace_id,'invoice_draft',$invoice->id,'invoice.create');
            $this->journal->record($user->id, 'invoice.created', $invoice, $room->property->workspace_id);

            return $invoice;
        });
    }

    public function issue(User $user, Invoice $invoice): void
    {
        $room = $this->scope->managedRoom($user, $invoice->rental->room_id, 'invoice.issue');
        app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($user, $room->id, 'invoice.issue', function () use ($user, $room, $invoice) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->ensure(! $invoice->issued_at && $invoice->total > 0, 'Hóa đơn không đủ điều kiện phát hành.');
            app(\App\Contracts\WorkAssignments::class)->assertCurrent($user,$room->property->workspace_id,'invoice_draft',$invoice->id);
            $invoice->update(['issued_at' => now(), 'status' => 'issued']);
            app(\App\Contracts\WorkAssignments::class)->complete($room->property->workspace_id,'invoice_draft',$invoice->id);
            $this->journal->record($user->id, 'invoice.issued', $invoice, $room->property->workspace_id);
            $this->journal->notify($invoice->rental->tenant_id, 'Hóa đơn mới', 'Hóa đơn tháng '.$invoice->period.' đã được phát hành.', workspace: $room->property->workspace_id);
        });
    }

    public function adjust(User $user, Invoice $invoice, int $amount, string $reason): void
    {
        $room = $this->scope->managedRoom($user, $invoice->rental->room_id, 'invoice.adjust');
        app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($user, $room->id, 'invoice.adjust', function () use ($user, $invoice, $amount, $reason, $room) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if(!$invoice->issued_at)app(\App\Contracts\WorkAssignments::class)->assertCurrent($user,$room->property->workspace_id,'invoice_draft',$invoice->id);
            $this->ensure($invoice->total + $amount >= $invoice->paid, 'Điều chỉnh không được nhỏ hơn số đã thanh toán.');
            $invoice->items()->create(['description' => $reason, 'quantity' => 1, 'unit_price' => $amount, 'amount' => $amount, 'adjustment' => true, 'actor_id' => $user->id]);
            $invoice->increment('total', $amount);
            $this->journal->record($user->id, 'invoice.adjusted', $invoice, $room->property->workspace_id, ['amount' => $amount, 'reason' => $reason]);
        });
    }

    public function pay(User $user, Invoice $invoice, array $data): Payment
    {
        $room = $this->scope->managedRoom($user, $invoice->rental->room_id, 'payment.record');
        if (($data['method'] ?? null) !== 'cash') {
            throw ValidationException::withMessages(['method' => 'Khoản chuyển khoản cần được gửi để đối soát và xác nhận trong yêu cầu thanh toán.']);
        }

        return $this->persistPayment($user, $invoice, $data, $room, 'payment.record');
    }

    public function verifyPayment(User $user, Invoice $invoice, array $data): Payment
    {
        $room = $this->scope->managedRoom($user, $invoice->rental->room_id, 'payment.verify');

        return $this->persistPayment($user, $invoice, $data, $room, 'payment.verify');
    }

    private function persistPayment(User $user, Invoice $invoice, array $data, Room $room, string $permission): Payment
    {

        return app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($user, $room->id, $permission, function () use ($user, $invoice, $data, $room) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $existing = Payment::where('reference', $data['reference'])->first();
            if ($existing) {
                $this->ensure($existing->invoice_id === $invoice->id && $existing->amount === (int) $data['amount'], 'Mã giao dịch đã được sử dụng cho khoản thanh toán khác.');

                return $existing;
            }
            $this->ensure($invoice->issued_at !== null, 'Hóa đơn chưa phát hành hoặc chưa được ghi nhận thanh toán thực tế.');
            $this->ensure($data['amount'] > 0 && $data['amount'] <= $invoice->outstanding, 'Số tiền phải lớn hơn 0 và không vượt công nợ.');
            $payment = Payment::create($data + ['invoice_id' => $invoice->id, 'actor_id' => $user->id, 'paid_at' => now()]);
            \App\Support\SimulationOrigin::inherit('invoices',$invoice->id,'payments',$payment->id);
            $this->journal->record($user->id, 'payment.recorded', $payment, $room->property->workspace_id, ['amount' => $payment->amount]);
            $this->journal->notify($invoice->rental->tenant_id, 'Đã ghi nhận thanh toán', 'Khoản thanh toán hóa đơn tháng '.$invoice->period.' đã được ghi nhận.', workspace: $room->property->workspace_id);

            return $payment;
        });
    }
}
