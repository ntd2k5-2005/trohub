<?php
namespace App\Modules\DataPlatform;
use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Models\AuditEntry;
use App\Modules\Property\Models\{Property,Room};
final class AuditResourceScope
{
    public function visible(User $actor,AuditEntry $entry):bool
    {
        $models=[Property::class,Room::class,\App\Modules\Rental\Models\Rental::class,\App\Modules\Billing\Models\Invoice::class,\App\Modules\Billing\Models\PaymentRequest::class,\App\Modules\Maintenance\Models\MaintenanceRequest::class,\App\Modules\AccessControl\Models\DirectGrant::class];
        if(!in_array($entry->resource_type,$models,true))return true;
        $resource=$entry->resource_type::find($entry->resource_id);if(!$resource)return false;
        $room=$resource instanceof Room?$resource:($resource->room??$resource->rental?->room);
        $property=$resource instanceof Property?$resource:($room?->property??($resource->property_id?Property::find($resource->property_id):null));
        return !$property||app(Authorization::class)->allowed($actor,'property.view',$property->workspace,$property->id,$room?->id);
    }
}
