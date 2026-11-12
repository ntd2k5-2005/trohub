<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Subscription\AccountSubscriptionService;
use App\Modules\Subscription\Models\SubscriptionOrder;
use App\Modules\Subscription\PlanCatalog;
use App\Modules\Workspace\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminOrganizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->seed(DatabaseSeeder::class); }
    private function user(string $key): User { return User::where('email', $key.'@trohub.test')->firstOrFail(); }
    private function page(string $section = ''): string { return '/ui/admin-dashboard?context=platform-admin'.($section ? '&section='.$section : ''); }

    public function test_supreme_can_find_sections_in_sidebar_and_each_url_has_one_active_item(): void
    {
        $this->actingAs($this->user('supreme'));
        $response=$this->get($this->page());$response->assertOk()->assertSee('Quản trị kinh doanh');
        foreach (config('navigation.admin_sections') as $section=>$definition) {
            if($section==='order')continue; // Details require a real quote ID, covered in PlatformBusinessTest.
            $response=$this->get($this->page($section))->assertOk();
            $shell=$response->viewData('shell');$this->assertCount(1,$shell['items']->where('active',true));
            $this->assertStringContainsString($section==='ownership'?'/ui/admin-accounts':'section='.($section==='legacy'?'requests':$section),$shell['items']->firstWhere('active',true)['url']);
            $this->assertSame($definition['label'],$response->viewData('page')['title']);
        }
        $this->get('/ui/data-platform?context=system-admin')->assertOk();
        $this->get('/ui/properties?context=workspace&workspace_id='.Workspace::first()->id)->assertOk();
    }

    public function test_financial_reviewer_cannot_publish_plans_or_see_other_administration_sections(): void
    {
        $this->actingAs($this->user('platform'));
        $response=$this->get($this->page())->assertOk();$items=$response->viewData('shell')['items'];
        $this->assertTrue($items->contains(fn($item)=>str_contains($item['url'],'section=requests')));
        $this->assertFalse($items->contains(fn($item)=>str_contains($item['url'],'section=plans')));
        foreach (['plans','banking','ownership'] as $section) $this->get($this->page($section))->assertForbidden();
        foreach (['requests','payments','legacy'] as $section) $this->get($this->page($section))->assertOk();
        $this->post('/actions/plan.publish',['context'=>'platform-admin','id'=>1,'confirm'=>1])->assertForbidden();
        $this->get('/ui/data-platform?context=system-admin')->assertForbidden();
    }

    public function test_moderation_only_permission_does_not_admit_financial_sections(): void
    {
        $user=$this->user('platform');
        // Narrow an existing group only in this isolated fixture; no real account/role is added.
        DB::table('access_groups')->where('id',$user->access_group_id)->update(['permissions'=>json_encode(['listing.moderate'])]);
        $this->actingAs($user)->get('/ui/moderation?context=platform-admin')->assertOk();
        foreach (['requests','payments','plans','legacy','banking','ownership'] as $section) $this->get($this->page($section))->assertForbidden();
    }

    public function test_workspace_accountant_and_owner_are_not_platform_finance_operators(): void
    {
        foreach (['accountant','owner'] as $key) {
            $this->actingAs($this->user($key))->get('/ui/billing?context=workspace')->assertOk();
            foreach (['requests','payments','plans','legacy','banking','ownership'] as $section) $this->get($this->page($section))->assertForbidden();
            $this->post('/actions/account.subscription.confirm',['context'=>'personal','id'=>1,'amount'=>100,'reference'=>'NOT-AUTHORIZED','note'=>'Outside scope','confirm'=>1])->assertForbidden();
        }
    }

    public function test_bank_view_permission_alone_provides_platform_context_without_other_privileges(): void
    {
        $user=$this->user('platform');DB::table('access_groups')->where('id',$user->access_group_id)->update(['permissions'=>json_encode(['bank_account.view'])]);
        $this->actingAs($user)->get($this->page('banking'))->assertOk()->assertSee('Tài khoản ngân hàng nền tảng');
        $this->get($this->page('plans'))->assertForbidden();$this->get($this->page('requests'))->assertForbidden();
    }

    public function test_dashboard_is_bounded_and_management_supports_search_filter_and_pagination(): void
    {
        config(['subscriptions.platform_payee'=>['bank_bin'=>'970422','bank_name'=>'Ngân hàng kiểm thử','account_no'=>'000000999','account_holder_name'=>'PLATFORM TEST']]);
        $admin=$this->user('supreme');$catalog=app(PlanCatalog::class);
        foreach (['trial','monthly'] as $key) {
            $data=['plan'=>$key,'label'=>'Gói chỉ dùng trong kiểm thử','price'=>$key==='trial'?0:75000,'duration_count'=>1,'duration_unit'=>'months','features'=>['workspace'],'reason'=>'Fixture điều hướng quản trị'];
            foreach (['workspaces','properties','rooms','staff'] as $kind) $data[$kind.'_mode']='unlimited';
            $plan=$catalog->save($admin,$data);$catalog->publish($admin,$plan->id);
        }
        $owner=$this->user('user');$service=app(AccountSubscriptionService::class);$service->trial($owner);
        $number=config('business.pagination')+2;for($i=1;$i<=$number;$i++) $service->quote($owner,'monthly','ADMIN-REQUEST-'.$i);
        $this->actingAs($admin);$response=$this->get($this->page())->assertOk()->assertSee('Xem tất cả yêu cầu gói');
        $this->assertLessThanOrEqual(config('navigation.admin_preview_limit'),count($response->viewData('screen')['records']));
        $response=$this->get($this->page('requests'))->assertOk();$this->assertSame($number,$response->viewData('screen')['pagination']->total());
        $response=$this->get($this->page('requests').'&q=ADMIN-REQUEST-14')->assertOk();$this->assertCount(1,$response->viewData('screen')['records']);
        $response=$this->get($this->page('requests').'&status=confirmed')->assertOk();$this->assertCount(0,$response->viewData('screen')['records']);
        $this->get($this->page('requests').'&page=2')->assertOk()->assertSee('ADMIN-REQUEST-1');
    }

    public function test_legacy_order_confirmation_does_not_claim_payment_or_guess_amount_and_period(): void
    {
        $workspace=Workspace::where('owner_id',$this->user('owner')->id)->firstOrFail();
        $order=SubscriptionOrder::create(['workspace_id'=>$workspace->id,'plan'=>'yearly','reference'=>'LEGACY-UI-ONLY','status'=>'confirmed']);
        $before=DB::table('subscription_orders')->orderBy('id')->get()->toJson();$period=$workspace->expires_at->copy();
        $response=$this->actingAs($this->user('supreme'))->get($this->page('legacy').'&owner_id='.$workspace->owner_id)->assertOk()->assertSee('Đã ghi nhận hồ sơ')->assertSee('Chưa có xác nhận thực nhận')->assertSee('Số tiền đã đối soát')->assertSee('Chưa xác định');
        $card=collect($response->viewData('screen')['records'])->firstWhere('title','Hồ sơ đăng ký cũ');
        $this->assertSame('Đã ghi nhận hồ sơ',$card['facts']['Trạng thái hồ sơ']);
        $this->assertSame($before,DB::table('subscription_orders')->orderBy('id')->get()->toJson());$this->assertTrue($workspace->fresh()->expires_at->equalTo($period));
    }
}
