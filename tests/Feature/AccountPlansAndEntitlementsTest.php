<?php
namespace Tests\Feature;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\Property\PropertyService;
use App\Modules\Subscription\{AccountSubscriptionService,LegacySubscriptionReview,PlanCatalog,PropertyCloud};
use App\Modules\Subscription\Models\{AccountSubscription,PlanVersion,SubscriptionCycle};
use App\Modules\Workspace\{OwnerQuota,WorkspaceLifecycle};
use App\Modules\Workspace\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountPlansAndEntitlementsTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->freezeTime();$this->seed(DatabaseSeeder::class);config(['subscriptions.account_authority_enabled'=>true,'subscriptions.platform_payee'=>['bank_bin'=>'970422','bank_name'=>'Ngân hàng kiểm thử','account_no'=>'000000999','account_holder_name'=>'PLATFORM TEST']]);}
    private function root():User{return User::where('email','supreme@trohub.test')->firstOrFail();}
    private function owner():User{$u=User::factory()->create();$u->forceFill(['access_group_id'=>AccessGroup::where('key','ordinary')->value('id')])->save();return $u;}
    private function draft(string $key='trial',array $extra=[]):PlanVersion
    {
        $data=['plan'=>$key,'label'=>'Gói thử nghiệm '.$key,'price'=>$key==='trial'?0:100000,'duration_count'=>$key==='trial'?14:1,'duration_unit'=>$key==='trial'?'days':'months','features'=>array_keys(config('subscriptions.feature_labels')),'reason'=>'Phiên bản chỉ dùng trong fixture kiểm thử'];
        foreach(['workspaces'=>3,'properties'=>3,'rooms'=>10,'staff'=>2] as $kind=>$value){$data[$kind.'_mode']='limited';$data['max_'.$kind]=$value;}
        return app(PlanCatalog::class)->save($this->root(),array_replace($data,$extra));
    }
    private function publish(string $key='trial',array $extra=[]):PlanVersion{$p=$this->draft($key,$extra);app(PlanCatalog::class)->publish($this->root(),$p->id);return $p->fresh();}
    private function denied(callable $f,int $status=409):void{try{$f();$this->fail('Phải bị chặn.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame($status,$e->getStatusCode());}}
    private function enrolled():array
    {
        $this->publish();$u=$this->owner();$s=app(AccountSubscriptionService::class);$a=$s->trial($u);$w=$s->createWorkspace($u,'Khu dùng chung đầu tiên');app(WorkspaceLifecycle::class)->transition($u,$w,'active',$w->version,'Mở khu quản lý mới');return [$u,$a,$w->fresh()];
    }
    public function test_unspecified_unlimited_and_published_immutability_through_admin_ui():void
    {
        $c=app(PlanCatalog::class);$p=$this->draft('trial',['properties_mode'=>'unspecified']);$this->denied(fn()=>$c->publish($this->root(),$p->id));
        $p=$this->draft('trial',['properties_mode'=>'unlimited']);
        $this->actingAs($this->root())->post('/actions/plan.publish',['context'=>'platform-admin','id'=>$p->id,'confirm'=>1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('unlimited',$p->fresh()->snapshot['limits']['properties']);
        $this->actingAs($this->root())->get('/ui/admin-dashboard?context=platform-admin&section=plans')->assertOk()->assertSee('Không giới hạn')->assertSee('Chưa cấu hình');
        $this->denied(fn()=>$p->update(['snapshot'=>['limits'=>[]]]));
        $this->actingAs(User::where('email','user@trohub.test')->firstOrFail())->post('/actions/plan.publish',['context'=>'personal','id'=>$p->id,'confirm'=>1])->assertForbidden();
    }
    public function test_account_trial_is_one_time_shared_quota_and_plan_versions_are_not_retroactive():void
    {
        [$u,$a,$w]=$this->enrolled();$service=app(AccountSubscriptionService::class);
        $this->denied(fn()=>$service->trial($u));
        $second=$service->createWorkspace($u,'Khu dùng chung thứ hai');$this->assertSame(2,app(OwnerQuota::class)->usage($u->id,'workspaces'));$this->assertSame('draft',$second->lifecycle);
        app(WorkspaceLifecycle::class)->transition($u,$second,'active',$second->version,'Mở khu thứ hai');
        $this->assertTrue(app(PropertyCloud::class)->active($w));$this->assertTrue(app(PropertyCloud::class)->active($second));
        $new=$this->publish('trial',['max_workspaces'=>1]);$this->assertNotSame($new->id,$a->plan_version_id);$this->assertSame(3,app(OwnerQuota::class)->limits($u->id)['workspaces']);
        $this->travelTo($a->expires_at);$this->assertFalse(app(PropertyCloud::class)->active($w));$this->assertFalse(app(PropertyCloud::class)->active($second));
    }
    public function test_catalog_closure_honors_existing_quote_and_allows_request_for_next_period_change():void
    {
        [$u,$a]=$this->enrolled();$s=app(AccountSubscriptionService::class);$plan=$this->publish('monthly');$q=$s->quote($u,'monthly','CATALOG-QUOTE');
        app(PlanCatalog::class)->availability($this->root(),$plan->id,'public','closed','Ngừng tiếp nhận mới trong kiểm thử');
        $this->denied(fn()=>$s->quote($u,'monthly','AFTER-CLOSE'));$s->report($u,$q,'PAYER-CATALOG');$s->confirm($this->root(),$q->id,100000,'BANK-CATALOG','Đã xác minh khoản thuộc báo giá còn hạn');
        $this->assertSame($plan->id,$a->fresh()->plan_version_id);
        app(PlanCatalog::class)->availability($this->root(),$plan->id,'closed','renewal_only','Chỉ tiếp nhận gia hạn cùng loại gói');$s->quote($u,'monthly','RENEWAL-ONLY');
        $this->publish('yearly');$next=$s->quote($u,'yearly','NEXT-PERIOD-CHANGE');$this->assertSame('pending',$next->status);$this->assertSame($plan->id,$a->fresh()->plan_version_id);
        $this->actingAs($this->root())->post('/actions/plan.availability',['context'=>'platform-admin','id'=>$plan->id,'expected'=>'renewal_only','availability'=>'archived','reason'=>'Lưu trữ phiên bản đã ngừng tiếp nhận'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('archived',$plan->fresh()->availability);
    }
    public function test_verified_quote_keeps_trial_remainder_idempotent_receipt_and_reversal_never_refunds():void
    {
        [$u,$a,$w]=$this->enrolled();$s=app(AccountSubscriptionService::class);$this->publish('monthly');$money=DB::table('payments')->get()->toJson();
        $q=$s->quote($u,'monthly','ACCOUNT-QUOTE-ONE');$this->assertSame($q->id,$s->quote($u,'monthly','ACCOUNT-QUOTE-ONE')->id);
        $s->report($u,$q,'PAYER-ONE');$this->denied(fn()=>$s->confirm($this->root(),$q->id,99999,'BANK-ONE','Đã kiểm tra giao dịch tổng hợp'));
        $s->confirm($this->root(),$q->id,100000,'BANK-ONE','Đã kiểm tra thực nhận theo dữ liệu synthetic');$s->confirm($this->root(),$q->id,100000,'BANK-ONE','Lặp cùng giao dịch');
        $this->assertSame(1,SubscriptionCycle::where('account_subscription_id',$a->id)->count());
        $this->assertTrue($a->fresh()->expires_at->equalTo($a->expires_at->copy()->addMonthNoOverflow()));
        $cycle=SubscriptionCycle::where('account_subscription_id',$a->id)->firstOrFail();$s->reverse($this->root(),$cycle->id,'Đã đối chiếu giao dịch bị đảo trong fixture synthetic');
        $this->assertFalse(app(PropertyCloud::class)->active($w));$this->assertSame($money,DB::table('payments')->get()->toJson());
        $this->assertNotNull($cycle->fresh()->reversed_at);$this->denied(fn()=>$s->quote($u,'monthly','BLOCKED-AFTER-REVERSAL'));
    }
    public function test_account_restrictions_survive_renewal_and_other_cause_release():void
    {
        [$u,$a,$w]=$this->enrolled();$s=app(AccountSubscriptionService::class);$this->publish('monthly');
        $one=$s->restrict($this->root(),$u->id,'review1','Xử lý hồ sơ một');$two=$s->restrict($this->root(),$u->id,'review2','Xử lý hồ sơ hai');
        $q=$s->quote($u,'monthly','RESTRICTED-QUOTE');$s->report($u,$q,'PAYER-RESTRICTED');$s->confirm($this->root(),$q->id,100000,'BANK-RESTRICTED','Đối soát hợp lệ trong fixture');
        $this->assertFalse(app(PropertyCloud::class)->active($w));$s->release($this->root(),$one,'Đã hoàn tất hồ sơ một');$this->assertFalse(app(PropertyCloud::class)->active($w));
        $s->release($this->root(),$two,'Đã hoàn tất hồ sơ hai');$this->assertTrue(app(PropertyCloud::class)->active($w));
    }
    public function test_legacy_quota_adoption_is_explicit_does_not_change_dates_money_or_staff():void
    {
        $w=Workspace::whereHas('owner',fn($q)=>$q->where('email','owner@trohub.test'))->firstOrFail();$before=$w->only(['expires_at','plan','status']);$members=DB::table('memberships')->get()->toJson();
        $p=$this->publish('yearly',['max_rooms'=>0,'max_staff'=>0]);$catalog=app(PlanCatalog::class);
        $this->assertNotSame(0,app(OwnerQuota::class)->limits($w->owner_id)['rooms']);
        $catalog->assignLegacyQuota($this->root(),$w->owner_id,$p->id,'Áp dụng hạn mức cho thao tác tiếp theo, không đổi kỳ tiền');
        $this->assertSame(0,app(OwnerQuota::class)->limits($w->owner_id)['rooms']);$this->assertEquals($before,$w->fresh()->only(['expires_at','plan','status']));$this->assertSame($members,DB::table('memberships')->get()->toJson());
        $this->assertTrue(app(Authorization::class)->allowed($w->owner,'invoice.view',$w));
        $this->denied(fn()=>app(PropertyService::class)->saveRoom($w->owner,$w->properties()->firstOrFail(),['name'=>'Không thêm khi vượt hạn mức','area'=>20,'rent'=>1000000]));
    }
    public function test_legacy_review_is_read_only_and_does_not_guess_periods_or_verified_amount():void
    {
        $owner=Workspace::whereHas('owner',fn($q)=>$q->where('email','owner@trohub.test'))->firstOrFail()->owner_id;
        $before=Workspace::where('owner_id',$owner)->get()->toJson();$report=app(LegacySubscriptionReview::class)->report($owner);
        $this->assertContains($report['classification'],['MISSING_EVIDENCE','MULTIPLE_CONFLICTING']);$this->assertFalse($report['cutover_performed']);$this->assertSame($before,Workspace::where('owner_id',$owner)->get()->toJson());
        $this->assertFalse(AccountSubscription::where('owner_id',$owner)->exists());
    }
}
