<?php

namespace Database\Seeders\Support;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Listing\ListingService;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class DemoV1Validator
{
    public function ids(string $table): array
    {
        return DB::table('demo_dataset_records')->where('dataset', DemoV1Import::DATASET)->where('table_name', $table)->pluck('record_id')->all();
    }
    public function query(string $table)
    {
        return DB::table($table)->whereIn('id', $this->ids($table));
    }
    public function validate(?string $password = null): array
    {
        $checks=[]; $counts=[];
        $check=function(string $name,bool $ok,mixed $detail=null)use(&$checks){$checks[]=['check'=>$name,'passed'=>$ok,'detail'=>$detail];};
        $targets=['users'=>300,'workspaces'=>35,'properties'=>70,'rooms'=>420,'listings'=>280,'rental_requests'=>320,'room_reservations'=>250,'rentals'=>250,'invoices'=>900,'payments'=>600,'chat_threads'=>180,'chat_messages'=>2500,'favorites'=>400,'roommate_needs'=>100,'maintenance_requests'=>90,'reports'=>70,'reviews'=>40,'devices'=>25,'safety_events'=>150,'user_notifications'=>1500,'domain_events'=>10000,'subscription_orders'=>120];
        foreach($targets as $table=>$target){$counts[$table]=$this->query($table)->count();$check('count.'.$table,$counts[$table]===$target,['expected'=>$target,'actual'=>$counts[$table]]);}
        $date=CarbonImmutable::parse(config('demo_v1.reference_date'))->startOfDay();
        $users=$this->query('users')->orderBy('id')->get();$bad=[];
        foreach($users as $i=>$u)if($u->email!=='user'.($i+1).'.demo@example.com'||!$u->email_verified_at||$u->email_activation_required||!$u->password_login_enabled||!in_array(password_get_info($u->password)['algoName'],['bcrypt','argon2id','argon2i'])||$password&&!Hash::check($password,$u->password))$bad[]=$u->id;
        $check('accounts.sequence_verified_hashed',$bad===[],$bad);
        $check('accounts.no_platform_authority',User::whereIn('id',$this->ids('users'))->get()->every(fn($u)=>!app(Authorization::class)->global($u,'access.bootstrap')&&!app(Authorization::class)->global($u,'subscription.approve')));
        $check('owners.manage_own_workspace',Workspace::whereIn('id',$this->ids('workspaces'))->get()->every(fn($w)=>app(Authorization::class)->allowed($w->owner,'property.view',$w)));
        $check('foreign_keys',config('database.default')!=='sqlite'||DB::select('PRAGMA foreign_key_check')===[]);
        foreach(['rooms'=>['occupied'=>145,'vacant'=>200,'reserved'=>20,'inactive'=>55],'listings'=>['published'=>180,'paused'=>60,'draft'=>40],'rentals'=>['active'=>145,'closed'=>55,'draft'=>20,'cancelled'=>30],'room_reservations'=>['completed'=>200,'holding'=>16,'deposit_confirmed'=>4,'expired'=>15,'released'=>15]]as$t=>$states){$actual=$this->query($t)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n','status')->all();$check('states.'.$t,$actual==$states,$actual);}
        $rids=$this->ids('rentals');$rentals=$this->query('rentals')->get()->keyBy('id');$rooms=$this->query('rooms')->get()->keyBy('id');$properties=$this->query('properties')->get()->keyBy('id');
        $bad=[];$activeRooms=[];$activeUsers=[];
        foreach($rentals as$r){if($r->ends_at<$r->starts_at||$r->status==='closed'&&$r->closed_at&&substr($r->closed_at,0,10)<$r->starts_at)$bad[]=$r->id;if($r->status==='active'){if(isset($activeRooms[$r->room_id])||isset($activeUsers[$r->tenant_id])||$rooms[$r->room_id]->status!=='occupied'||!$r->confirmed_at||!$r->signed_at||!$r->residency_started_at)$bad[]=$r->id;$activeRooms[$r->room_id]=true;$activeUsers[$r->tenant_id]=true;}}
        $signed=$rentals->whereNotNull('signed_at');$check('rentals.signed_count',$signed->count()===200,$signed->count());$check('rentals.dates_exclusive_and_occupancy',$bad===[],$bad);
        $holds=$this->query('room_reservations')->get();$bad=[];
        foreach($holds as$h){$r=$rentals[$h->rental_id];if($r->room_id!==$h->room_id||(in_array($h->status,['holding','deposit_confirmed'])&&($h->active_room_id!==$h->room_id||$rooms[$h->room_id]->status!=='reserved'||$h->hold_until<=$date->toDateTimeString()))||($h->status==='completed'&&!$r->signed_at))$bad[]=$h->id;}
        $check('holds.linked_exclusive_and_valid',$bad===[],$bad);
        $check('holds.activation_window_before_expiry',$holds->whereIn('status',['holding','deposit_confirmed'])->every(fn($h)=>$rentals[$h->rental_id]->starts_at<=substr($h->hold_until,0,10)));
        $check('draft_candidates.can_review_and_confirm',$rentals->where('status','draft')->every(fn($r)=>app(Authorization::class)->personal(User::findOrFail($r->tenant_id),'rental.view')&&app(Authorization::class)->personal(User::findOrFail($r->tenant_id),'tenant.confirm')));
        $check('notifications.workspace_scope_and_epoch',$this->query('user_notifications')->get()->every(fn($n)=>$n->scope_kind==='property'&&$n->workspace_id!==null&&(int)$n->cloud_epoch===1&&!str_contains($n->action_url??'','/ui/invitations')));
        $published=$this->query('listings')->where('status','published')->pluck('id')->all();
        $visible=app(ListingService::class)->available()->whereIn('id',$this->ids('listings'))->pluck('id')->all();sort($published);sort($visible);$check('public.available_exactly_published',$published===$visible,['expected'=>count($published),'visible'=>count($visible)]);
        $invoices=$this->query('invoices')->get();$states=['paid'=>0,'unpaid'=>0,'overdue'=>0];$bad=[];
        foreach($invoices as$v){$r=$rentals[$v->rental_id];$sum=DB::table('invoice_items')->where('invoice_id',$v->id)->sum('amount');$paid=DB::table('payments')->where('invoice_id',$v->id)->sum('amount');$state=$paid==$v->total?'paid':($v->due_at<$date->toDateString()?'overdue':'unpaid');$states[$state]++;if($sum!=$v->total||$paid>$v->total||$v->period<substr($r->starts_at,0,7)||$v->period>substr($r->ends_at,0,7)||!$v->issued_at||$r->status==='closed'&&$state!=='paid')$bad[]=$v->id;}
        $check('invoices.arithmetic_period_closed_debt',$bad===[],$bad);$check('invoices.payment_distribution',$states===['paid'=>600,'unpaid'=>170,'overdue'=>130],$states);
        $check('payments.all_cash',$this->query('payments')->where('method','!=','cash')->count()===0);
        $bad=[];$last=[];
        foreach($this->query('meter_readings')->orderBy('room_id')->orderBy('service_rate_id')->orderBy('period')->get()as$m){$k=$m->room_id.':'.$m->service_rate_id;if($m->current-$m->previous!=$m->usage||$m->usage<0||isset($last[$k])&&$last[$k]!=$m->previous)$bad[]=$m->id;$last[$k]=$m->current;}
        $check('utilities.continuous_nonnegative_readings',$bad===[],$bad);
        $connections=$this->query('connections')->get()->keyBy('id');$threads=$this->query('chat_threads')->get()->keyBy('id');$workspaces=$this->query('workspaces')->get()->keyBy('id');$listings=$this->query('listings')->get()->keyBy('id');$bad=[];
        foreach($this->query('chat_messages')->get()as$m){$t=$threads[$m->thread_id];$c=$connections[$t->context_id];$expected=$m->sender_side==='requester'?$c->user_id:$workspaces[$t->workspace_id]->owner_id;if($m->sender_id!==$expected||$listings[$c->listing_id]->status!=='published')$bad[]=$m->id;}
        $check('chat.real_participants_and_context',$bad===[],$bad);$check('viewings.count',$connections->whereNotNull('viewing_at')->count()===120);
        $bad=[];foreach($this->query('reviews')->get()as$v){$r=$rentals[$v->rental_id];if($r->tenant_id!==$v->user_id||$r->status!=='closed'||$v->created_at<$r->closed_at)$bad[]=$v->id;}$check('reviews.after_completed_rental',$bad===[],$bad);
        $bad=[];foreach($this->query('maintenance_requests')->get()as$m){$r=$rentals[$m->rental_id];if($r->room_id!==$m->room_id||$r->tenant_id!==$m->reporter_id||$m->created_at<$r->starts_at)$bad[]=$m->id;}$check('maintenance.tenant_room_scope',$bad===[],$bad);
        $bad=[];foreach($this->query('domain_events')->get()as$e){$p=json_decode($e->payload,true);if(!in_array((string)$p['resource_id'],$this->idsCached($p['resource_table']),true)||$p['actor_id']&&!$users->contains('id',$p['actor_id']))$bad[]=$e->id;}$check('history.real_resource_actor',$bad===[],$bad);
        $check('devices.simulated_only',$this->query('devices')->where('serial','not like',DemoV1Import::DATASET.'-SIM-%')->count()===0&&$this->query('safety_events')->get()->every(fn($e)=>(json_decode($e->payload,true)['simulated']??false)===true));
        $check('safety.supported_states',$this->query('safety_events')->whereNotIn('status',['open','acknowledged','closed'])->count()===0 && $this->query('safety_events')->whereNotIn('health',['healthy','fault','unknown'])->count()===0);
        $check('subscriptions.supported_states',$this->query('subscription_orders')->whereNotIn('status',['pending','confirmed'])->count()===0);
        $check('holds.deposit_exemption',$holds->where('status','deposit_confirmed')->every(fn($h)=>(int)$rentals[$h->rental_id]->deposit===0));
        $check('refunds.agreed_and_paid',$this->query('deposit_refund_decisions')->get()->every(fn($d)=>$d->status==='paid'&&$d->payer_accepted_at&&$d->refunded_at&&$d->refund_reference&&$d->amount_received-$d->deduction>=0));
        $check('rentals.cancelled_have_release_date',$this->query('rentals')->where('status','cancelled')->whereNull('closed_at')->count()===0);
        $bad=[];foreach(DB::table('demo_dataset_records')->where('dataset',DemoV1Import::DATASET)->select('table_name')->distinct()->pluck('table_name')as$table)if(\Illuminate\Support\Facades\Schema::hasColumns($table,['created_at','updated_at'])&&$this->query($table)->whereColumn('updated_at','<','created_at')->exists())$bad[]=$table;
        $check('history.updated_not_before_created',$bad===[],$bad);
        $bad=[];foreach($this->query('devices')->get()as$d){$e=$this->query('safety_events')->where('device_id',$d->id)->orderByDesc('event_time')->first();if(!$e||$d->risk!==$e->risk||$d->last_event_at!==$e->event_time)$bad[]=$d->id;}$check('devices.latest_event_matches_state',$bad===[],$bad);
        $integrity=app(\App\Modules\DataPlatform\AuditIntegrity::class)->verify();
        $check('audit.existing_integrity_chain',$integrity['valid']??$integrity['ok']??false,$integrity);
        $bad=[];foreach($this->query('housing_media')->get()as$m)if(!\Illuminate\Support\Facades\Storage::disk('local')->exists($m->path))$bad[]=$m->id;$check('images.local_files_present',$bad===[],['missing'=>count($bad)]);
        return ['dataset'=>DemoV1Import::DATASET,'reference_date'=>$date->toDateString(),'passed'=>!in_array(false,array_column($checks,'passed'),true),'counts'=>$counts,'checks'=>$checks];
    }
    private array $cached=[];
    private function idsCached(string$table):array{return $this->cached[$table]??=array_map('strval',$this->ids($table));}
}
