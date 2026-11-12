<?php

namespace App\Modules\Marketplace;

use App\Modules\DataPlatform\Contracts\HotCache;
use App\Modules\Listing\Models\Listing;

/** Content only: the caller still selects eligible listings from SQL each time. */
final class PublicListingText
{
    public function read(Listing $listing): array
    {
        $source = fn () => ['title' => $listing->title, 'description' => $listing->description];
        return app(HotCache::class)->remember($this->key($listing), 60, $source);
    }

    public function key(Listing $listing): string
    {
        return 'public-listing:'.$listing->id.':'.hash('sha256', $listing->title.'|'.$listing->description);
    }
}
