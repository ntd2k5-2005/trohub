<?php
namespace Tests\Feature;
use App\Models\User;
use App\Modules\Billing\{DashboardRepository,DashboardScope};
use App\Modules\Billing\Models\{Invoice,Payment};
use App\Modules\Property\Models\{Property,Room};
use App\Modules\Rental\Models\{Rental,Deposit};
use App\Modules\Workspace\Models\{Workspace,Membership};
use App\Support\{ReportingPeriod,SimulationOrigin};
use Database\Seeders\{DatabaseSeeder,PresentationCleanupSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Http,Mail};
use Tests\TestCase;

class DashboardDataTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->travelTo(\Carbon\Carbon::parse('2026-11-10 12:00:00'));Mail::fake();Http::preventStrayRequests();$this->seed(DatabaseSeeder::class);}
    private function user(string $key):User{return User::where('email',$key.'@trohub.test')->firstOrFail();}
    private function ws():Workspace{return Workspace::where('owner_id',$this->user('owner')->id)->oldest('id')->firstOrFail();}
    private function url(array $params=[]):string{return '/ui/workspace-dashboard?'.http_build_query(['context'=>'workspace','workspace_id'=>$this->ws()->id]+$params);}
    private function fixture():Invoice
    {
        $invoice=Invoice::firstOrFail();$invoice->update(['issued_at'=>now()->subDays(15),'due_at'=>today()->subDay(),'total'=>1000,'status'=>'paid']);
        Payment::create(['invoice_id'=>$invoice->id,'amount'=>100,'method'=>'cash','reference'=>'DASH-A','actor_id'=>$this->user('owner')->id,'paid_at'=>now()->subDays(2)]);
        Payment::create(['invoice_id'=>$invoice->id,'amount'=>150,'method'=>'cash','reference'=>'DASH-B','actor_id'=>$this->user('owner')->id,'paid_at'=>now()->subDay()]);return $invoice;
    }
    private function metrics(array $params=[]):array{return $this->get($this->url($params))->assertOk()->viewData('screen')['dashboard']['metrics'];}
    public function test_cash_debt_partial_and_overdue_use_payments_without_join_duplication():void
    {
        $i=$this->fixture();$this->actingAs($this->user('owner'));$m=$this->metrics();$this->assertSame('250',$m['received']);$this->assertSame('750',$m['outstanding']);$this->assertSame(1,$m['overdue']);$this->assertSame(1,$m['partial_invoices']);$this->assertSame(1,$m['mismatched_paid_flags']);$this->assertSame(250,array_sum($m['series']));
        Deposit::where('rental_id',$i->rental_id)->update(['amount'=>9900000]);$this->assertSame('250',$this->metrics()['received']);
        $link=$this->get($this->url())->viewData('screen')['dashboard']['worklists'][0]['items'][0]['url'];$this->assertStringContainsString('invoice_id='.$i->id,$link);$this->get($link)->assertOk()->assertSee($i->period);
    }
    public function test_scope_all_and_selected_are_consistent_and_do_not_include_other_owner():void
    {
        $this->fixture();$this->actingAs($this->user('owner'));$one=$this->metrics();$all=$this->metrics(['workspace_scope'=>'all']);$ids=Workspace::where('owner_id',$this->user('owner')->id)->pluck('id')->all();$selected=$this->metrics(['workspace_scope'=>'selected','workspace_ids'=>$ids]);
        $this->assertSame($one['received'],$all['received']);$this->assertSame($all,$selected);$this->assertCount(2,$all['workspaces']);$this->assertGreaterThan($one['exploited_units'],$all['exploited_units']);
        $other=Workspace::where('owner_id',$this->user('other')->id)->value('id');$this->get($this->url(['workspace_scope'=>'selected','workspace_ids'=>[$other]]))->assertForbidden();$this->get($this->url(['workspace_scope'=>'all','workspace_ids'=>[$other]]))->assertForbidden();
    }
    public function test_empty_scope_and_revocation_cannot_leak_financial_data():void
    {
        $this->fixture();$this->actingAs($this->user('tenant'))->get($this->url())->assertForbidden();
        $this->actingAs($this->user('owner'));$this->get($this->url(['workspace_scope'=>'selected']))->assertForbidden();
        $staff=$this->user('accountant');$this->actingAs($staff)->get($this->url())->assertOk();Membership::where('user_id',$staff->id)->update(['status'=>'ended']);$this->get($this->url())->assertForbidden();
    }
    public function test_resource_scoped_invoice_permission_cannot_read_sibling_room_or_cash():void
    {
        $this->fixture();$staff=$this->user('roomstaff');$member=Membership::where('user_id',$staff->id)->firstOrFail();
        DB::table('access_groups')->where('id',$member->access_group_id)->update(['permissions'=>json_encode(['invoice.view'])]);
        $this->actingAs($staff);$r=$this->get($this->url(['tab'=>'business']))->assertOk();$d=$r->viewData('screen')['dashboard'];$this->assertSame('0',$d['metrics']['received']);$this->assertSame('Chưa có quyền xem',$d['kpis'][0]['value']);
        $allowed=app(\App\Contracts\Authorization::class)->roomIds($staff,$this->ws(),'invoice.view');$invoice=Invoice::first();$this->assertSame(in_array($invoice->rental->room_id,$allowed)?'750':'0',$d['metrics']['outstanding']);
        foreach($d['worklists']as$list)foreach($list['items']as$item)$this->assertContains($item['room_id'],$allowed);
        $this->assertCount(1,$d['charts']);
    }
    public function test_current_debt_and_rooms_do_not_change_with_cash_period_and_empty_has_no_fake_values():void
    {
        $this->fixture();$this->actingAs($this->user('owner'));$a=$this->metrics();$b=$this->metrics(['period'=>'today']);$this->assertSame('0',$b['received']);$this->assertSame($a['outstanding'],$b['outstanding']);$this->assertSame($a['room_states'],$b['room_states']);
        $empty=Workspace::where('owner_id',$this->user('owner')->id)->latest('id')->first();$d=$this->get('/ui/workspace-dashboard?context=workspace&workspace_id='.$empty->id)->assertOk()->viewData('screen')['dashboard'];$this->assertSame('0',$d['metrics']['outstanding']);$this->assertSame(0,$d['metrics']['occupied']);$this->assertCount(4,$d['kpis']);$this->assertCount(3,$d['charts']);
    }
    public function test_period_validates_dates_and_honors_local_midnight():void
    {
        $this->actingAs($this->user('owner'));$this->get($this->url(['period'=>'custom','from'=>'2026-11-20','until'=>'2026-11-10']))->assertUnprocessable();
        $r=Request::create('/?period=today');config(['platform_business.storage_timezone'=>'UTC']);$p=ReportingPeriod::request($r);$this->assertSame('2026-11-09 17:00:00',$p->bounds()[0]);$this->assertSame('2026-11-10',$p->bucket('2026-11-09 17:00:00'));
    }
    public function test_sample_origin_stays_in_metadata_without_authorizing_anything():void
    {
        $i=$this->fixture();$p=Payment::first();DB::table('demo_dataset_records')->insert(['dataset'=>'DASH_FIXTURE','table_name'=>'payments','record_id'=>(string)$p->id,'demo_key'=>'receipt','created_at'=>now()]);$this->actingAs($this->user('owner'));$d=$this->get($this->url())->assertDontSee('mô phỏng')->viewData('screen')['dashboard'];$this->assertSame('100',$d['simulation_received']);
        SimulationOrigin::inherit('payments',$p->id,'subscription_quotes',999);$this->assertTrue(SimulationOrigin::contains('subscription_quotes',999));$this->actingAs($this->user('tenant'))->get($this->url())->assertForbidden();
    }
    public function test_display_cleanup_keeps_all_login_identifiers_passwords_and_personal_names():void
    {
        $before=DB::table('users')->select('id','email','password')->orderBy('id')->get()->toJson();$u=$this->user('expired');$u->update(['name'=>'Nhân viên hết quyền']);$this->seed(PresentationCleanupSeeder::class);$this->assertSame('Phạm Bảo Khánh',$u->fresh()->name);$this->assertSame($before,DB::table('users')->select('id','email','password')->orderBy('id')->get()->toJson());
        $u->update(['name'=>'Tên tự cập nhật']);$this->seed(PresentationCleanupSeeder::class);$this->assertSame('Tên tự cập nhật',$u->fresh()->name);Mail::assertNothingOutgoing();
    }
    public function test_payment_recorded_through_real_service_inherits_sample_invoice_origin():void
    {
        $i=$this->fixture();DB::table('demo_dataset_records')->insert(['dataset'=>'DASH_FIXTURE','table_name'=>'invoices','record_id'=>(string)$i->id,'demo_key'=>'invoice','created_at'=>now()]);
        $payment=app(\App\Modules\Billing\BillingService::class)->pay($this->user('owner'),$i,['amount'=>100,'method'=>'cash','reference'=>'DASH-INHERITED']);$this->assertTrue(SimulationOrigin::contains('payments',$payment->id));
        $this->actingAs($this->user('owner'));$this->assertSame('100',$this->metrics()['simulation_received']);
    }
    public function test_sidebar_groups_expand_by_default_and_hidden_transfer_uses_account_parent():void
    {
        $r=$this->actingAs($this->user('supreme'))->get('/ui/admin-accounts?context=platform-admin')->assertOk()->assertSee('class="shell-nav-group" open',false)->assertDontSee('Thông tin trong phạm vi của bạn');
        $this->assertFalse($r->viewData('shell')['items']->contains(fn($i)=>str_contains($i['url'],'section=ownership')));
        $r=$this->get('/ui/admin-dashboard?context=platform-admin&section=ownership')->assertOk();$this->assertSame('admin-accounts',$r->viewData('shell')['items']->firstWhere('active',true)['slug']);
    }
}
