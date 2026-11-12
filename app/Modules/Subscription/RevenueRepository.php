<?php

namespace App\Modules\Subscription;

use App\Models\User;
use App\Modules\Subscription\Models\SubscriptionCycle;
use Illuminate\Support\Carbon;

final class RevenueRepository
{
    public function query(User $actor, BusinessPeriod $period)
    {
        app(BusinessAccess::class)->authorize($actor,'subscription.revenue.view');
        [$from,$until]=$period->bounds();
        $query=SubscriptionCycle::with('quote.planVersion','quote.accountSubscription.owner')->whereNull('reversed_at')->whereNotNull('verified_at')->where('verified_amount','>',0)->where('verified_at','>=',$from)->where('verified_at','<',$until)
            ->whereHas('quote',fn($q)=>$q->where('currency','VND')->where('status','confirmed')->whereHas('planVersion',fn($p)=>$p->whereIn('plan',$period->plan?[$period->plan]:['monthly','yearly'])))
            ->when($period->q!=='',fn($q)=>$q->where(fn($q)=>$q->where('bank_reference','like','%'.$period->q.'%')->orWhereHas('quote.accountSubscription.owner',fn($u)=>$u->where('name','like','%'.$period->q.'%')->orWhere('email','like','%'.$period->q.'%'))));
        \App\Support\SimulationOrigin::filter($query,'subscription_cycles',$period->source);return $query;
    }
    public function totals(User $actor,BusinessPeriod $period): array
    {
        $total='0';$simulation='0';$operational='0';$byPlan=['monthly'=>'0','yearly'=>'0'];$series=[];$count=0;
        $simulated=array_fill_keys(\App\Support\SimulationOrigin::ids('subscription_cycles')->pluck('record_id')->all(),true);
        foreach($this->query($actor,$period)->orderBy('id')->lazyById(200)as$row){
            $amount=(string)$row->getRawOriginal('verified_amount');$total=Vnd::add($total,$amount);$key=$row->quote->planVersion->plan;$byPlan[$key]=Vnd::add($byPlan[$key],$amount);$count++;
            $date=$this->date($row)->format($period->from->diffInDays($period->until)>62?'Y-m':'Y-m-d');$series[$date]=Vnd::add($series[$date]??'0',$amount);
            if(isset($simulated[$row->id]))$simulation=Vnd::add($simulation,$amount);else$operational=Vnd::add($operational,$amount);
        }
        ksort($series);return ['received'=>$total,'transactions'=>$count,'monthly'=>$byPlan['monthly'],'yearly'=>$byPlan['yearly'],'series'=>$series,'simulation_received'=>$simulation,'operational_received'=>$operational];
    }
    public function date(SubscriptionCycle $cycle): Carbon { return Carbon::parse($cycle->getRawOriginal('verified_at'),config('platform_business.storage_timezone'))->setTimezone(config('platform_business.timezone')); }
}
