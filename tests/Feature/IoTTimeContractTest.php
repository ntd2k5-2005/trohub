<?php

namespace Tests\Feature;

use App\Modules\IoT\IoTService;
use App\Modules\IoT\Models\Device;
use App\Modules\IoT\Models\SafetyEvent;
use App\Modules\Rental\Models\Rental;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IoTTimeContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_offset_timestamps_use_the_same_assignment_and_local_storage_time(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now()->startOfSecond());
        $rental = Rental::where('status', 'active')->firstOrFail();
        $token = app(IoTService::class)->register($rental->room->property->workspace->owner, $rental->room_id, 'UTC contract', 'UTC-CONTRACT');
        $device = Device::where('serial', 'UTC-CONTRACT')->firstOrFail();
        foreach (['UTC', '+02:00'] as $index => $timezone) {
            $at = now()->addSeconds($index);
            $data = ['event_id' => 'OFFSET-'.$index, 'event_time' => $at->copy()->setTimezone($timezone)->toIso8601String(), 'schema_version' => 1, 'config_version' => 1, 'risk' => 'normal', 'health' => 'healthy', 'reason' => 'Timezone contract'];
            // Hardware clients may not send an Accept header; API failures must still be JSON.
            $this->post('/api/iot/events', $data, ['Authorization' => 'Bearer '.$token])->assertOk()->assertJson(['accepted' => true]);
            $event = SafetyEvent::where('event_id', $data['event_id'])->firstOrFail();
            $this->assertSame($rental->room_id, $event->room_id);
            $this->assertSame($at->format('Y-m-d H:i:s'), $event->getRawOriginal('event_time'));
            $this->assertTrue($device->fresh()->last_seen_at->equalTo($at));
            $this->post('/api/iot/events', $data, ['Authorization' => 'Bearer '.$token])->assertOk();
        }
        $this->assertSame(2, SafetyEvent::where('device_id', $device->id)->count());
        $this->post('/api/iot/events', array_replace($data, ['event_id' => 'FUTURE', 'event_time' => now()->addMinutes(2)->utc()->toIso8601String()]), ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()->assertJsonValidationErrors('event_time');
        $this->post('/api/iot/events', [], ['Authorization' => 'Bearer '.$token])->assertUnprocessable()->assertJsonValidationErrors('event_id');
        $this->post('/api/iot/events', [])->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    }
}
