<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\DataPlatform\Journal;
use App\Modules\Property\Models\Room;
use App\Modules\Workspace\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

final class AssignmentAdministration
{
    public function save(User $actor, array $data, ?int $id = null): void
    {
        app(RootProtection::class)->mutation(function () use ($actor, $data, $id) {
            $old = $id ? GroupAssignment::whereKey($id)->lockForUpdate()->firstOrFail() : null;
            abort_if($old?->valid_until?->lte(now()), 409, 'Lần gán này đã hết hạn. Hãy tạo lần gán mới.');
            abort_if($old && ($old->origin !== 'explicit' || $old->revoked_at), 403, 'Hãy chỉnh sửa nguồn quyền này trong hồ sơ tương ứng.');
            $target = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            abort_if($actor->id === $target->id || $old && $old->user_id !== $target->id, 403);
            $workspace = ! empty($data['target_workspace_id']) ? Workspace::findOrFail($data['target_workspace_id']) : null;
            if ($workspace) Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if ($old) {
                abort_unless($old->workspace_id === $workspace?->id, 403);
            }
            $group = AccessGroup::where('active', true)->findOrFail($data['access_group_id']);
            abort_unless($group->status === 'active', 403, 'Nhóm này không nhận thêm lần gán hoặc mở rộng quyền.');
            $property = ! empty($data['property_id']) ? (int) $data['property_id'] : null;
            $room = ! empty($data['room_id']) ? (int) $data['room_id'] : null;
            if ($room) {
                $r = Room::with('property')->findOrFail($room);
                abort_unless($workspace && $r->property->workspace_id === $workspace->id && (! $property || $property === $r->property_id), 403);
                $property = $r->property_id;
            }
            if ($workspace) {
                $group = app(WorkspaceGroupCopies::class)->resolve($workspace, $group->id);
                abort_unless($group->scope === 'workspace' && (! $group->workspace_id || $group->workspace_id === $workspace->id), 403);
                $member = app(AccessService::class)->member($target, $workspace);
                abort_unless($member, 403, 'Tài khoản cần tham gia khu quản lý trước khi nhận nhóm bổ sung.');
                abort_if($member->property_ids !== null && (! $property || ! in_array($property, $member->property_ids, true)), 403);
                abort_if($member->room_ids !== null && (! $room || ! in_array($room, $member->room_ids, true)), 403);
                if ($member->valid_until) {
                    abort_unless(! empty($data['valid_until']) && Carbon::parse($data['valid_until'])->lte($member->valid_until), 403);
                }
                if ($old) {
                    app(DelegationPolicy::class)->workspace($actor, $workspace, $old->group->permissions, $old->property_id ? [$old->property_id] : null, now()->toDateTimeString(), $old->room_id ? [$old->room_id] : null);
                }
                app(DelegationPolicy::class)->workspace($actor, $workspace, $group->permissions, $property ? [$property] : null, $data['valid_until'] ?? null, $room ? [$room] : null);
            } else {
                abort_unless(in_array($group->scope, ['global', 'personal'], true) && ! $group->workspace_id && ! $property && ! $room && app(AccessService::class)->global($actor, 'access.assign'), 403);
                app(DelegationPolicy::class)->global($actor, array_unique([...($old?->group?->permissions ?? []), ...$group->permissions]), $data['valid_until'] ?? null);
            }
            if ($workspace && $old) abort_unless($old->membership_id === $member->id, 403);
            $key = $workspace ? 'workspace:'.$workspace->id.($room ? ':room:'.$room : ($property ? ':property:'.$property : '')).':membership:'.$member->id : ($group->scope === 'personal' ? 'personal' : 'platform');
            $duplicate = GroupAssignment::where('user_id', $target->id)->where('access_group_id', $group->id)->where('workspace_id', $workspace?->id)->where('property_id', $property)->where('room_id', $room)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->when($workspace, fn ($q) => $q->where('membership_id', $member->id))->when($id, fn ($q) => $q->whereKeyNot($id))->first();
            if ($duplicate && ! $duplicate->revoked_at) {
                throw ValidationException::withMessages(['access_group_id' => 'Nhóm đã được gán tại phạm vi này. Hãy chỉnh sửa lần gán hiện tại.']);
            }
            $record = $old ?? new GroupAssignment;
            if (! $old) $key .= ':'.\Illuminate\Support\Str::uuid();
            $before = $record->only(['access_group_id', 'property_id', 'room_id', 'valid_from', 'valid_until']);
            $record->fill(['user_id' => $target->id, 'access_group_id' => $group->id, 'workspace_id' => $workspace?->id, 'property_id' => $property, 'room_id' => $room, 'scope_key' => $key, 'origin' => 'explicit', 'grantor_id' => $actor->id, 'valid_from' => $data['valid_from'], 'valid_until' => $data['valid_until'] ?? null, 'revoked_at' => null])->save();
            if ($workspace) app(MembershipAuthority::class)->bind($actor, $member, $record);
            app(Journal::class)->record($actor->id, 'group.assignment.save', $record, $workspace?->id, ['summary' => $group->name.' · '.$target->email, 'before' => $before, 'after' => $record->only(['access_group_id', 'property_id', 'room_id', 'valid_from', 'valid_until'])]);
        });
    }

    public function revoke(User $actor, int $id): void
    {
        app(RootProtection::class)->mutation(function () use ($actor, $id) {
            $record = GroupAssignment::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($record->user_id === $actor->id || $record->origin !== 'explicit', 403);
            if ($record->workspace_id) {
                app(DelegationPolicy::class)->workspace($actor, Workspace::findOrFail($record->workspace_id), $record->group->permissions, $record->property_id ? [$record->property_id] : null, now()->toDateTimeString(), $record->room_id ? [$record->room_id] : null);
            } else {
                abort_unless(app(AccessService::class)->global($actor, 'access.assign'), 403);
                app(DelegationPolicy::class)->global($actor, $record->group->permissions, now()->toDateTimeString());
            }
            $record->update(['revoked_at' => now()]);
            app(Journal::class)->record($actor->id, 'group.assignment.revoke', $record, $record->workspace_id, ['summary' => $record->group->name.' · '.$record->user->email]);
        });
    }
}
