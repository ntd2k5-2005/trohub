<?php
namespace Tests\Feature;
use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\{DirectGrantService,GroupAssignments};
use App\Modules\Billing\Models\{PaymentRequest,ReceivingAccount};
use App\Modules\DataPlatform\{AuditResourceScope,Journal};
use App\Modules\DataPlatform\Models\AuditEntry;
use App\Modules\Property\PropertyMoveService;
use App\Modules\Rental\Models\Rental;
use App\Modules\Workspace\Models\{Membership,Workspace};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
class PropertyMoveWorkflowTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->freezeTime();\Illuminate\Support\Facades\Http::fake([config('vietqr.banks_url')=>\Illuminate\Support\Facades\Http::response(['code'=>'00','data'=>[['bin'=>'970415','name'=>'Ngân hàng kiểm thử','shortName'=>'TEST','transferSupported'=>1]]])]);$this->seed(DatabaseSeeder::class);config(['business.limits.yearly.properties'=>20,'business.limits.yearly.rooms'=>2000]);}
    private function fixture():array
    {
        $r=Rental::where('status','active')->firstOrFail();$p=$r->room->property;$source=$p->workspace;
        $target=Workspace::create(['owner_id'=>$source->owner_id,'name'=>'Khu nhận quản lý','plan'=>$source->plan,'status'=>'active','expires_at'=>now()->addYear()]);app(GroupAssignments::class)->owner($target);
        $banks=app(\App\Modules\Billing\ReceivingAccountService::class);$original=$banks->save($source->owner,$source,['bank_bin'=>'970415','account_no'=>'000000123456','account_holder_name'=>'TAI KHOAN KIEM THU','label'=>'Người nhận thử','declaration'=>1]);$banks->setDefault($source->owner,$source,$original->id,null);
        $invoice=$r->invoices()->firstOrFail();app(\App\Modules\Billing\BillingService::class)->pay($source->owner,$invoice,['amount'=>100000,'method'=>'cash','reference'=>'MOVE-CASH-ONE']);
        app(\App\Modules\Billing\PaymentRequestService::class)->issue($source->owner,$source,'invoice',$invoice->id);
        $account=$original->replicate();$account->workspace_id=$target->id;$account->property_id=null;$account->verification_status='verified';$account->save();
        DB::table('receiving_account_defaults')->insert(['workspace_id'=>$target->id,'scope_key'=>'workspace','receiving_account_id'=>$account->id]);
        return[$p,$source,$target,$r,$account];
    }
    private function denied(callable$f,int$status=409):void{try{$f();$this->fail('Phải bị chặn.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException$e){$this->assertSame($status,$e->getStatusCode());}}
    public function test_live_tenancy_moves_without_financial_mutation_and_old_scope_is_cut():void
    {
        [$p,$source,$target,$r]=$this->fixture();$oldRoom=$r->room_id;
        $staff=User::factory()->create();$m=Membership::create(['workspace_id'=>$source->id,'user_id'=>$staff->id,'status'=>'active','permissions'=>['property.view','member.view']]);
        $g=app(DirectGrantService::class)->issue($source->owner,$source,['user_id'=>$staff->id,'permission'=>'invoice.view','property_id'=>$p->id,'valid_from'=>now()->toDateTimeString(),'valid_until'=>now()->addDay()->toDateTimeString(),'reason'=>'Phụ trách nhà trước khi chuyển']);
        app(Journal::class)->record($staff->id,'membership.updated',$p,$source->id,['summary'=>'Lịch sử theo nhà']);$audit=AuditEntry::where('actor_id',$staff->id)->where('resource_type',$p::class)->where('resource_id',$p->id)->latest('id')->firstOrFail();
        $financial=[];foreach(['rentals','invoices','payments','deposits','contract_versions']as$t)$financial[$t]=DB::table($t)->get()->toJson();
        $request=PaymentRequest::where('rental_id',$r->id)->firstOrFail();$payee=DB::table('payment_requests')->where('id',$request->id)->value('payee_snapshot');
        $s=app(PropertyMoveService::class);$move=$s->propose($source->owner,$p,$target,'MOVE-TEST-ONE','Tổ chức lại khu quản lý');$this->assertSame('pending',$move->status);
        $this->actingAs($source->owner)->get('/ui/properties?context=workspace&workspace_id='.$source->id.'&tab=houses')->assertOk()->assertSee('Xác nhận chuyển nhà');
        $s->commit($source->owner,$move->id);$s->commit($source->owner,$move->id);
        $this->assertSame($target->id,$p->fresh()->workspace_id);$this->assertSame($oldRoom,$r->fresh()->room_id);
        foreach($financial as$t=>$before)$this->assertSame($before,DB::table($t)->get()->toJson(),$t);
        $this->assertSame($payee,DB::table('payment_requests')->where('id',$request->id)->value('payee_snapshot'));
        $this->assertSame($source->id,$request->fresh()->workspace_at_event);$this->assertSame($target->id,$request->fresh()->workspace_id);
        $this->assertNotNull($g->fresh()->revoked_at);$this->assertFalse(app(Authorization::class)->allowed($staff,'property.view',$target,$p->id));
        $this->assertFalse(app(AuditResourceScope::class)->visible($staff,$audit));
        $this->assertTrue(app(\App\Support\ScopedRepository::class)->rentals($r->tenant)->whereKey($r->id)->exists());
    }
    public function test_unverified_or_different_payee_is_blocked_without_partial_move():void
    {
        [$p,$source,$target,$r,$account]=$this->fixture();$account->update(['verification_status'=>'not_checked']);$s=app(PropertyMoveService::class);
        $move=$s->propose($source->owner,$p,$target,'MOVE-BLOCKED','Kiểm tra điều kiện nhận tiền');$this->assertSame('blocked',$move->status);
        $this->denied(fn()=>$s->commit($source->owner,$move->id));$this->assertSame($source->id,$p->fresh()->workspace_id);
    }
    public function test_drift_and_cross_owner_target_are_denied():void
    {
        [$p,$source,$target]=$this->fixture();$s=app(PropertyMoveService::class);$move=$s->propose($source->owner,$p,$target,'MOVE-DRIFT','Chuyển nhà theo kế hoạch');
        $p->rooms()->firstOrFail()->update(['area'=>99]);$this->denied(fn()=>$s->commit($source->owner,$move->id));
        $foreign=Workspace::where('owner_id','!=',$source->owner_id)->firstOrFail();$this->denied(fn()=>$s->propose($source->owner,$p,$foreign,'MOVE-FOREIGN','Không được chuyển khác chủ'),403);
        $this->assertSame($source->id,$p->fresh()->workspace_id);
    }
    public function test_move_queues_reconciliation_in_new_context_without_transferring_staff_authority():void
    {
        [$p,$source,$target,$r]=$this->fixture();$staff=User::factory()->create();Membership::create(['workspace_id'=>$source->id,'user_id'=>$staff->id,'status'=>'active','permissions'=>['payment.verify','payment.view']]);$request=PaymentRequest::where('rental_id',$r->id)->firstOrFail();$review=app(\App\Modules\Billing\PaymentRequestService::class);$review->beginReview($staff,$source,$request);$a=\App\Modules\Workspace\Models\WorkAssignment::where('current_key','payment_review:'.$request->id)->firstOrFail();$money=DB::table('payments')->get()->toJson();
        $s=app(PropertyMoveService::class);$move=$s->propose($source->owner,$p,$target,'MOVE-WORK','Chuyển cả hồ sơ đối soát đang mở');$s->commit($source->owner,$move->id);$a->refresh();
        $this->assertSame($target->id,$a->workspace_id);$this->assertSame($source->id,$a->workspace_at_event);$this->assertSame('handover_pending',$a->status);$this->assertSame($staff->id,$a->actor_id);
        $this->denied(fn()=>$review->verify($staff,$target,$request->fresh(),'OLD-STAFF'),403);$this->denied(fn()=>$review->verify($target->owner,$target,$request->fresh(),'NO-TAKEOVER'),409);
        app(\App\Modules\Workspace\WorkAssignmentService::class)->takeOver($target->owner,$target,$a->id);$this->assertSame($money,DB::table('payments')->get()->toJson());$this->assertSame('reassigned',$a->fresh()->status);
    }
}
