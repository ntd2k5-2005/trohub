<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ScreenPresenter
{
    public function present(Request $request, string $slug): array
    {
        $request->validate(['item'=>'nullable|string|max:128|regex:/^[a-z0-9:_-]+$/']);
        $module = match ($slug) {
            'marketplace-home','marketplace-search','listing-detail','favorites','compare-listings' => 'Marketplace',
            'properties','room-detail' => 'Property', 'workspace-listings' => 'Listing',
            'conversations' => 'Chat', 'connections','workspace-leads' => 'Connection', 'rentals','rental-detail' => 'Rental',
            'billing','my-invoices','payments','utilities' => 'Billing', 'maintenance' => 'Maintenance',
            'iot-devices','iot-incidents','room-safety','room-safety-history' => 'IoT',
            'landlord-upgrade','subscription' => 'Subscription', 'workspace-dashboard','team-permissions','workspace-settings' => 'Workspace',
            'notifications' => 'Notification', 'my-reports','admin-reports','moderation','moderation-history','admin-accounts','access-control' => 'Admin',
            'roommate-discovery' => 'Roommate', 'data-platform' => 'DataPlatform',
            'analytics-overview','analytics-funnel','analytics-forecast','analytics-lineage' => 'Analytics',
            default => 'Identity'
        };

        return app(RecordPresentation::class)->present(app('App\\Modules\\'.$module.'\\Presenter')->present($request, $slug),$request,$slug);
    }
}
