<?php

namespace App\Modules\Workspace;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\DelegationPolicy;
use App\Modules\AccessControl\GroupAssignments;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\DataPlatform\Journal;
use App\Modules\Subscription\PropertyCloud;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\MembershipHandover;
use App\Modules\Workspace\Models\WorkAssignment;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Schema;

final class MembershipLifecycle
{
    public function requireSchema(): void
    {
        abort_unless(Schema::hasColumn('memberships', 'current_key'), 503, 'Chức năng nhân sự đang chờ cập nhật dữ liệu an toàn.');
    }

    public function activate(User $actor, Workspace $workspace, int $id): void
    {
        $this->requireSchema();
        app(StaffCapacity::class)->transaction($workspace, function ($workspace) use ($actor, $id) {
            $m = Membership::where('workspace_id', $workspace->id)->lockForUpdate()->findOrFail($id);
            $this->manage($actor, $workspace, $m, 'member.update');
            abort_unless(in_array($m->status, ['pending', 'suspended'], true) && (! $m->valid_until || $m->valid_until->isFuture()), 409, 'Lần tham gia không còn đủ điều kiện kích hoạt.');
            abort_unless($m->user->status === 'active', 403);
            abort_unless(app(StaffCapacity::class)->available($workspace, $m->user_id), 409, 'Chưa có suất nhân sự hoặc gói chung cần được đối chiếu. Nhân viên vẫn chờ kích hoạt.');
            $codes = array_unique([...($m->permissions ?? $m->group?->permissions ?? []), ...\App\Modules\AccessControl\Models\AccessGroup::whereIn('id', $m->requested_group_ids ?? [])->get()->flatMap(fn ($g) => $g->permissions)->all()]);
            app(DelegationPolicy::class)->workspace($actor, $workspace, $codes, $m->property_ids, $m->valid_until?->toDateTimeString(), $m->room_ids);
            $pending = $m->status === 'pending';
            $m->update(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null, 'version' => $m->version + 1]);
            if ($pending) app(\App\Modules\AccessControl\MembershipAuthority::class)->bind($actor, $m);
            if ($pending && $m->requested_group_ids) app(GroupAssignments::class)->workspace($actor, $workspace, $m->user, $m->requested_group_ids, $m->valid_until?->toDateTimeString());
            app(Journal::class)->record($actor->id, 'membership.activated', $m, $workspace->id);
        });
    }

    public function suspend(User $actor, Workspace $workspace, int $id, string $reason): void
    {
        abort_unless(trim($reason) !== '', 422);
        app(StaffCapacity::class)->transaction($workspace, function ($workspace) use ($actor, $id, $reason) {
            $m = Membership::where('workspace_id', $workspace->id)->lockForUpdate()->findOrFail($id);
            $this->manage($actor, $workspace, $m, 'member.update');
            abort_unless($m->status === 'active', 409);
            $m->update(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => $reason, 'version' => $m->version + 1]);
            app(Journal::class)->record($actor->id, 'membership.suspended', $m, $workspace->id, ['reason' => $reason]);
        });
    }

    private function manage(User $actor, Workspace $workspace, Membership $m, string $permission): void
    {
        $access = app(Authorization::class);
        abort_if($m->user_id === $actor->id || $m->user_id === $workspace->owner_id, 403);
        $policy = app(DelegationPolicy::class);
        // Checking the target scope also protects pending and suspended memberships.
        $policy->workspace($actor, $workspace, [$permission], $m->property_ids, now()->toDateTimeString(), $m->room_ids);
        if ($workspace->owner_id !== $actor->id) $policy->workspace($actor, $workspace, $policy->membershipCodes($m), $m->property_ids, now()->toDateTimeString(), $m->room_ids);
    }

    public function end(User $actor, Workspace $workspace, int $id, string $reason = 'Kết thúc làm việc', bool $self = false): void
    {
        $this->requireSchema();
        app(StaffCapacity::class)->transaction($workspace, function ($workspace) use ($actor, $id, $reason, $self) {
            $m = Membership::where('workspace_id', $workspace->id)->lockForUpdate()->findOrFail($id);
            if ($self) {
                abort_unless($m->user_id === $actor->id && $workspace->owner_id !== $actor->id && User::whereKey($actor->id)->where('status', 'active')->exists(), 403);
            } else $this->manage($actor, $workspace, $m, 'member.end');
            if ($m->status === 'ended') return;
            $before = $m->only(['user_id', 'status', 'permissions', 'property_ids', 'room_ids', 'valid_until']);
            DirectGrant::where('membership_id', $m->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            GroupAssignment::where('membership_id', $m->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $m->update(['status' => 'ended', 'ended_at' => now(), 'current_key' => null, 'version' => $m->version + 1]);
            $case = MembershipHandover::firstOrCreate(['membership_id' => $m->id], ['workspace_id' => $workspace->id, 'actor_id' => $actor->id, 'reason' => $reason, 'snapshot' => $before, 'status' => 'pending']);
            WorkAssignment::where('membership_id', $m->id)->where('status', 'open')->update(['status' => 'handover_pending', 'current_key' => null, 'handover_id' => $case->id]);
            if (! WorkAssignment::where('handover_id', $case->id)->exists()) $case->update(['status' => 'completed']);
            app(Journal::class)->record($actor->id, 'membership.ended', $m, $workspace->id, ['reason' => $reason, 'before' => $before, 'handover_id' => $case->id]);
        });
    }
}
