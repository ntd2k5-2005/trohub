<?php

namespace Tests\Feature;

use App\Modules\Billing\BillingService;
use App\Modules\Billing\Models\ServiceRate;
use App\Modules\Rental\Models\Rental;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingPeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_meter_rate_effective_on_first_day_is_available_in_that_billing_period(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rental = Rental::where('status', 'active')->firstOrFail();
        $room = $rental->room;
        $owner = $room->property->workspace->owner;
        $service = app(BillingService::class);
        $service->createRate($owner, ['property_id' => $room->property_id, 'name' => 'Điện đầu kỳ', 'kind' => 'electricity', 'rate' => 4000, 'effective_from' => '2030-01-01']);
        $rate = ServiceRate::where('property_id', $room->property_id)->where('name', 'Điện đầu kỳ')->firstOrFail();

        $response = $this->actingAs($owner)->post('/actions/meter.create', ['context' => 'workspace', 'workspace_id' => $room->property->workspace_id, 'room_id' => $room->id, 'service_rate_id' => $rate->id, 'period' => '2030-01', 'current' => 50]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('meter_readings', ['room_id' => $room->id, 'service_rate_id' => $rate->id, 'period' => '2030-01', 'usage' => 50]);
    }

    public function test_invoice_includes_fixed_rate_effective_on_first_day_and_excludes_next_day(): void
    {
        $this->seed(DatabaseSeeder::class);
        $rental = Rental::where('status', 'active')->firstOrFail();
        $room = $rental->room;
        $owner = $room->property->workspace->owner;
        $service = app(BillingService::class);
        foreach (ServiceRate::where('property_id', $room->property_id)->whereIn('kind', ['electricity', 'water'])->get() as $rate) {
            $service->reading($owner, ['room_id' => $room->id, 'service_rate_id' => $rate->id, 'period' => '2030-01', 'current' => 1000]);
        }
        $service->createRate($owner, ['property_id' => $room->property_id, 'name' => 'Internet đầu kỳ', 'kind' => 'fixed', 'rate' => 100000, 'effective_from' => '2030-01-01']);
        $service->createRate($owner, ['property_id' => $room->property_id, 'name' => 'Internet đầu kỳ', 'kind' => 'fixed', 'rate' => 200000, 'effective_from' => '2030-01-02']);

        $invoice = $service->generate($owner, $rental, '2030-01', '2030-01-10');

        $this->assertSame(100000, $invoice->items()->where('description', 'Internet đầu kỳ')->firstOrFail()->amount);
    }
}
