<?php
namespace Tests\Feature;
use App\Models\User;
use App\Modules\Subscription\{PlanCatalog,BusinessPeriod,RevenueRepository};
use App\Modules\Subscription\Models\{SubscriptionQuote,SubscriptionCycle};
use Database\Seeders\{DatabaseSeeder,TroHubDemoV1Seeder,BusinessHistorySeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Http,Mail,Storage};
use Tests\TestCase;

class BusinessHistoryDatasetTest extends TestCase
{
    use RefreshDatabase;
    public function test_bounded_history_is_idempotent_preserves_legacy_and_cannot_be_presented_as_real_money():void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-10 12:00:00'));Mail::fake();Http::preventStrayRequests();Storage::fake('local');$this->seed(DatabaseSeeder::class);
        for($i=1;$i<=70;$i++)foreach([0,1]as$v)Storage::disk('local')->put("housing-images/demo-v1/property-{$i}-{$v}.png",base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='));
        config(['demo_v1.password'=>'Testing-only-password','demo_v1.reference_date'=>'2026-11-02','subscriptions.platform_payee'=>['bank_bin'=>'970422','bank_name'=>'MB fixture','account_no'=>'000000999','account_holder_name'=>'FIXTURE']]);$this->seed(TroHubDemoV1Seeder::class);
        $before=[];foreach(['users','workspaces','rooms','rentals','contract_versions','invoices','payments','subscription_orders','memberships','direct_grants']as$t)$before[$t]=DB::table($t)->orderBy('id')->get()->toJson();
        $this->seed(BusinessHistorySeeder::class);$counts=[];foreach(['plan_versions','account_subscriptions','subscription_quotes','subscription_cycles','payments','demo_dataset_records']as$t)$counts[$t]=DB::table($t)->count();$this->seed(BusinessHistorySeeder::class);foreach($counts as$t=>$n)$this->assertSame($n,DB::table($t)->count(),$t);
        foreach($before as$t=>$rows){$old=json_decode($rows);$ids=array_column($old,'id');$this->assertSame($rows,DB::table($t)->whereIn('id',$ids)->orderBy('id')->get()->toJson(),$t);}
        $this->assertSame(68,SubscriptionQuote::count());$this->assertSame(50,SubscriptionCycle::count());$root=User::where('email','supreme@trohub.test')->firstOrFail();$repo=app(RevenueRepository::class);
        $total=$repo->totals($root,BusinessPeriod::request(Request::create('/?period=custom&from=2025-01-01&until=2026-11-10')));$this->assertSame('7742000',$total['received']);$this->assertSame($total['received'],$total['simulation_received']);$this->assertSame('0',$total['operational_received']);
        $real=$repo->totals($root,BusinessPeriod::request(Request::create('/?period=year&source=operational')));$this->assertSame('0',$real['received']);
        foreach(['trial','monthly','yearly']as$key)$this->assertNull(app(PlanCatalog::class)->selectable($key));
        foreach(SubscriptionCycle::orderBy('starts_at')->get()->groupBy('account_subscription_id')as$cycles){$last=null;foreach($cycles as$c){$this->assertTrue($c->starts_at->gte($c->verified_at));$this->assertTrue($c->applied_at->gte($c->starts_at));$this->assertTrue($c->verified_at->lte(now()));if($last)$this->assertTrue($c->starts_at->gte($last));$last=$c->ends_at;}}
        Http::fake(['img.vietqr.io/*'=>Http::response('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>',200,['Content-Type'=>'image/svg+xml'])]);
        $quote=SubscriptionQuote::where('status','pending')->where('expires_at','>',now())->firstOrFail();$owner=User::findOrFail($quote->accountSubscription->owner_id);$this->actingAs($owner)->get('/thanh-toan-goi/'.$quote->id.'/ma-qr?context=personal')->assertOk();Http::assertSentCount(1);Mail::assertNothingOutgoing();
        $historyOwnerId=DB::table('account_subscriptions')->whereNull('authority_enabled_at')->whereIn('id',SubscriptionCycle::select('account_subscription_id'))->value('owner_id');$this->assertNotNull($historyOwnerId);$existingOwner=User::findOrFail($historyOwnerId);$this->actingAs($existingOwner)->get('/ui/landlord-upgrade?context=personal')->assertOk()->assertSee('Lịch sử mua gói')->assertDontSee('mô phỏng');
        $this->actingAs($root)->get('/ui/admin-dashboard?context=platform-admin&section=business')->assertOk()->assertDontSee('mô phỏng');$this->get('/quan-tri/kinh-doanh/bao-cao.csv?context=platform-admin&period=year')->assertOk();
        $this->post('/actions/plan.availability',['context'=>'platform-admin','id'=>$quote->plan_version_id,'expected'=>'archived','availability'=>'public','reason'=>'Không mở bán mẫu'])->assertConflict();
    }
}
