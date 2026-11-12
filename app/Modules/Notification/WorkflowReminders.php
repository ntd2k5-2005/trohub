<?php

namespace App\Modules\Notification;

use App\Modules\Connection\Models\Connection;
use App\Modules\Notification\Models\UserNotification;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\Models\RoomReservation;
use Illuminate\Support\Carbon;

final class WorkflowReminders
{
    public function run(): void
    {
        $notifications = app(NotificationService::class);
        UserNotification::whereNull('read_at')->where(function ($q) {
            $q->where('dedupe_key', 'like', 'viewing:%')->orWhere('dedupe_key', 'like', 'hold-due:%')->orWhere('dedupe_key', 'like', 'rental-end:%');
        })->chunkById(100, function ($items) use ($notifications) {
            foreach ($items as $notice) {
                [$type, $id] = explode(':', $notice->dedupe_key, 3);
                $current = match ($type) {
                    'viewing' => Connection::find($id),
                    'hold-due' => RoomReservation::find($id),
                    'rental-end' => Rental::find($id),
                    default => null,
                };
                $expected = $current ? match ($type) {
                    'viewing' => $current->status === 'viewing_confirmed' && Carbon::parse($current->viewing_at)->isFuture() ? 'viewing:'.$id.':'.$current->viewing_at.':'.$notice->user_id : null,
                    'hold-due' => $current->active_room_id && in_array($current->status, ['holding', 'deposit_confirmed'], true) && $current->hold_until->isFuture() ? 'hold-due:'.$id.':'.$current->hold_until->timestamp : null,
                    'rental-end' => $current->status === 'active' && $current->ends_at >= today()->toDateString() ? 'rental-end:'.$id.':'.$current->ends_at.':'.$notice->user_id : null,
                } : null;
                if ($expected !== explode('@cloud:', $notice->dedupe_key, 2)[0]) {
                    $notifications->retire($notice->user_id, $notice->dedupe_key);
                }
            }
        });
        Connection::with('listing.room.property.workspace')->where('status', 'viewing_confirmed')->whereBetween('viewing_at', [now(), now()->addHours((int) config('reminders.viewing_hours'))])->chunkById(100, function ($items) use ($notifications) {
            foreach ($items as $item) {
                $workspace = $item->listing->room->property->workspace;
                if (! app(\App\Modules\Subscription\PropertyCloud::class)->currentOperation($workspace, $item->updated_at, $item->cloud_epoch)) continue;
                foreach ([$item->user_id => 'personal', $workspace->owner_id => 'workspace'] as $user => $context) {
                    $notifications->linked($user, 'viewing:'.$item->id.':'.$item->viewing_at.':'.$user, 'Lịch xem phòng sắp tới', 'Bạn có lịch xem '.$item->listing->room->name.' lúc '.Carbon::parse($item->viewing_at)->format('H:i d/m/Y'), route('ui.show', ['slug' => $context === 'workspace' ? 'workspace-leads' : 'connections', 'context' => $context] + ($context === 'workspace' ? ['workspace_id' => $workspace->id] : []), false), workspace: $workspace->id);
                }
            }
        });
        Rental::with('room.property.workspace')->where('status', 'active')->whereBetween('ends_at', [today()->toDateString(), today()->addDays((int) config('reminders.contract_days'))->toDateString()])->chunkById(100, function ($items) use ($notifications) {
            foreach ($items as $item) {
                if (! app(\App\Modules\Subscription\PropertyCloud::class)->currentOperation($item->room->property->workspace, $item->created_at, $item->cloud_epoch)) continue;
                foreach ([$item->tenant_id => 'personal', $item->room->property->workspace->owner_id => 'workspace'] as $user => $context) {
                    if (! $user) {
                        continue;
                    }
                    $notifications->linked($user, 'rental-end:'.$item->id.':'.$item->ends_at.':'.$user, 'Hợp đồng sắp hết hạn', 'Kiểm tra thời hạn thuê và trao đổi kế hoạch gia hạn hoặc trả phòng.', route('ui.show', ['slug' => 'rental-detail', 'id' => $item->id, 'context' => $context] + ($context === 'workspace' ? ['workspace_id' => $item->room->property->workspace_id] : []), false), workspace: $item->room->property->workspace_id);
                }
            }
        });
        RoomReservation::with('request', 'room.property')->whereNotNull('active_room_id')->whereIn('status', ['holding', 'deposit_confirmed'])->whereBetween('hold_until', [now(), now()->addHours((int) config('reminders.reservation_hours'))])->chunkById(100, function ($items) use ($notifications) {
            foreach ($items as $item) {
                if (! app(\App\Modules\Subscription\PropertyCloud::class)->currentOperation($item->room->property->workspace, $item->created_at, $item->cloud_epoch)) continue;
                $notifications->linked($item->request->user_id, 'hold-due:'.$item->id.':'.$item->hold_until->timestamp, 'Sắp hết thời hạn giữ phòng', 'Kiểm tra tiền cọc, hợp đồng và thời hạn giữ phòng trước '.$item->hold_until->format('H:i d/m/Y').'.', route('ui.show', ['slug' => 'rentals', 'context' => 'personal'], false), workspace: $item->room->property->workspace_id);
            }
        });
    }
}
