<?php

namespace App\Modules\Billing\Models;

use App\Modules\Rental\Models\Rental;
use App\Support\BusinessModel;

class PaymentRequest extends BusinessModel
{
    protected static function booted(): void {static::creating(function($r){if(\Illuminate\Support\Facades\Schema::hasColumn('payment_requests','workspace_at_event'))$r->workspace_at_event??=$r->workspace_id;});}
    protected $hidden = ['payee_snapshot'];
    public function getRoomAttribute(){return $this->rental->room;}
    public function getRoomIdAttribute():int{return $this->rental->room_id;}
    public function getTitleAttribute():string{return 'Đối soát khoản '.($this->kind==='deposit'?'cọc':'hóa đơn').' · '.$this->rental->room->name;}

    public function scopeCloudOrVerified(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where(fn ($q) => $q->where('status', 'verified')
            ->orWhereExists(fn ($tx) => $tx->selectRaw('1')->from('payment_request_transactions')->whereColumn('payment_request_id', 'payment_requests.id')->whereNotNull('verified_at'))
            ->orWhereHas('rental.room.property.workspace', fn ($w) => app(\App\Modules\Subscription\PropertyCloud::class)->eligible($w)));
    }

    protected function casts(): array
    {
        return parent::casts() + ['payee_snapshot' => 'encrypted:array', 'verified_at' => 'datetime'];
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
