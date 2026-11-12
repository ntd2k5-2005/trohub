<?php

namespace App\Modules\Rental\Contracts;

interface SettlementLedger
{
    public function assertFinalReadings(int $roomId, int $propertyId, string $period): void;
}
