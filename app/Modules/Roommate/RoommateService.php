<?php

namespace App\Modules\Roommate;

use App\Contracts\Authorization;
use App\Contracts\Authorization as AccessService;
use App\Models\User;
use App\Modules\AccessControl\ActionAuthorizer;
use App\Modules\DataPlatform\Journal;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Models\Rental;
use App\Modules\Roommate\Models\RoommateNeed;
use App\Modules\Roommate\Models\RoommateRequest;
use App\Support\ScopedRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RoommateService
{
    public function execute(User $u, string $action, int $id, array $data): void
    {
        abort_unless(app(Authorization::class)->personal($u, app(ActionAuthorizer::class)->permission($action, ['id' => $id])), 403);
        $access = app(AccessService::class);
        $journal = app(Journal::class);
        DB::transaction(function () use ($action, $u, $id, $data, $journal) {
            $before = [];
            switch ($action) {
                case 'roommate.save':
                    abort_unless(config('features.roommate'), 404);
                    $record = $id ? RoommateNeed::cloudAccessible()->where('user_id', $u->id)->findOrFail($id) : new RoommateNeed(['user_id' => $u->id]);
                    $mode = $data['mode'] ?? $record->mode ?? 'search_together';
                    if ($mode === 'has_room') {
                        $roomId = (int) ($data['room_id'] ?? $record->room_id);
                        abort_unless(in_array($roomId, app(ScopedRepository::class)->currentRoomIds($u), true), 403, 'Chỉ chọn phòng bạn đang cư trú hợp lệ.');
                        $room = Room::findOrFail($roomId);
                        $occupants = Rental::where('room_id', $roomId)->whereIn('status', ['active', 'move_out_pending'])->withCount(['occupants' => fn ($q) => $q->whereNull('ends_at')])->get()->sum('occupants_count');
                        if (($data['people_needed'] ?? 1) > $room->capacity - $occupants) {
                            throw ValidationException::withMessages(['people_needed' => 'Phòng không còn đủ chỗ theo sức chứa hiện tại.']);
                        }
                        $data['room_id'] = $roomId;
                    } else {
                        $data['room_id'] = null;
                    }
                    $data['mode'] = $mode;
                    if ($record->exists && $record->status !== 'open') {
                        throw ValidationException::withMessages(['roommate' => 'Nhu cầu đã kết thúc. Hãy đăng nhu cầu mới.']);
                    }
                    $before = $record->only(['name', 'scope', 'permissions', 'status']);
                    $record->fill($data)->save();
                    break;
                case 'roommate.close':
                    abort_unless(config('features.roommate'), 404);
                    $record = RoommateNeed::cloudAccessible()->where('user_id', $u->id)->lockForUpdate()->findOrFail($id);
                    $record->update(['status' => 'closed']);
                    RoommateRequest::where('roommate_need_id', $record->id)->where('status', 'pending')->update(['status' => 'rejected']);
                    break;
                case 'roommate.connect':
                    abort_unless(config('features.roommate'), 404);
                    $need = RoommateNeed::cloudAccessible()->where('status', 'open')->where('expires_at', '>', now())->lockForUpdate()->findOrFail($id);
                    abort_if($need->user_id === $u->id, 422);
                    $record = RoommateRequest::firstOrCreate(['roommate_need_id' => $id, 'user_id' => $u->id]);
                    if ($record->wasRecentlyCreated) {
                        $journal->notify($need->user_id, 'Kết nối ở ghép mới', 'Có người quan tâm nhu cầu ở ghép của bạn.', workspace: $need->room?->property->workspace_id);
                    } break;
                case 'roommate.respond':
                    abort_unless(config('features.roommate'), 404);
                    $candidate = RoommateRequest::findOrFail($id);
                    $need = RoommateNeed::cloudAccessible()->where('user_id', $u->id)->lockForUpdate()->findOrFail($candidate->roommate_need_id);
                    $record = RoommateRequest::whereKey($id)->lockForUpdate()->firstOrFail();
                    abort_unless($record->status === 'pending' && $need->status === 'open' && $need->expires_at->isFuture(), 422);
                    $record->update($data);
                    if ($data['status'] === 'accepted') {
                        $need->update(['status' => 'matched']);
                        RoommateRequest::where('roommate_need_id', $need->id)->where('id', '!=', $record->id)->where('status', 'pending')->update(['status' => 'rejected']);
                    }
                    $journal->notify($record->user_id, 'Kết nối ở ghép đã có phản hồi', $data['status'] === 'accepted' ? 'Nhu cầu ở ghép của bạn đã được chấp nhận.' : 'Lời mời ở ghép chưa phù hợp.', workspace: $need->room?->property->workspace_id);
                    break;
                default: abort(404);
            }
            $journal->record($u->id, $action, $record, null, ['before' => $before, 'after' => array_intersect_key($data, array_flip(['name', 'scope', 'permissions', 'access_group_id', 'user_id', 'permission', 'valid_from', 'valid_until', 'reason', 'status', 'resolution', 'code', 'category', 'delegable']))]);
        });
    }

    public function expire(): int
    {
        return DB::transaction(function () {
            $ids = RoommateNeed::cloudAccessible()->where('status', 'open')->where('expires_at', '<=', now())->lockForUpdate()->pluck('id');
            RoommateRequest::whereIn('roommate_need_id', $ids)->where('status', 'pending')->update(['status' => 'rejected']);
            foreach (RoommateNeed::cloudAccessible()->whereIn('id', $ids)->get() as $need) {
                $need->update(['status' => 'expired']);
                app(Journal::class)->record(null, 'roommate.expired', $need);
                app(Journal::class)->notify($need->user_id, 'Nhu cầu ở ghép đã hết hạn', 'Bạn có thể đăng nhu cầu mới khi vẫn cần tìm người ở cùng.', workspace: $need->room?->property->workspace_id);
            }

            return $ids->count();
        });
    }
}
