<?php

namespace App\Modules\Listing;

use App\Modules\Listing\Models\Listing;
use App\Modules\Property\HousingAmenities;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Optional index: SQL remains authoritative for eligibility and current values. */
final class ListingSearchIndex
{
    private function path(): string
    {
        $index = config('data_stores.meilisearch.index');
        if (! is_string($index) || ! preg_match('/^[A-Za-z0-9_-]+$/', $index)) {
            throw new RuntimeException('Invalid search index');
        }

        return '/indexes/'.$index;
    }

    private function request()
    {
        if (! config('data_stores.meilisearch.enabled')) {
            throw new RuntimeException('Meilisearch unavailable');
        }
        $request = Http::baseUrl(rtrim(config('data_stores.meilisearch.host'), '/'))->connectTimeout(1)->timeout(config('data_stores.meilisearch.timeout_seconds', 3));
        if ($key = config('data_stores.meilisearch.key')) {
            $request = $request->withToken($key);
        }

        return $request;
    }

    private function state()
    {
        return Cache::store(config('data_stores.meilisearch.state_store', 'file'));
    }

    private function stateKey(): string
    {
        return 'listing-search:'.hash('sha256', config('data_stores.meilisearch.host').'|'.config('data_stores.meilisearch.index'));
    }

    public function dirty(): bool
    {
        return (bool) $this->state()->get($this->stateKey().':dirty');
    }

    public function markDirty(): string
    {
        $token = (string) Str::uuid();
        $this->state()->forever($this->stateKey().':dirty', $token);

        return $token;
    }

    public function configure(): int
    {
        return $this->request()->patch($this->path().'/settings', [
            'searchableAttributes' => ['title', 'description', 'district', 'address', 'amenities'],
            'filterableAttributes' => ['district', 'rent', 'area', 'room_type', 'capacity', 'amenities'],
            'sortableAttributes' => ['rent', 'area', 'published_at'],
            'pagination' => ['maxTotalHits' => (int) config('data_stores.meilisearch.max_candidates', 1000)],
        ])->throw()->json('taskUid');
    }

    public function document(Listing $listing): array
    {
        $room = $listing->room;
        $document = ['id' => $listing->id, 'title' => $listing->title, 'description' => $listing->description,
            'district' => $room->property->district, 'address' => $room->property->public_address,
            'amenities' => HousingAmenities::labels($room, 'room'), 'rent' => (int) $room->rent,
            'area' => (float) $room->area, 'capacity' => (int) $room->capacity, 'room_type' => $room->room_type,
            'published_at' => $listing->published_at ? CarbonImmutable::parse($listing->published_at)->timestamp : 0];

        return $document + ['source_hash' => hash('sha256', json_encode($document, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR))];
    }

    public function import(array $ids): int
    {
        $documents = app(ListingService::class)->available()->whereIn('id', $ids)->with('room.property')->get()->map(fn ($l) => $this->document($l))->all();

        return $this->request()->post($this->path().'/documents?primaryKey=id', $documents)->throw()->json('taskUid');
    }

    public function task(int $id): array
    {
        return $this->request()->get('/tasks/'.$id)->throw()->json();
    }

    public function wait(int $id): void
    {
        $deadline = microtime(true) + config('data_stores.meilisearch.task_wait_seconds', 5);
        do {
            $task = $this->task($id);
            if (($task['status'] ?? null) === 'succeeded') {
                return;
            }
            if (in_array($task['status'] ?? null, ['failed', 'canceled'], true)) {
                throw new RuntimeException('Search task failed');
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Search task timed out');
    }

    public function sync(array $ids): void
    {
        if (! config('data_stores.meilisearch.enabled') || ! $ids) {
            return;
        }
        try {
            $this->state()->lock($this->stateKey().':lock', 30)->block(5, function () use ($ids) {
                $visible = app(ListingService::class)->available()->whereIn('id', $ids)->pluck('id')->all();
                if ($visible) {
                    $this->wait($this->import($visible));
                }
                $removed = array_values(array_diff($ids, $visible));
                if ($removed) {
                    $this->wait($this->request()->post($this->path().'/documents/delete-batch', $removed)->throw()->json('taskUid'));
                }
            });
        } catch (\Throwable) {
            // The business transaction has committed; the optional index must not misreport its result.
            try {
                $this->markDirty();
            } catch (\Throwable) { /* Search also falls back if its state store is unavailable. */
            }
        }
    }

    public function rebuild(): int
    {
        return $this->state()->lock($this->stateKey().':lock', 120)->block(5, function () {
            $token = $this->markDirty();
            $response = $this->request()->get($this->path());
            if ($response->status() === 404) {
                $this->wait($this->request()->post('/indexes', ['uid' => config('data_stores.meilisearch.index'), 'primaryKey' => 'id'])->throw()->json('taskUid'));
            } else {
                $response->throw();
            }
            $this->wait($this->configure());
            $this->wait($this->request()->delete($this->path().'/documents')->throw()->json('taskUid'));
            $count = 0;
            app(ListingService::class)->available()->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
                $this->wait($this->import($rows->pluck('id')->all()));
                $count += $rows->count();
            });
            if ($this->state()->get($this->stateKey().':dirty') === $token) {
                $this->state()->forget($this->stateKey().':dirty');
            }

            return $count;
        });
    }

    public function search(string $query, array $options = []): array
    {
        $result = $this->request()->post($this->path().'/search', ['q' => $query] + array_intersect_key($options, array_flip(['filter', 'sort', 'limit', 'offset', 'matchingStrategy'])))->throw()->json();
        $ids = array_column($result['hits'] ?? [], 'id');
        $allowed = app(ListingService::class)->available()->whereIn('id', $ids)->pluck('id')->all();
        $result['hits'] = array_values(array_filter($result['hits'] ?? [], fn ($hit) => in_array((int) $hit['id'], $allowed, true)));
        $result['_candidate_limit_reached'] = count($ids) >= (int) config('data_stores.meilisearch.max_candidates', 1000);
        unset($result['estimatedTotalHits'],$result['totalHits']);

        return $result;
    }
}
