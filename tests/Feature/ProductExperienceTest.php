<?php
namespace Tests\Feature;
use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Subscription\{AccountSubscriptionService,PlanCatalog};
use App\Modules\Workspace\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http,Mail};
use Tests\TestCase;

class ProductExperienceTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->freezeTime();Mail::fake();Http::preventStrayRequests();$this->seed(DatabaseSeeder::class);}
    private function user(string $key):User{return User::where('email',$key.'@trohub.test')->firstOrFail();}
    private function admin(array $params=[]):string{return '/ui/admin-accounts?'.http_build_query(['context'=>'platform-admin']+$params);}
    private function workspace(string $slug,array $params=[]):string{return '/ui/'.$slug.'?'.http_build_query(['context'=>'workspace','workspace_id'=>Workspace::where('owner_id',$this->user('owner')->id)->oldest('id')->value('id')]+$params);}
    public function test_paginated_account_table_opens_the_exact_record_and_preserves_return_filters():void
    {
        User::factory()->count(20)->create(['name'=>'Người dùng kiểm thử']);$this->actingAs($this->user('supreme'));
        $url=$this->admin(['page'=>2,'order'=>'oldest']);$r=$this->get($url)->assertOk()->assertSee('data-record-list',false);$screen=$r->viewData('screen');$this->assertCount(config('business.pagination'),$screen['record_table']['records']);
        $row=$screen['record_table']['records'][0];$r=$this->get($row['_detail_url'])->assertOk()->assertSee('data-record-detail',false);$detail=$r->viewData('screen')['record_detail'];$this->assertSame($row['_key'],$detail['record']['_key']);$this->assertStringContainsString('page=2',$detail['back_url']);$this->assertStringContainsString('order=oldest',$detail['back_url']);$this->assertNull($r->viewData('screen')['pagination']);
    }
    public function test_order_control_changes_the_whole_query_before_pagination():void
    {
        $this->actingAs($this->user('supreme'));$old=$this->get($this->admin(['order'=>'oldest']))->assertOk()->viewData('screen')['record_table']['records'][0]['_key'];$new=$this->get($this->admin(['order'=>'newest']))->assertOk()->viewData('screen')['record_table']['records'][0]['_key'];
        $this->assertSame('users:'.User::min('id'),$old);$this->assertSame('users:'.User::max('id'),$new);
    }
    public function test_pagination_and_error_pages_are_vietnamese_and_do_not_expose_source_details():void
    {
        $this->actingAs($this->user('supreme'));$this->get($this->admin())->assertOk()->assertSee('Hiển thị')->assertDontSee('Showing');
        $this->get('/ui/not-a-screen?context=personal')->assertNotFound()->assertSee('Không tìm thấy trang')->assertDontSee('vendor/');
        $this->actingAs($this->user('tenant'))->get($this->admin())->assertForbidden()->assertSee('Bạn chưa có quyền truy cập')->assertDontSee('Forbidden');
    }
    public function test_detail_selector_cannot_bypass_workspace_or_tenant_scope():void
    {
        $i=Invoice::firstOrFail();$this->actingAs($this->user('other'))->get($this->workspace('billing',['item'=>'invoices:'.$i->id]))->assertForbidden();
        $otherWorkspace=Workspace::where('owner_id',$this->user('other')->id)->firstOrFail();$url='/ui/billing?'.http_build_query(['context'=>'workspace','workspace_id'=>$otherWorkspace->id,'item'=>'invoices:'.$i->id]);$this->get($url)->assertNotFound();
        $this->actingAs($this->user('tenant'))->get($this->admin(['item'=>'users:'.$this->user('supreme')->id]))->assertForbidden();$this->get('/ui/my-invoices?context=personal&item=invoices:999999')->assertNotFound();
    }
    public function test_detail_access_rechecks_permissions_after_a_list_was_opened():void
    {
        $user=$this->user('platform');$group=$user->access_group_id;DB::table('access_groups')->where('id',$group)->update(['permissions'=>json_encode(['account.view'])]);$this->actingAs($user);$row=$this->get($this->admin())->assertOk()->viewData('screen')['record_table']['records'][0];DB::table('access_groups')->where('id',$group)->update(['permissions'=>'[]']);$this->get($row['_detail_url'])->assertForbidden();
    }
    public function test_people_group_detail_retains_permission_and_action_information():void
    {
        $this->actingAs($this->user('supreme'));$r=$this->get('/ui/access-control?context=system-admin&section=groups')->assertOk();$row=$r->viewData('screen')['record_table']['records'][0];$detail=$this->get($row['_detail_url'])->assertOk()->assertSee('data-record-detail',false)->viewData('screen')['record_detail']['record'];$this->assertSame($row['permissions'],$detail['permissions']);$this->assertSame($row['forms'],$detail['forms']);
    }
    public function test_managed_housing_uses_table_and_public_discovery_keeps_photos():void
    {
        $this->actingAs($this->user('owner'));$r=$this->get($this->workspace('properties'))->assertOk()->assertSee('data-record-list',false);$row=$r->viewData('screen')['record_table']['records'][0];$this->assertNotEmpty($row['images']);$this->get($row['_detail_url'])->assertOk();$list=$this->get($this->workspace('workspace-listings'))->assertOk()->viewData('screen')['record_table'];$this->assertContains('Giá thuê',$list['columns']);$this->assertContains('Diện tích',$list['columns']);$this->get('/ui/marketplace-search?context=public')->assertOk()->assertSee('housing-public-cards',false)->assertDontSee('data-record-list',false);
    }
    public function test_invoice_detail_renders_full_business_information_and_not_template_directives():void
    {
        $this->actingAs($this->user('owner'));$r=$this->get($this->workspace('billing'))->assertOk();$row=$r->viewData('screen')['record_table']['records'][0];
        $this->get($row['_detail_url'])->assertOk()->assertSee('Tổng phải thu')->assertSee('Còn phải trả')->assertDontSee('@include')->assertDontSee('@endif');
    }
    public function test_read_only_staff_do_not_receive_hidden_edit_actions_or_account_controls():void
    {
        $this->actingAs($this->user('accountant'));$r=$this->get($this->workspace('billing'))->assertOk()->assertSee('data-record-list',false);$row=$r->viewData('screen')['record_table']['records'][0];$this->assertFalse(collect($row['forms'])->contains(fn($f)=>$f['action']==='invoice.create'));$this->get($this->admin())->assertForbidden();
    }
    public function test_successful_detail_action_returns_to_the_filtered_list_and_validation_keeps_detail():void
    {
        $root=$this->user('supreme');$target=$this->user('user');$list=$this->admin(['status'=>'active']);$this->actingAs($root);$r=$this->get($list)->assertOk();$row=collect($r->viewData('screen')['record_table']['records'])->firstWhere('_key','users:'.$target->id);$this->assertNotNull($row);
        $detail=$row['_detail_url'];$this->from($detail)->post('/actions/account.status',['context'=>'platform-admin','id'=>$target->id,'status'=>'locked'])->assertRedirect($detail)->assertSessionHasErrors('reason');$this->assertSame('active',$target->fresh()->status);
        $this->from($detail)->post('/actions/account.status',['context'=>'platform-admin','id'=>$target->id,'status'=>'locked','reason'=>'Tạm khóa theo yêu cầu hỗ trợ'])->assertRedirect($list)->assertSessionHasNoErrors();$this->assertSame('locked',$target->fresh()->status);$this->get($list)->assertOk()->assertSee('data-record-list',false);
    }
    public function test_demo_origin_is_kept_in_metadata_while_checkout_and_csv_look_like_the_product():void
    {
        config(['subscriptions.account_authority_enabled'=>true,'subscriptions.platform_payee'=>['bank_bin'=>'970422','bank_name'=>'MB fixture','account_no'=>'000000999','account_holder_name'=>'FIXTURE']]);$root=$this->user('supreme');$owner=$this->user('user');$catalog=app(PlanCatalog::class);
        foreach(['trial','monthly']as$key){$d=['plan'=>$key,'label'=>$key==='trial'?'Dùng thử':'Theo tháng','price'=>$key==='trial'?0:75000,'duration_count'=>1,'duration_unit'=>'months','features'=>['workspace'],'reason'=>'Fixture'];foreach(['workspaces','properties','rooms','staff']as$kind)$d[$kind.'_mode']='unlimited';$p=$catalog->save($root,$d);$catalog->publish($root,$p->id);}
        $service=app(AccountSubscriptionService::class);$service->trial($owner);$quote=$service->quote($owner,'monthly','UX-CHECKOUT');DB::table('demo_dataset_records')->insert(['dataset'=>'UX_FIXTURE','table_name'=>'subscription_quotes','demo_key'=>'quote','record_id'=>(string)$quote->id,'created_at'=>now()]);
        $this->actingAs($owner);$r=$this->get('/ui/landlord-upgrade?context=personal')->assertOk()->assertDontSee('mô phỏng');$payment=$r->viewData('screen')['payment_table']['records'][0];$this->get($payment['_detail_url'])->assertOk()->assertSee('Tôi đã chuyển')->assertDontSee('mô phỏng');$this->assertTrue(DB::table('demo_dataset_records')->where('dataset','UX_FIXTURE')->exists());
        $this->actingAs($this->user('tenant'))->get($payment['_detail_url'])->assertNotFound();
        $this->actingAs($root);$r=$this->get('/ui/admin-dashboard?context=platform-admin&section=requests')->assertOk()->assertSee('data-record-list',false)->assertDontSee('mô phỏng')->assertDontSee('Nguồn số liệu');$this->assertStringContainsString('section=order',$r->viewData('screen')['record_table']['records'][0]['_detail_url']);
        $csv=$this->get('/quan-tri/kinh-doanh/bao-cao.csv?context=platform-admin')->assertOk()->streamedContent();$header=str_getcsv(explode("\n",trim($csv))[0],',','"','');$this->assertCount(6,$header);$this->assertStringNotContainsString('Nguồn số liệu',$csv);Mail::assertNothingOutgoing();
    }
}
