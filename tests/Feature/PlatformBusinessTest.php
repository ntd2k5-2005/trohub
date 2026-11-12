<?php

namespace Tests\Feature;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\AccountGrantService;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\Billing\{PlatformBankService,PlatformBankPresenter};
use App\Modules\Billing\Models\ReceivingAccount;
use App\Modules\Subscription\{AccountSubscriptionService,BusinessPeriod,PlanCatalog,PlatformPayee,RevenueRepository,Vnd};
use App\Modules\Subscription\Models\{PlanVersion,SubscriptionCycle,SubscriptionQuote};
use App\Modules\Workspace\WorkspaceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;

class PlatformBusinessTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();$this->freezeTime();$this->seed(DatabaseSeeder::class);
        config(['subscriptions.account_authority_enabled'=>true,'subscriptions.platform_payee'=>['bank_bin'=>'970422','bank_name'=>'MB kiểm thử','account_no'=>'000000999','account_holder_name'=>'PLATFORM TEST']]);
        Http::preventStrayRequests();Http::fake(['api.vietqr.io/*'=>Http::response(['code'=>'00','data'=>[['bin'=>'970422','name'=>'Ngân hàng MB kiểm thử','shortName'=>'MB','transferSupported'=>1],['bin'=>'970436','name'=>'Ngân hàng kiểm thử khác','shortName'=>'TEST','transferSupported'=>1]]]),'img.vietqr.io/*'=>Http::response('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>',200,['Content-Type'=>'image/svg+xml'])]);
    }
    private function user(string $key):User{return User::where('email',$key.'@trohub.test')->firstOrFail();}
    private function operator(array $codes):User
    {
        $user=$this->user('platform');DB::table('access_groups')->where('id',$user->access_group_id)->update(['permissions'=>json_encode($codes)]);return $user->fresh();
    }
    private function page(string $section,array $data=[]):string{return '/ui/admin-dashboard?'.http_build_query(['context'=>'platform-admin','section'=>$section]+$data);}
    private function planData(string $key='monthly',int $price=75000):array
    {
        $data=['plan'=>$key,'label'=>'Gói fixture '.$key,'price'=>$key==='trial'?0:$price,'duration_count'=>$key==='trial'?14:1,'duration_unit'=>$key==='trial'?'days':($key==='yearly'?'years':'months'),'features'=>['workspace'],'reason'=>'Cấu hình chỉ trong môi trường kiểm thử'];
        foreach(['workspaces','properties','rooms','staff']as$kind)$data[$kind.'_mode']='unlimited';return $data;
    }
    private function fixture():array
    {
        $root=$this->user('supreme');$catalog=app(PlanCatalog::class);
        foreach(['trial','monthly','yearly']as$key){$plan=$catalog->save($root,$this->planData($key,$key==='yearly'?900000:75000));$catalog->publish($root,$plan->id);}
        $owner=$this->user('user');$service=app(AccountSubscriptionService::class);$account=$service->trial($owner);$quote=$service->quote($owner,'monthly','BUSINESS-TEST-ONE');return [$root,$owner,$account,$quote];
    }
    private function receive(User $root,User $owner,SubscriptionQuote $quote,string $reference='BUSINESS-BANK-ONE'):void
    {
        $service=app(AccountSubscriptionService::class);$service->report($owner,$quote,'PAYER-TEST','Chứng từ fixture');$service->confirm($root,$quote->id,$quote->amount,$reference,'Đã kiểm tra tiền thực nhận trong fixture');
    }
    private function totals(?string $query=null):array{return app(RevenueRepository::class)->totals($this->user('supreme'),BusinessPeriod::request(Request::create('/?period=year'.($query??''))));}
    private function bankData(string $number='000001234',string $bin='970422'):array{return ['label'=>'Nhận tiền nền tảng fixture','bank_bin'=>$bin,'account_no'=>$number,'account_holder_name'=>'BANK TEST','declaration'=>1];}

    public function test_platform_bank_import_is_explicit_and_preserves_old_quote_payload():void
    {
        [$root,$owner,$account,$quote]=$this->fixture();$before=DB::table('subscription_quotes')->find($quote->id);$banks=app(PlatformBankService::class);
        $this->actingAs($root)->get($this->page('banking'))->assertOk();$this->assertSame(0,$banks->accounts()->count());
        $bank=$banks->adoptConfigured($root);$this->assertSame($bank->id,$banks->selected()->id);$this->assertSame('000000999',$bank->account_no);
        $after=DB::table('subscription_quotes')->find($quote->id);$this->assertSame($before->payee_snapshot,$after->payee_snapshot);$this->assertSame($before->updated_at,$after->updated_at);$this->assertSame($bank->id,$after->receiving_account_id);
        $this->assertSame($bank->id,$banks->adoptConfigured($root)->id);$this->assertSame(1,$banks->accounts()->count());
        $this->actingAs($root)->post('/actions/platform.bank.delete',['context'=>'platform-admin','id'=>$bank->id,'version'=>$bank->version,'confirm'=>1])->assertConflict();
    }
    public function test_bank_default_edit_disable_delete_do_not_rewrite_quotes_or_landlord_accounts():void
    {
        [$root,$owner,$account,$old]=$this->fixture();$banks=app(PlatformBankService::class);$first=$banks->adoptConfigured($root);$oldSnapshot=$old->fresh()->payee_snapshot;
        $second=$banks->save($root,$this->bankData('123456789','970436'));$banks->change($root,$second->id,$second->version,'default');
        $new=app(AccountSubscriptionService::class)->quote($owner,'monthly','BUSINESS-TEST-NEW');$this->assertSame($second->id,$new->receiving_account_id);$this->assertSame('123456789',$new->payee_snapshot['account_no']);
        $edited=$banks->save($root,$this->bankData('987654321','970436')+['version'=>$second->fresh()->version],$second->id);
        $this->assertSame($oldSnapshot,$old->fresh()->payee_snapshot);$this->assertSame('123456789',$new->fresh()->payee_snapshot['account_no']);
        $this->actingAs($owner)->get('/thanh-toan-goi/'.$new->id.'/ma-qr?context=personal')->assertOk();Http::assertSent(fn($r)=>str_contains($r->url(),'123456789')&&(int)$r['amount']===75000&&$r['addInfo']===$new->transfer_reference);
        $banks->change($root,$edited->id,$edited->version,'disable');$this->assertNull(app(PlatformPayee::class)->details());$this->assertSame('000000999',app(PlatformPayee::class)->configured()['account_no']);
        $unused=$banks->save($root,$this->bankData('111111111'));$banks->change($root,$unused->id,$unused->version,'delete');$this->assertNull(ReceivingAccount::find($unused->id));
        $workspaceAccount=ReceivingAccount::create(['workspace_id'=>\App\Modules\Workspace\Models\Workspace::first()->id,'label'=>'Tiền thuê fixture','bank_bin'=>'970422','bank_name'=>'Nhận tiền thuê','account_no'=>'222222222','account_holder_name'=>'LANDLORD','account_fingerprint'=>hash('sha256','landlord'),'created_by'=>$owner->id]);
        $this->actingAs($root)->post('/actions/platform.bank.disable',['context'=>'platform-admin','id'=>$workspaceAccount->id,'version'=>1,'confirm'=>1])->assertNotFound();$this->assertSame('active',$workspaceAccount->fresh()->status);
    }
    public function test_bank_validation_and_stale_writes_are_rejected():void
    {
        $root=$this->user('supreme');$banks=app(PlatformBankService::class);$bank=$banks->save($root,$this->bankData());$this->actingAs($root);
        $this->post('/actions/platform.bank.save',['context'=>'platform-admin']+$this->bankData())->assertUnprocessable();
        $banks->change($root,$bank->id,$bank->version,'default');
        $this->post('/actions/platform.bank.disable',['context'=>'platform-admin','id'=>$bank->id,'version'=>$bank->version,'confirm'=>1])->assertConflict();
        $this->post('/actions/platform.bank.save',['context'=>'platform-admin','account_no'=>'bad !','bank_bin'=>'invalid'])->assertSessionHasErrors();
    }
    public function test_bank_view_does_not_authorize_writes_or_publishing():void
    {
        $viewer=$this->operator(['bank_account.view']);$response=$this->actingAs($viewer)->get($this->page('banking'))->assertOk();$this->assertEmpty($response->viewData('screen')['forms']);
        foreach(['platform.bank.save','platform.bank.default','platform.bank.disable','platform.bank.delete','plan.publish']as$action)$this->post('/actions/'.$action,['context'=>'platform-admin','id'=>1,'confirm'=>1])->assertForbidden();
        $manager=$this->operator(['bank_account.view','bank_account.create']);$this->actingAs($manager)->post('/actions/platform.bank.save',['context'=>'platform-admin']+$this->bankData())->assertSessionHasNoErrors();$this->post('/actions/plan.publish',['context'=>'platform-admin','id'=>1,'confirm'=>1])->assertForbidden();
    }
    public function test_plan_draft_edit_and_publish_have_independent_permissions_and_immutable_history():void
    {
        $editor=$this->operator(['subscription.plan.edit']);$catalog=app(PlanCatalog::class);$data=$this->planData();$plan=$catalog->save($editor,$data);$this->assertNull($plan->published_at);
        $this->actingAs($editor)->get($this->page('plans'))->assertOk()->assertSee('Sửa bản nháp')->assertDontSee('action="'.url('/actions/plan.publish').'"',false);
        $this->post('/actions/plan.publish',['context'=>'platform-admin','id'=>$plan->id,'confirm'=>1])->assertForbidden();
        $edited=$catalog->save($editor,array_replace($data,['id'=>$plan->id,'expected_fingerprint'=>$plan->fingerprint,'price'=>85000]));$this->assertSame($plan->id,$edited->id);$this->assertSame(85000,$edited->snapshot['price']);
        $publisher=$this->operator(['subscription.plan.publish']);$catalog->publish($publisher,$plan->id);$this->assertNotNull($plan->fresh()->published_at);
        $this->actingAs($publisher)->post('/actions/plan.save',['context'=>'platform-admin']+$data)->assertForbidden();
        $editor=$this->operator(['subscription.plan.edit']);$this->actingAs($editor)->post('/actions/plan.save',['context'=>'platform-admin','id'=>$plan->id,'expected_fingerprint'=>$edited->fingerprint]+$data)->assertConflict();$this->assertSame(85000,$plan->fresh()->snapshot['price']);
        $this->post('/actions/plan.availability',['context'=>'platform-admin','id'=>$plan->id,'expected'=>'public','availability'=>'closed','reason'=>'Không cho người soạn nháp đổi gói đã phát hành'])->assertForbidden();
        $this->post('/actions/plan.quota.assign',['context'=>'platform-admin','owner_id'=>$this->user('owner')->id,'plan_version_id'=>$plan->id,'reason'=>'Không cho người soạn nháp đổi hạn mức tài khoản cũ','confirm'=>1])->assertForbidden();
        $viewer=$this->operator(['subscription.plan.view']);$this->actingAs($viewer)->get($this->page('plans'))->assertOk();$this->post('/actions/plan.save',['context'=>'platform-admin']+$data)->assertForbidden();
    }
    public function test_transaction_viewer_cannot_confirm_or_edit_prices_and_approver_cannot_edit_prices():void
    {
        [$root,$owner,$account,$quote]=$this->fixture();app(AccountSubscriptionService::class)->report($owner,$quote,'PAYER-ONLY','Báo chuyển tiền fixture');
        $viewer=$this->operator(['payment.view']);$response=$this->actingAs($viewer)->get($this->page('payments'))->assertOk();$this->assertEmpty($response->viewData('screen')['records'][0]['forms']);
        $data=['context'=>'platform-admin','id'=>$quote->id,'amount'=>$quote->amount,'reference'=>'BUSINESS-VIEW-ONLY','note'=>'Đối soát fixture','confirm'=>1];$this->post('/actions/account.subscription.confirm',$data)->assertForbidden();
        $approver=$this->operator(['subscription.approve']);$this->actingAs($approver)->post('/actions/account.subscription.confirm',$data)->assertSessionHasNoErrors();$this->post('/actions/plan.save',['context'=>'platform-admin']+$this->planData())->assertForbidden();
        $this->assertSame(1,SubscriptionCycle::count());
    }
    public function test_full_once_payment_report_and_duplicate_confirmation_produce_exactly_one_receipt():void
    {
        [$root,$owner,$account,$quote]=$this->fixture();$service=app(AccountSubscriptionService::class);$this->assertSame('0',$this->totals()['received']);
        $service->report($owner,$quote,'PAYER-TEST','Báo chuyển chỉ thuộc fixture');$this->assertSame('0',$this->totals()['received']);
        $this->actingAs($root)->post('/actions/account.subscription.confirm',['context'=>'platform-admin','id'=>$quote->id,'amount'=>10000,'reference'=>'PARTIAL-NOT-ALLOWED','note'=>'Không nhận thiếu tiền gói','confirm'=>1])->assertConflict();$this->assertSame(0,SubscriptionCycle::count());$this->assertSame('trial',$account->fresh()->status);
        $service->confirm($root,$quote->id,75000,'FULL-ONCE','Đã đối soát fixture');$service->confirm($root,$quote->id,75000,'FULL-ONCE','Lặp cùng giao dịch');
        $this->assertSame(1,SubscriptionCycle::count());$this->assertSame('75000',$this->totals()['received']);$this->assertSame('active',$account->fresh()->status);
        app(\App\Modules\Billing\BillingService::class)->pay($this->user('owner'),\App\Modules\Billing\Models\Invoice::firstOrFail(),['amount'=>10000,'method'=>'cash','reference'=>'LANDLORD-NOT-PLATFORM']);
        $this->assertSame('75000',$this->totals()['received']);$this->assertSame('75000',(string)DB::table('subscription_cycles')->whereNull('reversed_at')->sum('verified_amount'));
    }
    public function test_existing_accounting_platform_verify_permission_confirms_without_legacy_or_plan_authority():void
    {
        [$root,$owner,$account,$quote]=$this->fixture();app(AccountSubscriptionService::class)->report($owner,$quote,'PAYER-ACCOUNTING','Báo chuyển trong fixture');
        $verifier=$this->operator(['payment.verify']);$this->actingAs($verifier)->get($this->page('payments'))->assertOk()->assertSee('data-record-list',false);
        $this->get($this->page('order',['id'=>$quote->id]))->assertOk()->assertSee('Tôi đã nhận');
        foreach(['plans','legacy','requests','revenue','banking']as$section)$this->get($this->page($section))->assertForbidden();
        $this->post('/actions/account.subscription.confirm',['context'=>'platform-admin','id'=>$quote->id,'amount'=>$quote->amount,'reference'=>'ACCOUNTING-VERIFIER','note'=>'Kiểm tra quyền con Kế toán hiện có','confirm'=>1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active',$account->fresh()->status);$this->assertSame(1,SubscriptionCycle::count());
        $this->post('/actions/plan.publish',['context'=>'platform-admin','id'=>$quote->plan_version_id,'confirm'=>1])->assertForbidden();
        $this->operator(['account.view']);$this->post('/actions/account.subscription.confirm',['context'=>'platform-admin','id'=>$quote->id,'amount'=>$quote->amount,'reference'=>'ACCOUNTING-VERIFIER','note'=>'Không được dùng quyền đã thu hồi','confirm'=>1])->assertForbidden();
    }
    public function test_dashboard_report_csv_detail_and_sql_share_filter_and_exact_total():void
    {
        [$root,$owner,$account,$quote]=$this->fixture();$this->receive($root,$owner,$quote);
        $this->actingAs($root);foreach(['business','revenue']as$section){$r=$this->get($this->page($section,['period'=>'year']))->assertOk();$this->assertSame('75000',$section==='business'?$r->viewData('screen')['dashboard']['metrics']['received']:$r->viewData('screen')['business']['received']);}
        $csv=$this->get('/quan-tri/kinh-doanh/bao-cao.csv?context=platform-admin&period=year')->assertOk()->streamedContent();$rows=array_map(fn($line)=>str_getcsv($line,',','"',''),array_values(array_filter(explode("\n",trim($csv)))));$this->assertCount(2,$rows);$this->assertSame('75000',$rows[1][4]);
        $this->get($this->page('order',['id'=>$quote->id]))->assertOk()->assertSee('Điều kiện gói đã xác lập')->assertSee('Người mua báo chuyển tiền');
        $this->assertSame('0',$this->totals('&plan=yearly')['received']);$this->assertSame('0',$this->totals('&q=NOT-MATCHED')['received']);
        $this->assertSame('75000',$this->totals('&q=BUSINESS-BANK-ONE')['received']);
    }
    public function test_revenue_requires_view_and_independent_export_permission_and_revocation_is_immediate():void
    {
        [$root,$owner,$account,$quote]=$this->fixture();$this->receive($root,$owner,$quote);$viewer=$this->operator(['subscription.revenue.view']);$this->actingAs($viewer)->get($this->page('revenue'))->assertOk()->assertDontSee('Xuất CSV theo bộ lọc');$this->get('/quan-tri/kinh-doanh/bao-cao.csv?context=platform-admin')->assertForbidden();
        $exporter=$this->operator(['subscription.revenue.view','payment.export']);$this->actingAs($exporter)->get('/quan-tri/kinh-doanh/bao-cao.csv?context=platform-admin')->assertOk();
        $revoked=$this->operator(['account.view']);$this->actingAs($revoked)->get($this->page('revenue'))->assertForbidden();$this->get('/quan-tri/kinh-doanh/bao-cao.csv?context=platform-admin')->assertForbidden();
        $shell=$this->get($this->page(''))->assertOk()->viewData('shell');$this->assertFalse($shell['items']->contains(fn($i)=>str_contains($i['url'],'section=revenue')));
    }
    public function test_direct_grant_provenance_and_revocation_apply_to_business_service_and_menu():void
    {
        $root=$this->user('supreme');$recipient=$this->user('user');$grant=app(AccountGrantService::class)->issue($root,['user_id'=>$recipient->id,'permission'=>'bank_account.create','scope_type'=>'platform','valid_from'=>now()->toDateTimeString(),'valid_until'=>now()->addHour()->toDateTimeString(),'reason'=>'Cấp quyền ngân hàng nền tảng fixture']);
        $this->assertTrue(app(Authorization::class)->global($recipient,'bank_account.create'));$this->actingAs($recipient)->post('/actions/platform.bank.save',['context'=>'platform-admin']+$this->bankData())->assertSessionHasNoErrors();
        app(WorkspaceService::class)->revokeGrant($root,$grant->id);$this->post('/actions/platform.bank.save',['context'=>'platform-admin']+$this->bankData('888888888'))->assertForbidden();$this->get($this->page('banking'))->assertForbidden();$this->assertSame(1,app(PlatformBankService::class)->accounts()->count());
    }
    public function test_workspace_finance_permissions_do_not_admit_platform_business():void
    {
        foreach(['owner','accountant']as$key){$this->actingAs($this->user($key));foreach(['business','revenue','payments','banking','plans','requests']as$section)$this->get($this->page($section))->assertForbidden();$this->get('/quan-tri/kinh-doanh/bao-cao.csv?context=platform-admin')->assertForbidden();}
    }
    public function test_receipt_period_uses_local_midnight_and_exact_integer_sums():void
    {
        [$root,$owner,$account,$quote]=$this->fixture();$this->receive($root,$owner,$quote);config(['platform_business.storage_timezone'=>'UTC']);$date=now('Asia/Ho_Chi_Minh')->startOfDay();
        $period=BusinessPeriod::request(Request::create('/?period=today'));$this->assertSame($date->copy()->utc()->format('Y-m-d H:i:s'),$period->bounds()[0]);
        DB::table('subscription_cycles')->update(['verified_at'=>$date->copy()->utc()->subSecond()->format('Y-m-d H:i:s')]);$this->assertSame('0',app(RevenueRepository::class)->totals($root,$period)['received']);
        DB::table('subscription_cycles')->update(['verified_at'=>$date->copy()->utc()->format('Y-m-d H:i:s')]);$this->assertSame('75000',app(RevenueRepository::class)->totals($root,$period)['received']);
        DB::table('subscription_cycles')->update(['verified_at'=>$date->copy()->addDay()->utc()->format('Y-m-d H:i:s')]);$this->assertSame('0',app(RevenueRepository::class)->totals($root,$period)['received']);
        $this->assertSame('9007199254740994',Vnd::add('9007199254740993','1'));$this->assertSame('18.446.744.073.709.551.615 ₫',Vnd::format('18446744073709551615'));
    }
    public function test_report_filters_validate_dates_and_csv_neutralizes_spreadsheet_formulas():void
    {
        [$root,$owner,$account,$quote]=$this->fixture();$owner->forceFill(['name'=>'=HYPERLINK("example")'])->save();$this->receive($root,$owner,$quote);$this->actingAs($root);
        $this->get($this->page('revenue',['period'=>'custom','from'=>'2026-11-10','until'=>'2026-01-01']))->assertUnprocessable();
        $this->get($this->page('revenue',['period'=>'custom','from'=>'not-date','until'=>'2026-01-01']))->assertSessionHasErrors('from');
        $csv=$this->get('/quan-tri/kinh-doanh/bao-cao.csv?context=platform-admin&period=year')->assertOk()->streamedContent();$this->assertStringContainsString("'=HYPERLINK",$csv);
    }
    public function test_reversed_receipt_and_legacy_records_are_excluded_from_received_money():void
    {
        [$root,$owner,$account,$quote]=$this->fixture();$this->receive($root,$owner,$quote);$cycle=SubscriptionCycle::firstOrFail();
        $this->actingAs($root)->post('/actions/account.subscription.reverse',['context'=>'platform-admin','id'=>$cycle->id,'evidence'=>'Đã kiểm chứng khoản tiền bị đảo trong fixture','confirm'=>1])->assertSessionHasNoErrors();
        \App\Modules\Subscription\Models\SubscriptionOrder::create(['workspace_id'=>\App\Modules\Workspace\Models\Workspace::firstOrFail()->id,'plan'=>'yearly','reference'=>'LEGACY-NOT-REVENUE','status'=>'confirmed']);
        $this->assertSame('0',$this->totals()['received']);$this->assertSame(1,SubscriptionCycle::count());
        $screen=$this->get($this->page('payments'))->assertOk()->viewData('screen');$card=collect($screen['records'])->firstWhere('subtitle',$quote->reference);$this->assertSame('Cần đối soát',$card['facts']['Số còn thiếu']);
        $this->get($this->page('payments',['tab'=>'history']))->assertOk()->assertSee('Đã đảo giao dịch');$this->get($this->page('order',['id'=>$quote->id]))->assertOk()->assertSee('Đã đảo giao dịch');
    }
}
