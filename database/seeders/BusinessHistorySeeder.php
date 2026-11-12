<?php
namespace Database\Seeders;

use App\Models\User;
use App\Modules\Subscription\Models\{AccountSubscription,PlanVersion,SubscriptionQuote,SubscriptionCycle};
use App\Modules\Subscription\PlatformPayee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Bounded historical import. No legacy update, notification, external payment or plan admission. */
final class BusinessHistorySeeder extends Seeder
{
    private array $plans=[],$accounts=[];private int $sequence=0;private string $dataset;private CarbonImmutable $date;
    public function preview():array
    {
        $owned=DB::table('demo_dataset_records')->where('dataset','TROHUB_DEMO_V1')->where('table_name','users')->pluck('record_id');
        $landlords=User::whereIn('id',$owned)->whereIn('id',DB::table('workspaces')->select('owner_id'))->where('status','active')->orderBy('id')->limit(config('business_history.existing_landlords'))->get();
        $new=User::whereIn('id',$owned)->where('status','active')->whereNotIn('id',DB::table('workspaces')->select('owner_id'))->whereNotExists(fn($q)=>$q->selectRaw('1')->from('memberships')->whereColumn('memberships.user_id','users.id'))->whereNotExists(fn($q)=>$q->selectRaw('1')->from('rentals')->whereColumn('rentals.tenant_id','users.id'))->orderBy('id')->limit(config('business_history.onboarding_accounts'))->get();
        return ['dataset'=>config('business_history.dataset'),'reference_date'=>config('business_history.reference_date'),'existing_landlord_ids'=>$landlords->modelKeys(),'onboarding_user_ids'=>$new->modelKeys(),'paid_target'=>$landlords->count()>=22&&$new->count()>=8?50:null,'pending_target'=>config('business_history.pending_quotes'),'closed_target'=>config('business_history.closed_quotes'),'new_users'=>0,'legacy_updates'=>0,'operational_plan_publications'=>0];
    }
    public function run():void
    {
        if(!app()->environment(['local','testing']))throw new RuntimeException('Historical simulation is restricted to local/testing');
        $this->dataset=config('business_history.dataset');$this->date=CarbonImmutable::parse(config('business_history.reference_date'),config('platform_business.timezone'))->setTime(12,0);
        DB::transaction(function(){
            DB::table('demo_dataset_runs')->insertOrIgnore(['dataset'=>$this->dataset,'reference_date'=>$this->date->toDateString(),'status'=>'preparing','created_at'=>now(),'updated_at'=>now()]);
            $run=DB::table('demo_dataset_runs')->where('dataset',$this->dataset)->lockForUpdate()->first();
            if($run->reference_date!==$this->date->toDateString())throw new RuntimeException('Do not change the reference date of an existing import');
            if($run->status==='complete'){echo "Business history already imported; no writes.\n";return;}
            if(DB::table('demo_dataset_records')->where('dataset',$this->dataset)->exists())throw new RuntimeException('Partial import must be reviewed');
            $selection=$this->preview();$owners=$selection['existing_landlord_ids'];$new=$selection['onboarding_user_ids'];
            if(count($owners)<22||count($new)<8)throw new RuntimeException('Insufficient existing eligible accounts; do not create people to reach a target');
            User::whereIn('id',[...$owners,...$new])->orderBy('id')->lockForUpdate()->get();
            if(AccountSubscription::whereIn('owner_id',[...$owners,...$new])->exists())throw new RuntimeException('Existing account subscription must not be overwritten');
            foreach(config('business_history.plans')as$key=>$p){
                $snapshot=$p+['plan'=>$key,'currency'=>'VND','billing_cycle'=>$key==='trial'?null:$key,'features'=>array_keys(config('subscriptions.feature_labels')),'limits'=>config('business_history.limits'),'data_origin'=>'simulation'];
                $plan=PlanVersion::create(['plan'=>$key,'snapshot'=>$snapshot,'fingerprint'=>hash('sha256',$this->dataset.json_encode($snapshot,JSON_THROW_ON_ERROR)),'published_at'=>'2025-01-01 08:00:00','availability'=>'archived','created_at'=>'2025-01-01 08:00:00','updated_at'=>'2025-01-01 08:00:00']);$this->record('plan_versions',$key,$plan->id);$this->plans[$key]=$plan;
            }
            // Existing synthetic person, registered before historical receipt dates. No current staff/right is created.
            $verifier=User::whereIn('id',DB::table('demo_dataset_records')->where('dataset','TROHUB_DEMO_V1')->where('table_name','users')->select('record_id'))->whereIn('id',DB::table('memberships')->select('user_id'))->where('created_at','<','2025-01-01')->where('status','active')->orderBy('id')->firstOrFail();
            foreach($owners as$i=>$id){
                $start=$i<12?CarbonImmutable::parse('2025-01-20 10:00:00')->addMonths($i):CarbonImmutable::parse('2025-02-15 10:00:00')->addMonths(($i-12)%6);
                if($i>=18)$start=CarbonImmutable::parse('2025-02-01 10:00:00')->addDays(($i-18)*10);
                $a=$this->account($id,$start->subDays(14),false);$cursor=$start;
                $keys=$i<12?['monthly','monthly']:($i<18?['yearly']:['monthly','monthly','yearly']);
                foreach($keys as$n=>$key){$paid=$n?$cursor->subDays(3):$cursor;$cursor=$this->paid($a,$key,$paid,$cursor,$verifier->id);}
                $a->update(['status'=>'expired','expires_at'=>$cursor]);
                if($i<config('business_history.closed_quotes'))$this->quote($a,'monthly',$start->subDays(8),$i<3?'cancelled':'pending',false);
            }
            $a=$this->account($new[0],$this->date->subDays(5),true);$a->update(['status'=>'trial','expires_at'=>$this->date->subDays(5)->addDays(config('business_history.plans.trial.duration_count'))]);
            $patterns=[1=>[['monthly','2026-11-02',null]],2=>[['yearly','2026-11-03',null]],3=>[['monthly','2026-09-05',null],['monthly','2026-10-02','2026-10-05'],['monthly','2026-11-02','2026-11-05']],5=>[['monthly','2026-10-07',null],['monthly','2026-11-04','2026-11-07']],6=>[['yearly','2025-10-01',null]]];
            foreach($patterns as$index=>$cycles){$first=CarbonImmutable::parse($cycles[0][1])->setTime(10,0);$a=$this->account($new[$index],$first->subDays(14),true);$end=$first;
                foreach($cycles as[$key,$at,$from]){$paid=CarbonImmutable::parse($at)->setTime(10,0);$end=$this->paid($a,$key,$paid,$from?CarbonImmutable::parse($from)->setTime(10,0):$paid,$verifier->id);}
                $a->update(['status'=>$end->gt($this->date)?'active':'expired','expires_at'=>$end]);
            }
            foreach([4,7]as$index){$a=$this->account($new[$index],$this->date->subDays(2),true);$a->update(['plan_version_id'=>$this->plans['monthly']->id,'status'=>'pending','trial_started_at'=>null,'expires_at'=>$this->date->subDays(2)]);}
            $pending=[0,0,1,2,3,4,4,5,6,6,7,7];foreach($pending as$n=>$index)$this->quote($this->accounts[$new[$index]],$n%3===0?'yearly':'monthly',$this->date->subMinutes($n*20),'pending',$n%2===0);
            $this->invoiceExamples();
            $counts=[];foreach(DB::table('demo_dataset_records')->where('dataset',$this->dataset)->select('table_name')->selectRaw('COUNT(*) count')->groupBy('table_name')->get()as$row)$counts[$row->table_name]=(int)$row->count;
            if(($counts['subscription_cycles']??0)!==50||($counts['subscription_quotes']??0)>90)throw new RuntimeException('Bounded import count mismatch');
            DB::table('demo_dataset_runs')->where('dataset',$this->dataset)->update(['status'=>'complete','counts'=>json_encode($counts),'updated_at'=>now()]);echo "Business history imported: 30 existing accounts, 50 receipts, 12 pending and 6 closed quotes (cancelled or expired).\n";
        });
    }
    private function record(string $table,string|int $key,int $id):void{DB::table('demo_dataset_records')->insert(['dataset'=>$this->dataset,'table_name'=>$table,'demo_key'=>(string)$key,'record_id'=>(string)$id,'created_at'=>now()]);}
    private function account(int $owner,CarbonImmutable $from,bool $authority):AccountSubscription
    {
        $a=AccountSubscription::create(['owner_id'=>$owner,'plan_version_id'=>$this->plans['trial']->id,'status'=>'trial','trial_started_at'=>$from,'expires_at'=>$from->addDays(config('business_history.plans.trial.duration_count')),'authority_enabled_at'=>$authority?$from:null,'version'=>1,'epoch'=>1,'created_at'=>$from,'updated_at'=>$from]);$this->record('account_subscriptions',$owner,$a->id);$this->accounts[$owner]=$a;return $a;
    }
    private function quote(AccountSubscription $a,string $key,CarbonImmutable $at,string $status,bool $reported):SubscriptionQuote
    {
        $number=++$this->sequence;$payee=app(PlatformPayee::class)->details();if(!$payee)throw new RuntimeException('A configured platform receiving account is required; it is not modified');
        $q=SubscriptionQuote::create(['account_subscription_id'=>$a->id,'plan_version_id'=>$this->plans[$key]->id,'amount'=>$this->plans[$key]->snapshot['price'],'currency'=>'VND','reference'=>'TH-'.$at->format('Ymd').'-'.str_pad((string)$number,4,'0',STR_PAD_LEFT),'expected_version'=>$a->version,'expires_at'=>$at->addDay(),'status'=>$status,'payee_snapshot'=>$payee,'receiving_account_id'=>$payee['receiving_account_id']??null,'transfer_reference'=>'THP'.$at->format('ymd').str_pad((string)$number,6,'0',STR_PAD_LEFT),'payer_reported_at'=>$reported?$at->addMinutes(20):null,'payer_bank_reference'=>$reported?'GD'.$at->format('Ymd').str_pad((string)$number,5,'0',STR_PAD_LEFT):null,'payer_note'=>$reported?'Thông tin thanh toán mô phỏng. Không phải giao dịch ngân hàng thật.':null,'cancelled_at'=>$status==='cancelled'?$at->addHours(2):null,'cancellation_reason'=>$status==='cancelled'?'Khách thay đổi thời điểm bắt đầu sử dụng.':null,'created_at'=>$at,'updated_at'=>$at]);$this->record('subscription_quotes','quote:'.$number,$q->id);return $q;
    }
    private function paid(AccountSubscription $a,string $key,CarbonImmutable $paid,CarbonImmutable $from,int $verifier):CarbonImmutable
    {
        $q=$this->quote($a,$key,$paid->subHours(2),'confirmed',true);$end=$key==='yearly'?$from->addYearNoOverflow():$from->addMonthNoOverflow();
        $c=SubscriptionCycle::create(['account_subscription_id'=>$a->id,'subscription_quote_id'=>$q->id,'verified_amount'=>$q->amount,'bank_reference'=>'THB-'.$paid->format('Ymd').'-'.$q->id,'verified_by'=>$verifier,'verification_note'=>'Lịch sử xác nhận mô phỏng; không phải bằng chứng nhận tiền ngân hàng.','starts_at'=>$from,'ends_at'=>$end,'verified_at'=>$paid,'application_state'=>'applied','applied_at'=>$from,'created_at'=>$paid,'updated_at'=>$paid]);$this->record('subscription_cycles','receipt:'.$q->id,$c->id);$a->update(['plan_version_id'=>$this->plans[$key]->id,'current_cycle_id'=>$c->id,'version'=>$a->version+1,'expires_at'=>$end,'status'=>'active']);return $end;
    }
    private function invoiceExamples():void
    {
        $query=\App\Modules\Billing\Models\Invoice::with('rental.room.property.workspace')->whereNotNull('issued_at')->whereDoesntHave('payments')->where('total','>',1)->whereHas('rental.room.property.workspace',fn($w)=>$w->where('provenance','synthetic'));
        $multi=DB::table('workspaces')->select('owner_id')->groupBy('owner_id')->havingRaw('COUNT(*)>1')->pluck('owner_id');
        $first=(clone$query)->whereHas('rental.room.property.workspace',fn($w)=>$w->whereIn('owner_id',$multi))->first();
        $second=(clone$query)->whereIn('id',\App\Support\SimulationOrigin::ids('invoices'))->when($first,fn($q)=>$q->whereKeyNot($first->id))->first();
        $previous=\Illuminate\Support\Carbon::getTestNow();try{
            foreach(array_filter([$first,$second])as$index=>$invoice){\Illuminate\Support\Carbon::setTestNow($this->date->subDays(6-$index)->setTime(10,30));$owner=$invoice->rental->room->property->workspace->owner;
                $payment=app(\App\Modules\Billing\BillingService::class)->pay($owner,$invoice,['amount'=>$index===0?intdiv($invoice->total,2):$invoice->total,'method'=>'cash','reference'=>'PT-'.$this->date->format('Ym').'-'.str_pad((string)$invoice->id,5,'0',STR_PAD_LEFT)]);
                $this->record('payments','invoice-receipt:'.$invoice->id,$payment->id);
            }
        }finally{\Illuminate\Support\Carbon::setTestNow($previous);}
    }
}
