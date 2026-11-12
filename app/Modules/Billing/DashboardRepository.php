<?php
namespace App\Modules\Billing;
use App\Modules\Billing\Models\{Invoice,Payment};
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Models\Rental;
use App\Support\{ReportingPeriod,SimulationOrigin,Vnd};

final class DashboardRepository
{
    public function summarize(array $scope,ReportingPeriod $period):array
    {
        [$from,$until]=$period->bounds();$limit=config('dashboards.preview_limit');$series=[];$by=[];
        foreach($scope['selected']as$w)$by[$w->id]=['label'=>$w->name,'received'=>'0','outstanding'=>'0'];
        $received='0';$simulation='0';$outstanding='0';$overdue=0;$overdueAmount='0';$invoiceCount=0;$partial=0;$discrepancies=0;
        $simulated=array_fill_keys(SimulationOrigin::ids('payments')->pluck('record_id')->all(),true);
        $payments=Payment::with('invoice.rental.room.property')->where('paid_at','>=',$from)->where('paid_at','<',$until)->whereHas('invoice',fn($i)=>$i->whereNotNull('issued_at')->whereHas('rental',fn($r)=>$r->whereIn('room_id',$scope['rooms']['payment.view'])));
        foreach($payments->orderBy('id')->lazyById(200)as$p){$money=(string)$p->getRawOriginal('amount');$received=Vnd::add($received,$money);if(isset($simulated[$p->id]))$simulation=Vnd::add($simulation,$money);$bucket=$period->bucket($p->getRawOriginal('paid_at'));$series[$bucket]=Vnd::add($series[$bucket]??'0',$money);$ws=$p->invoice->rental->room->property->workspace_id;$by[$ws]['received']=Vnd::add($by[$ws]['received'],$money);}
        $invoices=Invoice::with('rental.room.property')->withSum('payments as received_amount','amount')->whereNotNull('issued_at')->where('issued_at','<=',now())->whereHas('rental',fn($r)=>$r->whereIn('room_id',$scope['rooms']['invoice.view']));
        $overdueItems=[];$pendingItems=[];
        foreach($invoices->orderBy('id')->lazyById(200)as$i){$invoiceCount++;$paid=(int)($i->received_amount??0);$missing=max(0,$i->total-$paid);$outstanding=Vnd::add($outstanding,(string)$missing);$ws=$i->rental->room->property->workspace_id;$by[$ws]['outstanding']=Vnd::add($by[$ws]['outstanding'],(string)$missing);
            if($paid>0&&$missing>0)$partial++;if($i->status==='paid'&&$missing>0)$discrepancies++;
            if($missing>0){$item=['title'=>$i->rental->room->name,'subtitle'=>'Hóa đơn '.$i->period,'value'=>Vnd::format($missing),'workspace_id'=>$ws,'id'=>$i->id,'room_id'=>$i->rental->room_id,'kind'=>'invoice','due_at'=>$i->due_at->format('Y-m-d')];$pendingItems[]=$item;
                if($i->due_at->copy()->endOfDay()->isPast()){$overdue++;$overdueAmount=Vnd::add($overdueAmount,(string)$missing);$overdueItems[]=$item;}
            }
        }
        usort($overdueItems,fn($a,$b)=>$a['due_at']<=>$b['due_at']);usort($pendingItems,fn($a,$b)=>$a['due_at']<=>$b['due_at']);
        $rooms=Room::whereIn('id',$scope['rooms']['property.view'])->whereNull('retired_at')->whereHas('property',fn($p)=>$p->whereNull('retired_at'));$states=['occupied'=>0,'vacant'=>0,'reserved'=>0,'inactive'=>0];$vacancies=[];
        foreach($rooms->with('property')->get()as$r){$key=array_key_exists($r->status,$states)?$r->status:'inactive';$states[$key]++;if($key==='vacant'&&count($vacancies)<$limit)$vacancies[]=['title'=>$r->name,'subtitle'=>$r->property->name,'value'=>Vnd::format($r->rent),'workspace_id'=>$r->property->workspace_id,'id'=>$r->id,'kind'=>'room'];}
        $contracts=Rental::with('room.property','tenant')->whereIn('room_id',$scope['rooms']['rental.view'])->whereIn('status',['active','move_out_pending'])->where('starts_at','<=',today())->whereNotNull('ends_at')->where('ends_at','<=',today()->addDays(config('dashboards.expiring_contract_days')))->orderBy('ends_at')->limit($limit)->get()->map(fn($r)=>['title'=>$r->room->name,'subtitle'=>$r->tenant?->name??'Người thuê','value'=>\Illuminate\Support\Carbon::parse($r->ends_at)->format('d/m/Y'),'workspace_id'=>$r->room->property->workspace_id,'id'=>$r->id,'kind'=>'rental'])->all();
        ksort($series);$units=$states['occupied']+$states['vacant']+$states['reserved'];
        return ['received'=>$received,'simulation_received'=>$simulation,'outstanding'=>$outstanding,'overdue'=>$overdue,'overdue_amount'=>$overdueAmount,'invoice_count'=>$invoiceCount,'partial_invoices'=>$partial,'mismatched_paid_flags'=>$discrepancies,'occupied'=>$states['occupied'],'exploited_units'=>$units,'occupancy'=>$units?round($states['occupied']*100/$units,1):0,'series'=>$series,'workspaces'=>array_values($by),'room_states'=>$states,'worklists'=>['Hóa đơn quá hạn'=>array_slice($overdueItems,0,$limit),'Hóa đơn chờ ghi nhận thanh toán'=>array_slice($pendingItems,0,$limit),'Hợp đồng cần gia hạn'=>$contracts,'Phòng còn trống'=>$vacancies]];
    }
}
