<?php

namespace App\Modules\IoT;

use App\Modules\IoT\Models\Device;
use App\Modules\IoT\Models\SafetyEvent;
use App\Modules\Rental\Models\Rental;
use App\Support\ProductPresenter;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $this->iot($slug);
    }

    protected function iot(string $slug): void
    {
        $roomIds = $this->workspace ? $this->rooms(in_array($slug, ['iot-incidents', 'room-safety-history']) ? 'iot.log.view' : 'iot.view')->pluck('id')->all() : $this->scope->currentRoomIds($this->user);
        if ($this->request->filled('room_id')) {
            abort_unless(in_array($this->request->integer('room_id'), $roomIds, true), 403);
            $roomIds = [$this->request->integer('room_id')];
        }
        if ($this->can('iot.register')) {
            $this->vm['forms'][] = $this->form('Thêm thiết bị', 'iot.register', [$this->field('room_id', 'Phòng lắp đặt', 'select', $this->roomOptions('iot.register')), $this->field('name', 'Tên thiết bị'), $this->field('serial', 'Số sê-ri')]);
        }
        if (in_array($slug, ['iot-incidents', 'room-safety-history'])) {
            $events = SafetyEvent::with('room', 'device')->whereIn('room_id', $roomIds)->when(! $this->workspace, fn ($q) => $q->whereHas('device', fn ($d) => $d->where('kind', 'safety_sensor')));
            // Tenant history is bounded by their current occupancy, not earlier residents.
            if (! $this->workspace) {
                $events->where(function ($q) use ($roomIds) {
                    foreach ($roomIds as $rid) {
                        $r = Rental::where('room_id', $rid)->whereIn('status', ['active', 'move_out_pending'])->first();
                        $q->orWhere(fn ($b) => $b->where('room_id', $rid)->where('event_time', '>=', $r->tenant_id === $this->user->id ? $r->starts_at : ($r->occupants()->where('user_id', $this->user->id)->whereNull('ends_at')->value('starts_at') ?? now())));
                    }
                });
            }
            $this->records($events->orderByDesc('event_time'), function ($e) {
                $forms = [];
                if ($this->workspace && $this->access->allowed($this->user, 'maintenance.create', $this->workspace, $e->room->property_id, $e->room_id) && ! $e->maintenance_request_id) {
                    $forms[] = $this->form('Tạo yêu cầu kiểm tra', 'iot.maintenance', [], $e->id);
                } if (($this->workspace ? $this->access->allowed($this->user, $e->status === 'open' ? 'iot.ack' : 'iot.close', $this->workspace, $e->room->property_id, $e->room_id) : $this->access->personal($this->user, 'iot.ack')) && $e->status !== 'closed') {
                    $forms[] = $this->form($e->status === 'open' ? 'Đã tiếp nhận' : 'Đóng sự cố', 'iot.ack', [$this->field('status', 'Trạng thái', 'hidden', value: $e->status === 'open' ? 'acknowledged' : 'closed')], $e->id);
                }

                $reading = app(SafetyHistoryRepository::class)->reading($e);
                if ($reading['projected']) {
                    $this->request->attributes->set('history.engine', 'cassandra');
                }
                $facts = ['Mức cảnh báo' => self::label($e->risk), 'Thiết bị' => self::label($e->health), 'Lúc phát sinh' => $e->event_time->format('d/m/Y H:i:s'), 'Xử lý' => self::label($e->status)];
                foreach (config('business.sensor_readings', []) as $key => $definition) {
                    $value = data_get($reading['payload'], $key, data_get($reading['payload'], 'payload.'.$key));
                    if (is_numeric($value)) {
                        $facts[$definition['label']] = number_format((float) $value, (float) $value === (float) (int) $value ? 0 : 2, ',', '.').' '.$definition['unit'];
                    }
                }

                return $this->card($e->room->name.' · '.$e->device->name, $e->reason, $facts, $forms);
            });
        } else {
            $this->records(Device::when(! $this->workspace, fn ($q) => $q->where('kind', 'safety_sensor'))->with(['configs', 'assignments' => fn ($q) => $q->whereNull('effective_to')->with('room')])->whereHas('assignments', fn ($q) => $q->whereIn('room_id', $roomIds)->whereNull('effective_to')), function ($d) {
                $a = $d->assignments->first();
                $heartbeat = (int) ($d->configs->firstWhere('version', $d->config_version)?->settings['heartbeat_seconds'] ?? config('business.heartbeat_seconds'));
                $health = (! $d->last_seen_at || $d->last_seen_at->lt(now()->subSeconds($heartbeat * config('business.missed_heartbeats', 3)))) ? 'offline' : $d->health;
                $forms = [];
                if ($d->kind === 'safety_sensor' && ($this->workspace ? $this->access->allowed($this->user, 'iot.configure', $this->workspace, $a->room->property_id, $a->room_id) : $this->access->personal($this->user, 'iot.configure'))) {
                    $forms[] = $this->form('Cập nhật chu kỳ gửi trạng thái', 'iot.configure', [$this->field('heartbeat', 'Chu kỳ (giây)', 'number', value: $heartbeat)], $d->id);
                } if ($this->workspace && $this->access->allowed($this->user, 'iot.move', $this->workspace, $a->room->property_id, $a->room_id)) {
                    $forms[] = $this->form('Chuyển phòng lắp đặt', 'iot.move', [$this->field('room_id', 'Phòng mới', 'select', $this->roomOptions('iot.move'))], $d->id);
                }

                return $this->card($d->name, $a->room->name, ['Mức cảnh báo gần nhất' => self::label($d->risk), 'Sức khỏe thiết bị' => self::label($health), 'Lần nhận dữ liệu cuối' => $d->last_seen_at?->format('d/m/Y H:i:s') ?? 'Chưa nhận dữ liệu'], $forms);
            });
            if ($this->can('iot.log.view')) {
                $this->vm['links'][] = $this->link('Lịch sử cảnh báo', $this->workspace ? 'iot-incidents' : 'room-safety-history');
            }
            $this->vm['notice'] = 'Trạng thái hiển thị là dữ liệu gần nhất nhận được. Thiết bị mất kết nối hoặc lỗi cảm biến không có nghĩa phòng đang an toàn.';
        }
    }
}
