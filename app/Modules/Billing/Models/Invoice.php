<?php

namespace App\Modules\Billing\Models;

use App\Modules\Rental\Models\Rental;
use App\Support\BusinessModel;

class Invoice extends BusinessModel
{
    protected $table = 'invoices';

    public function getRoomAttribute(){return $this->rental->room;}
    public function getRoomIdAttribute():int{return $this->rental->room_id;}
    public function getTitleAttribute():string{return 'Hóa đơn '.$this->period;}

    public function rental()
    {
        return $this->belongsTo(Rental::class, 'rental_id');
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }

    public function getPaidAttribute(): int
    {
        return (int) $this->payments()->sum('amount');
    }

    public function getOutstandingAttribute(): int
    {
        return max(0, $this->total - $this->paid);
    }

    public function getPaymentStateAttribute(): string
    {
        if (! $this->issued_at) {
            return 'draft';
        } if ($this->outstanding === 0) {
            return 'paid';
        } if ($this->due_at->endOfDay()->isPast()) {
            return 'overdue';
        }

        return $this->paid > 0 ? 'partial' : 'unpaid';
    }
}
