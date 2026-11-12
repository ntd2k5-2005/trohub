<?php

namespace App\Modules\IoT;

use App\Contracts\Authorization;
use App\Contracts\Authorization as AccessService;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\IoT\Models\Device;
use App\Modules\IoT\Models\DeviceAssignment;
use App\Modules\IoT\Models\DeviceConfig;
use App\Modules\IoT\Models\SafetyEvent;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Models\Rental;
use App\Modules\Subscription\PropertyCloud;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Support\ScopedRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class IoTService
{
    public function __construct(private ScopedRepository $scope, private Journal $journal) {}

    private function room(User $user, int $roomId, string $permission): Room
    {
        $access = app(Authorization::class);
        if ($access->personal($user, $permission) && in_array($roomId, $this->scope->currentRoomIds($user), true)) {
            return Room::with('property')->findOrFail($roomId);
        }

        return $this->scope->managedRoom($user, $roomId, $permission);
    }

    public function register(User $user, int $roomId, string $name, string $serial): string
    {
        $room = $this->scope->managedRoom($user, $roomId, 'iot.register');

        return DB::transaction(function () use ($user, $room, $name, $serial) {
            $token = Str::random(64);
            $device = Device::create(['workspace_id' => $room->property->workspace_id, 'name' => $name, 'serial' => $serial, 'token_hash' => hash('sha256', $token)]);
            DeviceAssignment::create(['device_id' => $device->id, 'room_id' => $room->id, 'effective_from' => now()]);
            DeviceConfig::create(['device_id' => $device->id, 'version' => 1, 'effective_from' => now(), 'settings' => ['local_safety' => true, 'heartbeat_seconds' => config('business.heartbeat_seconds')]]);
            $this->journal->record($user->id, 'device.registered', $device, $device->workspace_id);

            return $token;
        });
    }

    public function move(User $user, Device $device, int $roomId): void
    {
        $target = $this->scope->managedRoom($user, $roomId, 'iot.move');
        abort_unless($target->property->workspace_id === $device->workspace_id, 403);
        DB::transaction(function () use ($user, $device, $target) {
            Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $old = $device->assignments()->whereNull('effective_to')->firstOrFail();
            $this->scope->managedRoom($user, $old->room_id, 'iot.move');
            $at = now();
            $old->update(['effective_to' => $at]);
            DeviceAssignment::create(['device_id' => $device->id, 'room_id' => $target->id, 'effective_from' => $at]);
            $device->update(['risk' => 'unknown', 'health' => 'unknown', 'last_seen_at' => null, 'last_event_at' => null]);
            $this->journal->record($user->id, 'device.moved', $device, $device->workspace_id, ['room_id' => $target->id]);
        });
    }

    public function ingest(Device $device, array $data): SafetyEvent
    {
        return app(PropertyCloud::class)->transaction($device->workspace_id, function () use ($device, $data) {
            $device = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $duplicate = SafetyEvent::where('device_id', $device->id)->where('event_id', $data['event_id'])->first();
            if ($duplicate) {
                return $duplicate;
            }
            // SQL datetime columns use the application timezone. Normalize offset
            // telemetry before comparing assignments or writing device freshness.
            $at = Carbon::parse($data['event_time'])->setTimezone(config('app.timezone'));
            abort_unless(app(PropertyCloud::class)->currentOperation(Workspace::findOrFail($device->workspace_id), $at), 409, 'Sự kiện thuộc kỳ dịch vụ trước; không phát lại cảnh báo cũ.');
            if ($at->isAfter(now()->addSeconds(30))) {
                throw ValidationException::withMessages(['event_time' => 'Thời điểm sự kiện không hợp lệ.']);
            }
            $assignment = $device->assignments()->where('effective_from', '<=', $at)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at))->first();
            $config = DeviceConfig::where('device_id', $device->id)->where('effective_from', '<=', $at)->where('version', $data['config_version'])->first();
            if (! $assignment || ! $config || $config->version !== (int) $data['config_version']) {
                throw ValidationException::withMessages(['device' => 'Thiết bị chưa được gán phòng hoặc phiên bản cấu hình không phù hợp thời điểm sự kiện.']);
            }
            if ($data['health'] !== 'healthy' && $data['risk'] === 'normal') {
                $data['risk'] = 'unknown';
            }
            $event = SafetyEvent::create(['device_id' => $device->id, 'room_id' => $assignment->room_id, 'event_id' => $data['event_id'], 'event_time' => $at, 'ingest_time' => now(),
                'config_version' => $data['config_version'], 'risk' => $data['risk'], 'health' => $data['health'], 'reason' => $data['reason'], 'payload' => $data]);
            // Historical/replayed telemetry never overwrites current room state.
            if (! $assignment->effective_to && (! $device->last_event_at || $at->greaterThan($device->last_event_at))) {
                $device->update(['risk' => $data['risk'], 'health' => $data['health'], 'last_event_at' => $at, 'last_seen_at' => $at]);
            }
            $this->journal->record(null, 'iot.received', $event, $device->workspace_id);
            if (in_array($event->risk, ['warning', 'danger', 'emergency'], true)) {
                $room = $assignment->room;
                $recipients = [$room->property->workspace->owner_id];
                $active = Rental::where('room_id', $room->id)->whereIn('status', ['active', 'move_out_pending'])->first();
                if ($active && $active->starts_at <= $at->toDateString()) {
                    $recipients = array_merge($recipients, [$active->tenant_id], $active->occupants()->where('starts_at', '<=', $at)->whereNull('ends_at')->pluck('user_id')->all());
                }
                $access = app(AccessService::class);
                foreach (Membership::with('user')->where('workspace_id', $device->workspace_id)->where('status', 'active')->get() as $member) {
                    if ($access->allowed($member->user, 'iot.view', $room->property->workspace, $room->property_id, $room->id)) {
                        $recipients[] = $member->user_id;
                    }
                }
                foreach (array_unique($recipients) as $recipient) {
                    $this->journal->notify($recipient, 'Cảnh báo an toàn tại '.$room->name, $event->reason, 2, workspace: $room->property->workspace_id);
                }
            }

            return $event;
        });
    }

    public function acknowledge(User $user, SafetyEvent $event, string $status): void
    {
        $room = $this->room($user, $event->room_id, $status === 'closed' ? 'iot.close' : 'iot.ack');
        abort_unless($event->device->kind === 'safety_sensor', 403);
        DB::transaction(function () use ($user, $event, $status, $room) {
            $event = SafetyEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if (($event->status === 'open' && $status !== 'acknowledged') || ($event->status === 'acknowledged' && $status !== 'closed') || $event->status === 'closed') {
                throw ValidationException::withMessages(['status' => 'Trạng thái cảnh báo không hợp lệ.']);
            }
            if ($status === 'closed' && $event->device->assignments()->where('room_id', $event->room_id)->whereNull('effective_to')->exists() && in_array($event->device->risk, ['danger', 'emergency'], true)) {
                throw ValidationException::withMessages(['status' => 'Thiết bị vẫn báo nguy hiểm, chưa thể đóng sự cố.']);
            }
            $event->update(['status' => $status]);
            $this->journal->record($user->id, 'iot.'.$status, $event, $room->property->workspace_id);
        });
    }

    public function configure(User $user, Device $device, int $heartbeat): void
    {
        $assignment = $device->assignments()->whereNull('effective_to')->firstOrFail();
        $this->room($user, $assignment->room_id, 'iot.configure');
        if ($device->kind !== 'safety_sensor' || $heartbeat < 10 || $heartbeat > 300) {
            throw ValidationException::withMessages(['heartbeat' => 'Chu kỳ gửi trạng thái phải từ 10 đến 300 giây.']);
        }
        DB::transaction(function () use ($user, $device, $heartbeat) {
            $device = Device::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $assignment = $device->assignments()->whereNull('effective_to')->firstOrFail();
            $this->room($user, $assignment->room_id, 'iot.configure');
            $version = $device->config_version + 1;
            DeviceConfig::create(['device_id' => $device->id, 'version' => $version, 'effective_from' => now(), 'settings' => ['local_safety' => true, 'heartbeat_seconds' => $heartbeat]]);
            $device->update(['config_version' => $version]);
            $this->journal->record($user->id, 'device.configured', $device, $device->workspace_id, ['version' => $version]);
        });
    }

    public function maintenance(User $user, SafetyEvent $event): void
    {
        $room = $this->scope->managedRoom($user, $event->room_id, 'maintenance.create');
        DB::transaction(function () use ($user, $event, $room) {
            $event = SafetyEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($event->maintenance_request_id) {
                return;
            }
            $issue = MaintenanceRequest::create(['room_id' => $room->id, 'reporter_id' => $user->id, 'title' => 'Kiểm tra an toàn: '.$event->device->name, 'description' => $event->reason]);
            $event->update(['maintenance_request_id' => $issue->id]);
            $this->journal->record($user->id, 'iot.maintenance_requested', $event, $room->property->workspace_id, ['maintenance_id' => $issue->id]);
        });
    }
}
