<?php
namespace Tests\Feature;
use App\Models\User;
use App\Modules\AccessControl\{GrantExplanation,DirectGrantService};
use App\Modules\Billing\{BillingService};
use App\Modules\Billing\Models\{ServiceRate,MeterReading};
use App\Modules\DataPlatform\{AuditIntegrity,Journal};
use App\Modules\DataPlatform\Models\{AuditEntry,DomainEvent};
use App\Modules\Rental\Models\Rental;
use App\Modules\Workspace\{MembershipLifecycle,WorkAssignmentService};
use App\Modules\Workspace\Models\{Membership,WorkAssignment};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Password};
use Tests\TestCase;
class IntegrityHandoverRecoveryTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->freezeTime();$this->seed(DatabaseSeeder::class);}
    private function denied(callable$f,int$status):void{try{$f();$this->fail('Phải bị chặn.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException$e){$this->assertSame($status,$e->getStatusCode());}}
    public function test_integrity_detects_changed_entry_and_missing_tail():void
    {
        $s=app(AuditIntegrity::class);$this->assertTrue($s->verify()['valid']);$user=User::firstOrFail();app(Journal::class)->record($user->id,'profile.updated',$user);
        $entry=AuditEntry::orderByDesc('integrity_sequence')->firstOrFail();$old=$entry->action;DB::table('audit_entries')->where('id',$entry->id)->update(['action'=>'tampered']);$this->assertFalse($s->verify()['valid']);
        DB::table('audit_entries')->where('id',$entry->id)->update(['action'=>$old]);$this->assertTrue($s->verify()['valid']);
        // Deliberate corruption in this disposable fixture proves missing-tail detection.
        DB::table('audit_entries')->where('id',$entry->id)->delete();$this->assertFalse($s->verify()['valid']);
    }
    public function test_audit_failure_rolls_back_business_event_and_head():void
    {
        $u=User::firstOrFail();$old=$u->name;$head=DB::table('audit_integrity_head')->first();$count=DomainEvent::count();
        DomainEvent::creating(fn()=>throw new \RuntimeException('Synthetic boundary failure'));
        try{DB::transaction(function()use($u){$u->update(['name'=>'Không được lưu']);app(Journal::class)->record($u->id,'profile.updated',$u);});$this->fail('Phải rollback.');}catch(\RuntimeException$e){$this->assertSame('Synthetic boundary failure',$e->getMessage());}
        $this->assertSame($old,$u->fresh()->name);$this->assertEquals($head,DB::table('audit_integrity_head')->first());$this->assertSame($count,DomainEvent::count());$this->assertTrue(app(AuditIntegrity::class)->verify()['valid']);
    }
    public function test_invoice_draft_handover_preserves_amounts_items_and_original_actor():void
    {
        $r=Rental::where('status','active')->firstOrFail();$w=$r->room->property->workspace;$owner=$w->owner;$staff=User::factory()->create();
        $m=Membership::create(['workspace_id'=>$w->id,'user_id'=>$staff->id,'status'=>'active','permissions'=>['invoice.create','invoice.issue','invoice.view']]);$s=app(BillingService::class);$period=now()->addMonths(2)->format('Y-m');
        foreach(ServiceRate::where('property_id',$r->room->property_id)->whereIn('kind',['electricity','water'])->get()as$rate){$last=MeterReading::where('room_id',$r->room_id)->where('service_rate_id',$rate->id)->max('current')??0;$s->reading($owner,['room_id'=>$r->room_id,'service_rate_id'=>$rate->id,'period'=>$period,'current'=>$last+10]);}
        $invoice=$s->generate($staff,$r,$period,now()->addMonths(2)->endOfMonth()->toDateString());$total=$invoice->total;$items=$invoice->items()->get()->toJson();$work=WorkAssignment::where('current_key','invoice_draft:'.$invoice->id)->firstOrFail();
        app(MembershipLifecycle::class)->end($owner,$w,$m->id,'Kết thúc làm việc');$this->assertSame('handover_pending',$work->fresh()->status);$this->assertNull($invoice->fresh()->issued_at);
        $this->denied(fn()=>$s->issue($staff,$invoice),403);$this->denied(fn()=>$s->issue($owner,$invoice),409);
        $this->actingAs($owner)->get('/ui/team-permissions?context=workspace&workspace_id='.$w->id.'&section=handover')->assertOk()->assertSee('Xem hóa đơn nháp');
        app(WorkAssignmentService::class)->takeOver($owner,$w,$work->id);$s->issue($owner,$invoice);
        $this->assertSame($total,$invoice->fresh()->total);$this->assertSame($items,$invoice->items()->get()->toJson());$this->assertSame($staff->id,$work->fresh()->actor_id);$this->assertSame('reassigned',$work->fresh()->status);
    }
    public function test_password_recovery_invalidates_old_session_without_changing_owner_or_restrictions():void
    {
        $u=User::where('email','owner@trohub.test')->firstOrFail();$owners=DB::table('workspaces')->pluck('owner_id','id')->all();$this->actingAs($u)->withSession(['auth_version'=>0])->get('/ui/profile-security?context=personal')->assertOk();
        $token=Password::broker()->createToken($u);\Illuminate\Support\Facades\Auth::logout();$this->post(route('password.update'),['email'=>$u->email,'token'=>$token,'password'=>'RecoveredFixture123!','password_confirmation'=>'RecoveredFixture123!'])->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertSame(1,(int)$u->fresh()->auth_version);$this->actingAs($u)->withSession(['auth_version'=>0])->get('/ui/profile-security?context=personal')->assertRedirect(route('login'));$this->assertSame($owners,DB::table('workspaces')->pluck('owner_id','id')->all());
    }
    public function test_reconciliation_handover_never_verifies_money_when_staff_leaves():void
    {
        \Illuminate\Support\Facades\Http::fake([config('vietqr.banks_url')=>\Illuminate\Support\Facades\Http::response(['code'=>'00','data'=>[['bin'=>'970415','name'=>'Ngân hàng kiểm thử','shortName'=>'TEST','transferSupported'=>1]]])]);
        $r=Rental::where('status','active')->firstOrFail();$w=$r->room->property->workspace;$owner=$w->owner;$banks=app(\App\Modules\Billing\ReceivingAccountService::class);$account=$banks->save($owner,$w,['bank_bin'=>'970415','account_no'=>'000000123456','account_holder_name'=>'TAI KHOAN KIEM THU','label'=>'Người nhận thử','declaration'=>1]);$banks->setDefault($owner,$w,$account->id,null);
        $service=app(\App\Modules\Billing\PaymentRequestService::class);$request=$service->issue($owner,$w,'invoice',$r->invoices()->firstOrFail()->id);$payee=$request->payee_snapshot;$staff=User::factory()->create();$m=Membership::create(['workspace_id'=>$w->id,'user_id'=>$staff->id,'status'=>'active','permissions'=>['payment.verify','payment.view']]);
        $this->actingAs($staff)->post('/actions/payment.review.claim',['context'=>'workspace','workspace_id'=>$w->id,'id'=>$request->id])->assertRedirect()->assertSessionHasNoErrors();$a=WorkAssignment::where('current_key','payment_review:'.$request->id)->firstOrFail();$money=DB::table('payments')->get()->toJson();
        app(MembershipLifecycle::class)->end($owner,$w,$m->id,'Bàn giao phần đối soát chưa hoàn tất');$this->assertSame($money,DB::table('payments')->get()->toJson());$this->assertNull($request->fresh()->verified_at);$this->assertSame('handover_pending',$a->fresh()->status);
        $this->denied(fn()=>$service->verify($staff,$w,$request,'NO-STAFF'),403);$this->denied(fn()=>$service->verify($owner,$w,$request,'NO-AUTO'),409);
        $this->actingAs($owner)->get('/ui/team-permissions?context=workspace&workspace_id='.$w->id.'&section=handover')->assertOk()->assertSee('Xem khoản cần đối soát');
        app(WorkAssignmentService::class)->takeOver($owner,$w,$a->id);$amount=$service->remaining($request);$service->verify($owner,$w,$request,'VERIFIED-AFTER-HANDOVER',$amount);
        $this->assertSame($payee,$request->fresh()->payee_snapshot);$this->assertSame('verified',$request->fresh()->status);$this->assertSame($staff->id,$a->fresh()->actor_id);
    }
    public function test_rental_negotiation_stays_open_until_authorized_handover_and_real_decision():void
    {
        $listing=app(\App\Modules\Listing\ListingService::class)->available()->whereHas('room',fn($q)=>$q->where('status','vacant'))->firstOrFail();$w=$listing->room->property->workspace;$owner=$w->owner;$tenant=User::where('email','user@trohub.test')->firstOrFail();$staff=User::factory()->create();$m=Membership::create(['workspace_id'=>$w->id,'user_id'=>$staff->id,'status'=>'active','permissions'=>['rental.create','rental.view']]);$s=app(\App\Modules\Rental\RentalRequestService::class);
        $request=$s->submit($tenant,$listing->id,['starts_at'=>now()->addDay()->toDateString(),'ends_at'=>now()->addYear()->toDateString(),'people'=>1,'accept_terms'=>1,'quote'=>app(\App\Modules\Rental\RentalPolicy::class)->quote($listing->room)]);
        $request->refresh();
        $s->decide($staff,$w,$request,'counter',$request->version,['rent'=>$listing->room->rent,'deposit'=>0,'deposit_terms'=>'Không cọc theo thương lượng thử nghiệm','reason'=>'Điều kiện thuê cần khách xác nhận']);$request->refresh();$a=WorkAssignment::where('current_key','rental_negotiation:'.$request->id)->firstOrFail();
        app(MembershipLifecycle::class)->end($owner,$w,$m->id,'Bàn giao hồ sơ đang thương lượng');$this->assertSame('counter_offered',$request->fresh()->status);$this->assertNull($request->rental_id);
        $this->denied(fn()=>$s->decide($owner,$w,$request,'reject',$request->version,['reason'=>'Chưa tiếp nhận']),409);
        app(WorkAssignmentService::class)->takeOver($owner,$w,$a->id);$s->decide($owner,$w,$request,'reject',$request->version,['reason'=>'Đã trao đổi và không tiếp tục cho thuê']);
        $this->assertSame('rejected',$request->fresh()->status);$this->assertSame($staff->id,$a->fresh()->actor_id);$this->assertSame(0,\App\Modules\Rental\Models\RoomReservation::where('rental_request_id',$request->id)->count());
    }
    public function test_legacy_classification_is_read_only_and_does_not_invent_delegating_source():void
    {
        $r=Rental::where('status','active')->firstOrFail();$w=$r->room->property->workspace;$u=User::factory()->create();Membership::create(['workspace_id'=>$w->id,'user_id'=>$u->id,'status'=>'active','permissions'=>[]]);
        $g=app(DirectGrantService::class)->issue($w->owner,$w,['user_id'=>$u->id,'permission'=>'invoice.view','property_id'=>$r->room->property_id,'valid_from'=>now()->toDateTimeString(),'valid_until'=>now()->addDay()->toDateTimeString(),'reason'=>'Nguồn tổ chức trong kiểm thử']);$service=app(GrantExplanation::class);$this->assertSame('ORGANIZATIONAL',$service->classification($g));
        DB::table('direct_grants')->where('id',$g->id)->update(['authority_kind'=>'legacy','source_kind'=>null,'source_id'=>null]);$g->refresh();$before=DB::table('direct_grants')->get()->toJson();$this->assertSame('AMBIGUOUS',$service->classification($g));$service->report();$this->assertSame($before,DB::table('direct_grants')->get()->toJson());$this->assertSame('Chưa đủ căn cứ để cấp tiếp',$service->details($g)['Cấp tiếp từ quyền này']);
    }
    public function test_permission_explanation_does_not_borrow_an_independent_source_for_disabled_group():void
    {
        $r=Rental::where('status','active')->firstOrFail();$w=$r->room->property->workspace;$u=User::factory()->create();Membership::create(['workspace_id'=>$w->id,'user_id'=>$u->id,'status'=>'active','permissions'=>[]]);
        $group=\App\Modules\AccessControl\Models\AccessGroup::create(['workspace_id'=>$w->id,'scope'=>'workspace','name'=>'Nhóm đã tạm ngừng','permissions'=>['invoice.view'],'active'=>false,'status'=>'disabled']);
        \App\Modules\AccessControl\Models\GroupAssignment::create(['workspace_id'=>$w->id,'scope_key'=>'workspace:'.$w->id,'user_id'=>$u->id,'membership_id'=>Membership::where('workspace_id',$w->id)->where('user_id',$u->id)->value('id'),'access_group_id'=>$group->id,'origin'=>'manual','valid_from'=>now()->subHour()]);
        app(DirectGrantService::class)->issue($w->owner,$w,['user_id'=>$u->id,'permission'=>'invoice.view','property_id'=>$r->room->property_id,'valid_from'=>now()->toDateTimeString(),'valid_until'=>now()->addDay()->toDateTimeString(),'reason'=>'Nguồn độc lập với nhóm tạm ngừng']);
        $access=app(\App\Modules\AccessControl\AccessService::class);$this->assertTrue($access->allowed($u,'invoice.view',$w,$r->room->property_id));$items=collect($access->explanation($u,$w));$item=$items->firstWhere('name',$group->name);$this->assertEmpty($item['effective']);$this->assertNotEmpty($item['blocked']);
        $response=$this->actingAs($u)->get('/ui/profile-security?context=personal&section=access')->assertOk()->assertSee('data-record-list',false);
        $row=collect($response->viewData('screen')['record_table']['records'])->firstWhere('_key','workspaces:'.$w->id);
        $this->get($row['_detail_url'])->assertOk()->assertSee('Phân công và quyền truy cập')->assertSee('Nhóm đã tạm ngừng');
    }
}
