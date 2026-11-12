<?php

namespace App\Modules\Maintenance;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Rental\Models\Rental;
use App\Support\ScopedRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MaintenanceService
{
    public function __construct(private ScopedRepository $scope, private Journal $journal) {}

    public function comment(User $user, MaintenanceRequest $issue, string $note): void
    {
        app(\App\Modules\Subscription\PropertyCloud::class)->requireRoom($issue->room_id);
        if ($issue->reporter_id !== $user->id || ! app(Authorization::class)->personal($user, 'maintenance.comment')) {
            $this->scope->managedRoom($user, $issue->room_id, 'maintenance.comment');
        }
        DB::transaction(function () use ($user, $issue, $note) {
            $issue = MaintenanceRequest::whereKey($issue->id)->lockForUpdate()->firstOrFail();
            if ($issue->status === 'closed') {
                throw ValidationException::withMessages(['note' => 'Yêu cầu đã kết thúc. Hãy tạo yêu cầu mới nếu cần hỗ trợ.']);
            }
            $this->journal->record($user->id, 'maintenance.commented', $issue, $issue->room->property->workspace_id, ['note' => $note]);
            $recipient = $user->id === $issue->reporter_id ? $issue->room->property->workspace->owner_id : $issue->reporter_id;
            $this->journal->notify($recipient, 'Yêu cầu sửa chữa có thông tin mới', $note, workspace: $issue->room->property->workspace_id);
        });
    }

    public function create(User $user, array $data): MaintenanceRequest
    {
        $personal = app(Authorization::class)->personal($user, 'maintenance.create') && in_array((int) $data['room_id'], $this->scope->currentRoomIds($user), true);
        if (! $personal) {
            $this->scope->managedRoom($user, (int) $data['room_id'], 'maintenance.create');
        }

        return DB::transaction(function () use ($user, $data) {
            $rental = Rental::where('room_id', $data['room_id'])->whereIn('status', ['active', 'move_out_pending'])->first();
            $issue = MaintenanceRequest::create($data + ['reporter_id' => $user->id, 'rental_id' => $rental?->id]);
            $workspace = $issue->room->property->workspace;
            $this->journal->record($user->id, 'maintenance.created', $issue, $workspace->id);
            $this->journal->notify($workspace->owner_id, 'Yêu cầu sửa chữa mới', $issue->title, workspace: $workspace->id);

            return $issue;
        });
    }

    public function transition(User $user, MaintenanceRequest $issue, string $status, string $note): void
    {
        $room = $this->scope->managedRoom($user, $issue->room_id, match ($status) {
            'acknowledged' => 'maintenance.receive', 'closed' => 'maintenance.close', default => 'maintenance.process'
        });
        app(\App\Modules\Subscription\PropertyCloud::class)->managedTransaction($user, $room->id, match ($status) {
            'acknowledged' => 'maintenance.receive', 'closed' => 'maintenance.close', default => 'maintenance.process'
        }, function () use ($user, $issue, $status, $note, $room) {
            $issue = MaintenanceRequest::whereKey($issue->id)->lockForUpdate()->firstOrFail();
            $next = ['new' => 'acknowledged', 'acknowledged' => 'in_progress', 'in_progress' => 'resolved', 'resolved' => 'closed'];
            if (($next[$issue->status] ?? null) !== $status) {
                throw ValidationException::withMessages(['status' => 'Bước xử lý sự cố không hợp lệ.']);
            }
            $issue->update(['status' => $status]);
            if ($status === 'closed') app(\App\Contracts\WorkAssignments::class)->complete($room->property->workspace_id, 'maintenance_request', $issue->id);
            elseif (app(Authorization::class)->allowed($user, 'maintenance.process', $room->property->workspace, $room->property_id, $room->id)) app(\App\Contracts\WorkAssignments::class)->claim($user, $room->property->workspace_id, 'maintenance_request', $issue->id, 'maintenance.process');
            $this->journal->record($user->id, 'maintenance.updated', $issue, $room->property->workspace_id, ['status' => $status, 'note' => $note]);
            $this->journal->notify($issue->reporter_id, 'Yêu cầu sửa chữa được cập nhật', $note, workspace: $room->property->workspace_id);
        });
    }
}
