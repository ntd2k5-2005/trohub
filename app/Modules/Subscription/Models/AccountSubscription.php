<?php
namespace App\Modules\Subscription\Models;
use App\Support\BusinessModel;
final class AccountSubscription extends BusinessModel
{
    protected $table='account_subscriptions';
    protected function casts(): array {return parent::casts()+['trial_started_at'=>'datetime','authority_enabled_at'=>'datetime','epoch'=>'integer'];}
    public function planVersion(){return $this->belongsTo(PlanVersion::class);}
    public function owner(){return $this->belongsTo(\App\Models\User::class,'owner_id');}
}
