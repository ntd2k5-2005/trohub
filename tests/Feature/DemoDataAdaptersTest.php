<?php

namespace Tests\Feature;

use App\Modules\DataPlatform\Adapters\ResilientCache;
use App\Modules\Listing\{ListingSearchIndex, ListingService};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Http};
use Tests\TestCase;

class DemoDataAdaptersTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_index_rechecks_sql_when_a_previously_indexed_listing_is_hidden():void
    {
        $this->seed(DatabaseSeeder::class);$l=app(ListingService::class)->available()->firstOrFail();
        config(['data_stores.meilisearch.enabled'=>true,'data_stores.meilisearch.host'=>'http://meili.test','data_stores.meilisearch.index'=>'test_listings']);
        Http::fake(['meili.test/*'=>Http::response(['hits'=>[['id'=>$l->id,'title'=>'public result']],'estimatedTotalHits'=>1])]);
        $index=new ListingSearchIndex;$this->assertCount(1,$index->search('phòng')['hits']);
        $l->update(['status'=>'paused']);$r=$index->search('phòng');$this->assertSame([],$r['hits']);$this->assertArrayNotHasKey('estimatedTotalHits',$r);
    }
    public function test_disabled_search_is_explicitly_unavailable():void
    {
        config(['data_stores.meilisearch.enabled'=>false]);Http::preventStrayRequests();$this->expectException(\RuntimeException::class);(new ListingSearchIndex)->search('phòng');
    }
    public function test_redis_failure_uses_authoritative_source():void
    {
        config(['data_stores.redis.enabled'=>true]);Cache::shouldReceive('store')->with('redis')->once()->andThrow(new \RuntimeException('offline boundary'));
        $calls=0;$result=(new ResilientCache)->remember('notifications:fixture',20,function()use(&$calls){$calls++;return 7;});$this->assertSame(7,$result);$this->assertSame(1,$calls);
    }
    public function test_utilities_page_reads_the_service_rate_relationship_with_real_readings():void
    {
        $this->seed(DatabaseSeeder::class);
        $owner=\App\Models\User::where('email','owner@trohub.test')->firstOrFail();
        $w=\App\Modules\Workspace\Models\Workspace::where('owner_id',$owner->id)->firstOrFail();$room=$w->properties()->first()->rooms()->first();
        $rate=\App\Modules\Billing\Models\ServiceRate::where('property_id',$room->property_id)->where('kind','electricity')->firstOrFail();
        $meter=\App\Modules\Billing\Models\MeterReading::create(['room_id'=>$room->id,'service_rate_id'=>$rate->id,'period'=>now()->format('Y-m'),'previous'=>100,'current'=>145,'usage'=>45,'actor_id'=>$owner->id]);
        $this->assertSame($rate->id,$meter->fresh()->rate->id);
        $this->actingAs($owner)->get('/ui/utilities?context=workspace&workspace_id='.$w->id)->assertOk()->assertSee($rate->name);
    }
}
