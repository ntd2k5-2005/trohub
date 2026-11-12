<?php

namespace App\Modules\Billing;

use App\Modules\Billing\Models\MeterReading;
use App\Modules\Billing\Models\ServiceRate;
use Illuminate\Validation\ValidationException;

final class SettlementLedger implements \App\Modules\Rental\Contracts\SettlementLedger
{
    public function assertFinalReadings(int $roomId, int $propertyId, string $period): void
    {
        $rates = ServiceRate::where('property_id', $propertyId)->where('effective_from', '<=', $period.'-01')->orderByDesc('effective_from')->get()->unique('name')->whereIn('kind', ['electricity', 'water']);
        foreach ($rates as $rate) {
            if (! MeterReading::where('room_id', $roomId)->where('service_rate_id', $rate->id)->where('period', $period)->exists()) {
                throw ValidationException::withMessages(['rental' => 'Cần chốt chỉ số cuối kỳ cho '.$rate->name.' trước khi trả phòng.']);
            }
        }
    }
}
