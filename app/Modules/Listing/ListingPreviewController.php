<?php

namespace App\Modules\Listing;

use App\Http\Controllers\UiController;
use App\Http\Middleware\ResolveUiContext;
use App\Modules\Listing\Models\Listing;
use App\Support\ScopedRepository;
use Illuminate\Http\Request;

final class ListingPreviewController
{
    public function show(Request $request, Listing $listing)
    {
        app(ScopedRepository::class)->managedRoom($request->user(), $listing->room_id, 'listing.view');
        $request->merge(['context' => 'public', 'id' => $listing->id]);
        $request->attributes->set('listing.preview', $listing->id);

        return app(ResolveUiContext::class)->handle($request, fn ($r) => app(UiController::class)->show($r, 'listing-detail'));
    }
}
