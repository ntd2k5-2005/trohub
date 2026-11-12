<?php

use App\Modules\IoT\IoTService;
use App\Modules\IoT\Models\Device;
use App\Modules\IoT\Models\DeviceConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/iot/events', function (Request $r) {
    abort_unless(config('features.iot'), 404);
    $token = $r->bearerToken();
    abort_unless(is_string($token) && strlen($token) >= 32, 401);
    $device = Device::where('token_hash', hash('sha256', $token))->first();
    abort_unless($device, 401);
    $data = $r->validate(['event_id' => 'required|string|max:100', 'event_time' => 'required|date', 'schema_version' => 'required|integer|in:1', 'config_version' => 'required|integer|min:1', 'risk' => 'required|in:unknown,normal,watch,warning,danger,emergency', 'health' => 'required|in:healthy,sensor_fault,degraded,offline,ai_unavailable', 'reason' => 'required|string|max:2000', 'gas' => 'nullable|numeric|between:0,1', 'temperature' => 'nullable|numeric|between:-50,150']);
    $event = app(IoTService::class)->ingest($device, $data);

    return response()->json(['accepted' => true, 'event_id' => $event->event_id]);
})->middleware('throttle:600,1');

Route::get('/iot/config', function (Request $r) {
    abort_unless(config('features.iot'), 404);
    $token = $r->bearerToken();
    abort_unless(is_string($token) && strlen($token) >= 32, 401);
    $device = Device::where('token_hash', hash('sha256', $token))->first();
    abort_unless($device, 401);
    $config = DeviceConfig::where('device_id', $device->id)->orderByDesc('version')->firstOrFail();
    app(\App\Modules\Subscription\PropertyCloud::class)->requireActive($device->workspace_id);

    return response()->json(['config_version' => $config->version, 'settings' => $config->settings]);
})->middleware('throttle:60,1');
