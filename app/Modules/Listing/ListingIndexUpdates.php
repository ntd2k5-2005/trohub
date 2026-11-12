<?php

namespace App\Modules\Listing;

use App\Modules\Listing\Models\Listing;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;

/** After-commit index maintenance independent from the other NoSQL projections. */
final class ListingIndexUpdates
{
    public static function register(): void
    {
        Listing::saved(fn ($model) => self::schedule([$model->id]));
        Listing::deleted(fn ($model) => self::schedule([$model->id]));
        Room::saved(function ($model) {
            if (config('data_stores.meilisearch.enabled')) {
                self::schedule($model->listings()->pluck('id')->all());
            }
        });
        Property::saved(function ($model) {
            if (config('data_stores.meilisearch.enabled')) {
                self::schedule(Listing::whereHas('room', fn ($r) => $r->where('property_id', $model->id))->pluck('id')->all());
            }
        });
        Workspace::saved(function ($model) {
            if (config('data_stores.meilisearch.enabled')) {
                self::schedule(Listing::whereHas('room.property', fn ($p) => $p->where('workspace_id', $model->id))->pluck('id')->all());
            }
        });
    }

    private static function schedule(array $ids): void
    {
        if (! config('data_stores.meilisearch.enabled') || ! $ids) {
            return;
        }
        DB::afterCommit(fn () => app(ListingSearchIndex::class)->sync($ids));
    }
}
