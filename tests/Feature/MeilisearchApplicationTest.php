<?php

namespace Tests\Feature;

use App\Modules\Listing\ListingSearchIndex;
use App\Modules\Listing\ListingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MeilisearchApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Real commits are required to test afterCommit. Never rollback protected policy migrations.
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(DatabaseSeeder::class);
        config(['data_stores.meilisearch.enabled' => true, 'data_stores.meilisearch.host' => 'http://phase2-meili.test', 'data_stores.meilisearch.index' => 'application', 'data_stores.meilisearch.state_store' => 'array']);
        Http::preventStrayRequests();
    }

    public function test_public_screen_uses_index_candidates_and_preserves_pagination_and_scope(): void
    {
        $listing = app(ListingService::class)->available()->firstOrFail();
        $index = app(ListingSearchIndex::class);
        Http::fake(['phase2-meili.test/*' => Http::response(['hits' => [$index->document($listing)], 'estimatedTotalHits' => 1])]);
        $this->get('/ui/marketplace-search?context=public&q=tu-khoa-chi-index-tra-ve')->assertOk()->assertHeader('X-TroHub-Search-Engine', 'meilisearch')->assertSee($listing->title);
        Http::assertSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/search') && $request['q'] === 'tu-khoa-chi-index-tra-ve');
        DB::table('listings')->where('id', $listing->id)->update(['status' => 'paused']);
        $this->get('/ui/marketplace-search?context=public&q=tu-khoa-chi-index-tra-ve')->assertOk()->assertDontSee($listing->title);
    }

    public function test_stale_values_fall_back_to_current_sql_without_leaking_old_text_or_price(): void
    {
        $listing = app(ListingService::class)->available()->firstOrFail();
        $old = app(ListingSearchIndex::class)->document($listing);
        Http::fake(['phase2-meili.test/*' => Http::response(['hits' => [$old], 'estimatedTotalHits' => 1])]);
        DB::table('listings')->where('id', $listing->id)->update(['title' => 'Tên mới an toàn']);
        $this->get('/ui/marketplace-search?context=public&q='.urlencode($old['title']))->assertOk()->assertHeader('X-TroHub-Search-Engine', 'sql-fallback')->assertDontSee('Tên mới an toàn');
        $this->assertTrue(app(ListingSearchIndex::class)->dirty());
        $this->get('/ui/marketplace-search?context=public&q='.urlencode('Tên mới an toàn'))->assertOk()->assertHeader('X-TroHub-Search-Engine', 'sql-fallback')->assertSee('Tên mới an toàn');
    }

    public function test_outage_fallback_searches_description_and_current_filters(): void
    {
        $listing = app(ListingService::class)->available()->firstOrFail();
        DB::table('listings')->where('id', $listing->id)->update(['description' => 'Mô tả duy nhất để kiểm tra dự phòng']);
        Http::fake(['phase2-meili.test/*' => Http::response([], 503)]);
        $url = '/ui/marketplace-search?context=public&q='.urlencode('Mô tả duy nhất').'&min_price='.$listing->room->rent.'&max_price='.$listing->room->rent;
        $this->get($url)->assertOk()->assertHeader('X-TroHub-Search-Engine', 'sql-fallback')->assertSee($listing->title);
        $this->get($url.'&min_area=9999')->assertOk()->assertDontSee($listing->title);
    }

    public function test_index_write_happens_after_commit_and_never_for_rollback(): void
    {
        $listing = app(ListingService::class)->available()->firstOrFail();
        $levels = [];
        Http::fake(function ($request) use (&$levels) {
            $levels[] = DB::transactionLevel();

            return Http::response(['taskUid' => 71, 'status' => 'succeeded']);
        });
        DB::transaction(function () use ($listing) {
            $listing->update(['title' => 'Nội dung đã xác nhận']);
            Http::assertNothingSent();
        });
        $this->assertNotEmpty($levels);
        $this->assertSame([0], array_values(array_unique($levels)));
        Http::fake();
        try {
            DB::transaction(function () use ($listing) {
                $listing->update(['title' => 'Nội dung phải hủy']);
                throw new \RuntimeException('Rollback fixture');
            });
        } catch (\RuntimeException) {
        }
        Http::assertNothingSent();
        $this->assertSame('Nội dung đã xác nhận', $listing->fresh()->title);
    }

    public function test_amenity_filter_matches_vietnamese_catalog_labels_instead_of_only_internal_ids(): void
    {
        $listing = app(ListingService::class)->available()->firstOrFail();
        DB::table('rooms')->where('id', $listing->room_id)->update(['amenity_ids' => json_encode(['air_conditioner'])]);
        $listing->load('room.property');
        Http::fake(['phase2-meili.test/*' => Http::response(['hits' => [app(ListingSearchIndex::class)->document($listing)], 'estimatedTotalHits' => 1])]);
        $this->get('/ui/marketplace-search?context=public&amenity='.urlencode('Máy lạnh'))->assertOk()->assertHeader('X-TroHub-Search-Engine', 'meilisearch')->assertSee($listing->title);
        $this->get('/ui/marketplace-search?context=public&amenity='.urlencode('Thang máy'))->assertOk()->assertDontSee($listing->title);
    }
}
