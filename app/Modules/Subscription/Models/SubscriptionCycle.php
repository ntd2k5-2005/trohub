<?php
namespace App\Modules\Subscription\Models;
use App\Support\BusinessModel;
final class SubscriptionCycle extends BusinessModel
{
    protected $table='subscription_cycles';
    protected function casts(): array{return parent::casts()+['verified_at'=>'datetime','starts_at'=>'datetime','ends_at'=>'datetime','reversed_at'=>'datetime','verified_amount'=>'integer','applied_at'=>'datetime'];}
    public function quote(){return $this->belongsTo(SubscriptionQuote::class,'subscription_quote_id');}
    protected static function booted(): void {static::updating(function($r){abort_if($r->isDirty(['account_subscription_id','subscription_quote_id','verified_amount','bank_reference','verified_by','verification_note','starts_at','ends_at','verified_at']),409,'Không được ghi lại chứng từ đã đối soát.');});}
}
