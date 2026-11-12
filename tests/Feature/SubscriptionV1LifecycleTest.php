<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Subscription\AccountSubscriptionService;
use App\Modules\Subscription\LegacySubscriptionReview;
use App\Modules\Subscription\Models\SubscriptionCycle;
use App\Modules\Subscription\PlanCatalog;
use App\Modules\Workspace\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubscriptionV1LifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->freezeTime();$this->seed(DatabaseSeeder::class);
        config(['subscriptions.account_authority_enabled'=>true,'subscriptions.platform_payee'=>['bank_bin'=>'970422','bank_name'=>'Ngân hàng kiểm thử','account_no'=>'000000999','account_holder_name'=>'PLATFORM TEST']]);
        $admin=User::where('email','supreme@trohub.test')->firstOrFail();$owner=User::factory()->create();$catalog=app(PlanCatalog::class);
        foreach(['trial','monthly','yearly']as$key){
            $data=['plan'=>$key,'label'=>'Gói kiểm thử '.$key,'price'=>$key==='trial'?0:($key==='monthly'?75000:800000),'duration_count'=>$key==='trial'?14:1,'duration_unit'=>$key==='trial'?'days':($key==='monthly'?'months':'years'),'features'=>['workspace','property','room','listing','billing','maintenance','iot'],'reason'=>'Phiên bản chỉ dùng cho kiểm thử V1'];
            foreach(['workspaces'=>3,'properties'=>4,'rooms'=>$key==='yearly'?2:10,'staff'=>3]as$kind=>$limit){$data[$kind.'_mode']='limited';$data['max_'.$kind]=$limit;}
            $plan=$catalog->save($admin,$data);$catalog->publish($admin,$plan->id);
        }
        return [$admin,$owner,app(AccountSubscriptionService::class)];
    }

    private function pay($service,$admin,$owner,string$plan,string$ref)
    {
        $quote=$service->quote($owner,$plan,$ref);$service->report($owner,$quote,'PAYER-'.$ref);$service->confirm($admin,$quote->id,$quote->amount,'BANK-'.$ref,'Đối soát trong dữ liệu kiểm thử cách ly');return $quote;
    }

    public function test_paid_change_keeps_current_benefits_and_runs_once_at_next_boundary(): void
    {
        [$admin,$owner,$service]=$this->fixture();$account=$service->trial($owner);$this->pay($service,$admin,$owner,'monthly','CURRENT');$account->refresh();$end=$account->expires_at->copy();$current=$account->plan_version_id;
        $workspace = $service->createWorkspace($owner, 'Khu giữ dữ liệu khi hạ hạn mức');
        app(\App\Modules\Workspace\WorkspaceLifecycle::class)->transition($owner, $workspace, 'active', $workspace->version, 'Kích hoạt trong fixture');
        $workspace->refresh(); $epoch = $workspace->cloud_epoch;
        $property = \App\Modules\Property\Models\Property::create(['workspace_id'=>$workspace->id,'name'=>'Nhà trong fixture','address'=>'Địa chỉ kiểm thử','district'=>'Thủ Đức']);
        for ($i=1;$i<=3;$i++) \App\Modules\Property\Models\Room::create(['property_id'=>$property->id,'name'=>'Phòng '.$i,'area'=>20,'rent'=>2000000]);
        $roomsBefore = DB::table('rooms')->where('property_id',$property->id)->orderBy('id')->get()->toJson();

        $quote=$this->pay($service,$admin,$owner,'yearly','NEXT');$account->refresh();
        $this->assertSame($current,$account->plan_version_id);$this->assertTrue($account->expires_at->equalTo($end));
        $pending=$service->scheduled($account);$this->assertNotNull($pending);$this->assertTrue($pending->starts_at->equalTo($end));$this->assertNull($pending->applied_at);
        $this->assertSame(0,$service->activateDue());
        $this->travelTo($end);$this->assertSame(1,$service->activateDue());$this->assertSame(0,$service->activateDue());$account->refresh();
        $this->assertSame($quote->plan_version_id,$account->plan_version_id);$this->assertSame(2,$account->planVersion->snapshot['limits']['rooms']);$this->assertNotNull($pending->fresh()->applied_at);
        $this->assertSame(1,SubscriptionCycle::where('subscription_quote_id',$quote->id)->count());
        $this->assertSame($roomsBefore, DB::table('rooms')->where('property_id',$property->id)->orderBy('id')->get()->toJson());
        $this->assertSame($epoch, $workspace->fresh()->cloud_epoch);
        $this->assertTrue(app(\App\Modules\Subscription\PropertyCloud::class)->active($workspace->fresh()));
        try { app(\App\Modules\Property\PropertyService::class)->saveRoom($owner, $property, ['name'=>'Vượt hạn mức mới','area'=>20,'rent'=>2000000]); $this->fail('Cannot increase over-quota usage'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }

    }

    public function test_unreported_quote_can_be_cancelled_but_reported_money_requires_reconciliation(): void
    {
        [$admin,$owner,$service]=$this->fixture();$service->trial($owner);$quote=$service->quote($owner,'monthly','CANCEL');$service->cancelQuote($owner,$quote,'Đổi lựa chọn trước khi chuyển tiền');$service->cancelQuote($owner,$quote,'Gửi lại');
        $this->assertSame('cancelled',$quote->fresh()->status);$this->assertFalse($service->payable($quote->fresh()));$this->assertSame(0,SubscriptionCycle::count());
        $reported=$service->quote($owner,'monthly','REPORTED');$service->report($owner,$reported,'USER-REPORTED');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);$service->cancelQuote($owner,$reported,'Không được bỏ qua đối soát');
    }

    public function test_legacy_period_is_preserved_then_can_start_new_paid_subscription_after_expiry(): void
    {
        [$admin,$unused,$service]=$this->fixture();$workspace=Workspace::whereHas('owner',fn($q)=>$q->where('email','owner@trohub.test'))->firstOrFail();$owner=$workspace->owner;$before=DB::table('subscription_orders')->orderBy('id')->get()->toJson();$expiry=$workspace->expires_at->copy();$review=app(LegacySubscriptionReview::class);
        $report=$review->report($owner->id);$review->record($admin,$owner->id,'retained',$report['source_hash'],'Giữ kỳ đang có; chưa đủ chứng từ số tiền cũ');$this->assertTrue($workspace->fresh()->expires_at->equalTo($expiry));
        try{$service->openPaid($owner,'monthly','TOO-EARLY');$this->fail('Active legacy period must not be replaced');}catch(\Symfony\Component\HttpKernel\Exception\HttpException$e){$this->assertSame(409,$e->getStatusCode());}
        $this->travelTo($expiry->copy()->addSecond());$quote=$service->openPaid($owner,'monthly','AFTER-LEGACY');$this->assertSame('pending',$quote->accountSubscription->status);$service->report($owner,$quote,'NEW-PAYER');$this->assertSame('pending',$quote->accountSubscription->fresh()->status);
        $service->confirm($admin,$quote->id,$quote->amount,'NEW-RECEIPT','Khoản mới đã đối soát trong fixture');$this->assertSame('active',$quote->accountSubscription->fresh()->status);$this->assertSame($before,DB::table('subscription_orders')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('legacy_subscription_reviews',1);
        $originals = $review->originalPeriods($owner->id); $this->assertNotEmpty($originals);
        $this->assertTrue(\Illuminate\Support\Carbon::parse($originals[0]['expires_at'])->equalTo($expiry));
        $this->actingAs($owner)->get('/ui/landlord-upgrade?context=personal')->assertOk()->assertSee('Lịch sử kỳ gói trước')->assertSee($expiry->format('d/m/Y H:i'));
    }

    public function test_late_scheduler_does_not_extend_a_paid_cycle_or_grant_free_time(): void
    {
        [$admin,$owner,$service]=$this->fixture();$account=$service->trial($owner);$this->pay($service,$admin,$owner,'monthly','FIRST');$quote=$this->pay($service,$admin,$owner,'yearly','LATE-NEXT');$cycle=SubscriptionCycle::where('subscription_quote_id',$quote->id)->firstOrFail();
        $this->travelTo($cycle->ends_at->copy()->addDay());$this->assertSame(1,$service->activateDue());$account->refresh();$this->assertSame('expired',$account->status);$this->assertTrue($account->expires_at->equalTo($cycle->ends_at));
    }
    public function test_owner_sees_all_published_choices_history_cancel_action_and_legacy_review_is_scoped(): void
    {
        [$admin, $owner, $service] = $this->fixture();
        $service->trial($owner); $this->pay($service, $admin, $owner, 'monthly', 'UI-CURRENT');
        $this->actingAs($owner)->get('/ui/landlord-upgrade?context=personal')->assertOk()->assertSee('Gói kiểm thử yearly')->assertSee('Chọn gói hoặc gia hạn');
        $quote = $service->quote($owner, 'yearly', 'UI-CANCEL');
        $this->actingAs($owner)->get('/ui/landlord-upgrade?context=personal&payment_id='.$quote->id)->assertOk()->assertSee('Hủy yêu cầu chưa thanh toán');
        $this->actingAs($admin)->post('/actions/account.quote.cancel', ['context'=>'personal', 'id'=>$quote->id, 'reason'=>'Không phải người sở hữu', 'confirm'=>1])->assertForbidden();
        $this->actingAs($owner)->post('/actions/account.quote.cancel', ['context'=>'personal', 'id'=>$quote->id, 'reason'=>'Đổi lựa chọn trước khi chuyển', 'confirm'=>1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $quote->fresh()->status);
        $this->actingAs($owner)->get('/ui/landlord-upgrade?context=personal&payment_id='.$quote->id)->assertOk()->assertSee('Đã hủy yêu cầu')->assertSee('Đổi lựa chọn trước khi chuyển');
        $this->pay($service, $admin, $owner, 'yearly', 'UI-NEXT');
        $this->actingAs($owner)->get('/ui/landlord-upgrade?context=personal')->assertOk()->assertSee('Gói kỳ tiếp theo')->assertSee('Chờ đến kỳ áp dụng')->assertDontSee('Chọn gói hoặc gia hạn');
        $legacy = Workspace::whereHas('owner', fn($q)=>$q->where('email','owner@trohub.test'))->firstOrFail();
        $url = '/ui/admin-dashboard?context=platform-admin&section=legacy&owner_id='.$legacy->owner_id;
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Ghi nhận đối chiếu')->assertSee('Kỳ gói đã lưu');
        $this->actingAs($owner)->get($url)->assertForbidden();
        $report = app(LegacySubscriptionReview::class)->report($legacy->owner_id);
        $this->actingAs($admin)->post('/actions/legacy.subscription.review', ['context'=>'platform-admin','owner_id'=>$legacy->owner_id,'decision'=>'retained','source_hash'=>$report['source_hash'],'note'=>'Kỳ đang có giữ nguyên, không đổi hợp đồng','confirm'=>1])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Lịch sử đối chiếu')->assertSee('Kỳ đang có giữ nguyên, không đổi hợp đồng');
    }

    public function test_expiry_scheduler_is_repeatable_and_preserves_contracts_and_financial_history(): void
    {
        [$admin, $owner, $service] = $this->fixture(); $account = $service->trial($owner);
        $workspace = $service->createWorkspace($owner, 'Khu kiểm thử hết hạn');
        $history = []; foreach (['rentals', 'contract_versions', 'invoices', 'payments'] as $table) $history[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        $this->travelTo($account->expires_at->copy()->addSecond());
        $this->artisan('subscriptions:expire')->assertSuccessful(); $this->artisan('subscriptions:expire')->assertSuccessful();
        $this->assertSame('expired', $account->fresh()->status);
        $this->assertFalse(app(\App\Modules\Subscription\PropertyCloud::class)->active($workspace));
        foreach ($history as $table=>$before) $this->assertSame($before, DB::table($table)->orderBy('id')->get()->toJson());
        $quote = $service->quote($owner, 'monthly', 'AFTER-EXPIRED'); $service->report($owner, $quote, 'EXPIRED-PAYER');
        $this->assertSame('expired', $account->fresh()->status);
        $service->confirm($admin, $quote->id, $quote->amount, 'EXPIRED-BANK', 'Xác nhận khoản trong dữ liệu thử cách ly');
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_archiving_a_workspace_does_not_erase_its_existing_paid_period(): void
    {
        [$admin, $unused, $service] = $this->fixture();
        $workspace = Workspace::whereHas('owner', fn($q)=>$q->where('email','owner@trohub.test'))->firstOrFail();
        $workspace->update(['status'=>'archived','lifecycle'=>'archived']); $expiry = $workspace->expires_at->copy();
        try { $service->openPaid($workspace->owner, 'monthly', 'ARCHIVED-PERIOD'); $this->fail('An archived workspace still has an established period'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
        $this->assertTrue($workspace->fresh()->expires_at->equalTo($expiry));
    }

}
