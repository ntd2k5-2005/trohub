<?php

namespace App\Modules\Listing;

use App\Modules\Property\HousingTypes;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

final class PublicListingSearch
{
    public function apply(Builder $listings, string $query, array $filters): string
    {
        $enabled = config('data_stores.meilisearch.enabled');
        if ($enabled) {
            try {
                $index = app(ListingSearchIndex::class);
                if ($index->dirty()) {
                    throw new RuntimeException('Search index is awaiting repair');
                }
                $conditions = [];
                foreach (['min_price' => ['rent', '>='], 'max_price' => ['rent', '<='], 'min_area' => ['area', '>='], 'capacity' => ['capacity', '>=']] as $key => [$field,$operator]) {
                    if (isset($filters[$key]) && $filters[$key] !== '') {
                        $conditions[] = $field.' '.$operator.' '.(float) $filters[$key];
                    }
                }
                if (! empty($filters['room_type'])) {
                    $conditions[] = 'room_type = '.json_encode(HousingTypes::normalize($filters['room_type']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                }
                $sort = match ($filters['sort'] ?? 'newest') {
                    'price_asc' => ['rent:asc'],'price_desc' => ['rent:desc'],default => ['published_at:desc']
                };
                $result = $index->search($query, ['filter' => $conditions, 'sort' => $sort, 'matchingStrategy' => 'all', 'limit' => (int) config('data_stores.meilisearch.max_candidates', 1000)]);
                if ($result['_candidate_limit_reached']) {
                    throw new RuntimeException('Candidate window exceeded; use complete SQL pagination');
                }
                $hits = $result['hits'];
                $current = app(ListingService::class)->available()->with('room.property')->whereIn('id', array_column($hits, 'id'))->get()->keyBy('id');
                foreach ($hits as $hit) {
                    if (! isset($current[$hit['id']]) || ! hash_equals($index->document($current[$hit['id']])['source_hash'], (string) ($hit['source_hash'] ?? ''))) {
                        $index->markDirty();
                        throw new RuntimeException('Stale search content');
                    }
                }
                $listings->whereIn('listings.id', array_column($hits, 'id'));

                return 'meilisearch';
            } catch (\Throwable) {
                // SQL retains the eligibility and structured filters applied by the presenter.
            }
        }
        $listings->where(fn ($b) => $b->where('title', 'like', '%'.$query.'%')->orWhere('description', 'like', '%'.$query.'%')->orWhereHas('room.property', fn ($p) => $p->where('district', 'like', '%'.$query.'%')->orWhere('public_address', 'like', '%'.$query.'%')->orWhere('name', 'like', '%'.$query.'%')));

        return $enabled ? 'sql-fallback' : 'sql';
    }
}
