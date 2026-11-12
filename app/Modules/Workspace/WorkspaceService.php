<?php

namespace App\Modules\Workspace;

use App\Contracts\Authorization as AccessService;
use App\Models\User;
use App\Modules\AccessControl\DelegationPolicy;
use App\Modules\AccessControl\GroupAssignments;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\PermissionCodes;
use App\Modules\AccessControl\RootProtection;
use App\Modules\DataPlatform\Journal;
use App\Modules\Rental\Models\Rental;
use App\Modules\Rental\Models\ResidentProfile;
use App\Modules\Workspace\Events\InvitationUpdated;
use App\Modules\Workspace\Models\Invitation;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Support\ScopedRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WorkspaceService
{
    public function __construct(private AccessService $access, private Journal $journal) {}

    public function rename(User $user, Workspace $workspace, string $name): void
    {
        $this->access->authorize($user, 'workspace.update', $workspace);
        DB::transaction(function () use ($user, $workspace, $name) {
            $before = $workspace->only('name');
            $workspace->update(['name' => $name]);
            $this->journal->record($user->id, 'workspace.updated', $workspace, $workspace->id, ['before' => $before, 'after' => ['name' => $name]]);
        });
    }

    public function updateMembership(User $user, Workspace $workspace, int $id, array $data): void
    {
        abort_unless($this->access->allowed($user, 'member.update', $workspace) || $this->access->roomIds($user, $workspace, 'member.update') !== [], 403);
        DB::transaction(function () use ($user, $workspace, $id, $data) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $member = Membership::where('workspace_id', $workspace->id)->where('status', 'active')->lockForUpdate()->findOrFail($id);
            $selected = $data['access_group_ids'] ?? [$data['access_group_id'] ?? AccessGroup::where('key', 'custom')->value('id')];
            $resolved = app(\App\Modules\AccessControl\WorkspaceGroupCopies::class)->forMembership($workspace, $member, $selected, ($data['property_ids'] ?? null) ?: null, ($data['room_ids'] ?? null) ?: null, $data['valid_until'] ?? null);
            $group = AccessGroup::findOrFail($resolved[0] ?? AccessGroup::where('key', 'custom')->value('id'));
            $preservePrimary = ($member->permissions === null || $member->group?->status !== 'active') && in_array($member->access_group_id, $resolved, true);
            $permissions = array_values(array_unique($data['permissions'] ?? []));
            $groupIds = array_key_exists('access_group_ids', $data) ? $resolved : [];
            $groupCodes = $groupIds ? AccessGroup::whereIn('id', $groupIds)->get()->flatMap(fn ($g) => $g->permissions)->all() : [];
            $allCodes = array_unique([...$permissions, ...$groupCodes]);
            if ($workspace->owner_id !== $user->id) {
                app(DelegationPolicy::class)->workspace($user, $workspace, app(DelegationPolicy::class)->membershipCodes($member), $member->property_ids, $member->valid_until?->toDateTimeString(), $member->room_ids);
            }
            app(DelegationPolicy::class)->workspace($user, $workspace, $allCodes, $data['property_ids'] ?? null, $data['valid_until'] ?? null, $data['room_ids'] ?? null);
            $ids = array_values(array_map('intval', $data['property_ids'] ?? []));
            foreach ($ids as $propertyId) {
                $workspace->properties()->findOrFail($propertyId);
            }
            abort_if($member->user_id === $user->id, 403);
            $before = $member->only(['access_group_id', 'permissions', 'property_ids', 'valid_until']);
            $member->update(['access_group_id' => $preservePrimary ? $member->access_group_id : (array_key_exists('access_group_ids', $data) ? app(\App\Modules\AccessControl\WorkspaceGroupCopies::class)->resolve($workspace, (int) AccessGroup::where('key', 'custom')->value('id'))->id : $group->id), 'property_ids' => $ids ?: null, 'room_ids' => ! empty($data['room_ids']) ? array_map('intval', $data['room_ids']) : null, 'permissions_mode' => array_key_exists('access_group_ids', $data) ? 'additive' : 'replace', 'permissions' => $permissions, 'valid_until' => $data['valid_until'] ?? null]);
            app(\App\Modules\AccessControl\MembershipAuthority::class)->bind($user, $member);
            app(GroupAssignments::class)->workspace($user, $workspace, $member->user, $preservePrimary ? array_values(array_diff($groupIds, [$member->access_group_id])) : $groupIds, $data['valid_until'] ?? null);
            $this->journal->record($user->id, 'membership.updated', $member, $workspace->id, ['summary' => $member->user->name, 'before' => $before, 'after' => $member->only(['access_group_id', 'permissions', 'property_ids', 'valid_until'])]);
        });
    }

    public function endMembership(User $user, Workspace $workspace, int $id): void
    {
        app(MembershipLifecycle::class)->end($user, $workspace, $id);
    }

    public function revokeGrant(User $user, int $id): void
    {
        $grant = DirectGrant::findOrFail($id);
        abort_if($grant->user_id === $user->id, 403);
        if ($grant->workspace_id) {
            app(DelegationPolicy::class)->workspace($user, Workspace::findOrFail($grant->workspace_id), [$grant->permission], $grant->property_id ? [$grant->property_id] : null, now()->toDateTimeString(), $grant->room_id ? [$grant->room_id] : null);
        } else {
            abort_unless($this->access->global($user, 'access.revoke'), 403);
            foreach (PermissionCodes::expand([$grant->permission]) as $code) {
                abort_unless($this->access->global($user, $code) || $this->access->personal($user, $code), 403);
            }
        }
        app(RootProtection::class)->mutation(function () use ($grant, $user) {
            $grant->update(['revoked_at' => now()]);
            $this->journal->record($user->id, 'permission.revoked', $grant, $grant->workspace_id);
        });
    }

    public function invite(User $owner, Workspace $workspace, array $data): string
    {
        abort_unless($this->access->allowed($owner, 'member.invite', $workspace) || $this->access->roomIds($owner, $workspace, 'member.invite') !== [], 403);

        return app(StaffCapacity::class)->transaction($workspace, function ($workspace) use ($owner, $data) {
            Workspace::whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $group = app(\App\Modules\AccessControl\WorkspaceGroupCopies::class)->resolve($workspace, (int) ($data['access_group_ids'][0] ?? $data['access_group_id'] ?? AccessGroup::where('key', 'custom')->value('id')));
            $permissions = array_values(array_unique($data['permissions'] ?? []));
            $groupIds = app(\App\Modules\AccessControl\WorkspaceGroupCopies::class)->ids($workspace, $data['access_group_ids'] ?? []);
            $groupCodes = $groupIds ? AccessGroup::whereIn('id', $groupIds)->get()->flatMap(fn ($g) => $g->permissions)->all() : [];
            $allCodes = array_unique([...$permissions, ...$groupCodes]);
            app(DelegationPolicy::class)->workspace($owner, $workspace, [...$allCodes, 'member.invite'], $data['property_ids'] ?? null, $data['valid_until'] ?? null, $data['room_ids'] ?? null);
            $ids = array_values(array_map('intval', $data['property_ids'] ?? []));
            foreach ($ids as $id) {
                abort_unless($workspace->properties()->whereKey($id)->exists(), 403);
            }
            $email = mb_strtolower($data['email']);
            $target = User::where('email', $email)->first();
            if ($target?->id === $workspace->owner_id || ($target && Membership::where('workspace_id', $workspace->id)->where('user_id', $target->id)->where('status', '!=', 'ended')->exists())) {
                throw ValidationException::withMessages(['email' => 'Tài khoản này đã tham gia khu quản lý. Hãy chỉnh sửa quyền trong danh sách nhân viên.']);
            }
            if (Invitation::where('workspace_id', $workspace->id)->where('purpose', 'staff')->where('email', $email)->whereNull('accepted_at')->whereNull('cancelled_at')->whereNull('declined_at')->where('expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['email' => 'Email này đang có lời mời chờ xác nhận. Bạn có thể tạo lại liên kết tại mục Lời mời.']);
            }
            $token = Str::random(64);
            $invitation = Invitation::create(['workspace_id' => $workspace->id, 'email' => strtolower($data['email']), 'recipient_id' => $target?->id, 'permissions' => $permissions, 'inviter_id' => $owner->id, 'valid_until' => $data['valid_until'] ?? null, 'access_group_id' => $group->id, 'property_ids' => $ids ?: null, 'room_ids' => ! empty($data['room_ids']) ? array_map('intval', $data['room_ids']) : null, 'access_group_ids' => $groupIds ?: null, 'token_hash' => hash('sha256', $token), 'expires_at' => empty($data['valid_until']) ? now()->addDays(7) : now()->addDays(7)->min(Carbon::parse($data['valid_until']))]);
            $this->journal->record($owner->id, 'membership.invited', $invitation, $workspace->id, ['summary' => $invitation->email, 'permissions' => $permissions, 'property_ids' => $ids]);

            event(new InvitationUpdated($invitation->id));

            return $token;
        });
    }

    public function accept(User $user, string $token): void
    {
        abort_unless(User::whereKey($user->id)->where('status', 'active')->exists(), 403);
        $id = Invitation::where('token_hash', hash('sha256', $token))->value('id');
        abort_unless($id, 404);
        $this->acceptInvitation($user, (int) $id, hash('sha256', $token));
    }

    public function acceptInvitation(User $user, int $id, ?string $tokenHash = null): void
    {
        abort_unless(User::whereKey($user->id)->where('status', 'active')->exists(), 403);
        if (! $tokenHash) {
            app(InvitationService::class)->received($user, $id);
        }
        app(MembershipLifecycle::class)->requireSchema();
        $candidate = Invitation::findOrFail($id);
        app(StaffCapacity::class)->transaction(Workspace::findOrFail($candidate->workspace_id), function ($lockedWorkspace) use ($user, $id, $tokenHash) {
            $invite = Invitation::whereKey($id)->when($tokenHash, fn ($q) => $q->where('token_hash', $tokenHash))->whereNull('accepted_at')->whereNull('cancelled_at')->whereNull('declined_at')->where('expires_at', '>', now())->lockForUpdate()->firstOrFail();
            $currentUser = User::whereKey($user->id)->where('status', 'active')->firstOrFail();
            // Identity/claim is validated by received() or the single-use secret link above.
            abort_unless(mb_strtolower($currentUser->email) === $invite->email, 403);
            abort_unless(strtolower($user->email) === $invite->email, 403);
            $w = Workspace::findOrFail($invite->workspace_id);
            app(\App\Modules\Subscription\PropertyCloud::class)->requireActive($w);
            if ($invite->purpose === 'tenant') {
                $resident = ResidentProfile::where('workspace_id', $w->id)->findOrFail($invite->resident_profile_id);
                $resident->update(['user_id' => $user->id]);
                Rental::where('resident_profile_id', $resident->id)->whereNull('tenant_id')->update(['tenant_id' => $user->id]);
                if ($invite->purpose === 'tenant') {
                    app(GroupAssignments::class)->tenant($user);
                }
                $invite->update(['accepted_at' => now(), 'recipient_id' => $user->id]);
                event(new InvitationUpdated($invite->id, false));
                $this->journal->record($user->id, 'resident.claimed', $resident, $w->id);

                return;
            }
            $group = app(\App\Modules\AccessControl\WorkspaceGroupCopies::class)->resolve($w, $invite->access_group_id);
            $groupIds = app(\App\Modules\AccessControl\WorkspaceGroupCopies::class)->ids($w, $invite->access_group_ids ?? []);
            $permissions = $invite->permissions ?? $group->permissions;
            abort_if($invite->valid_until?->isPast(), 403);
            abort_if($invite->permissions !== null && ! $invite->inviter_id, 403);
            $inviter = User::findOrFail($invite->inviter_id ?? $w->owner_id);
            app(DelegationPolicy::class)->workspace($inviter, $w, array_unique([...$permissions, 'member.invite', ...AccessGroup::whereIn('id', $groupIds)->get()->flatMap(fn ($g) => $g->permissions)->all()]), $invite->property_ids, $invite->valid_until?->toDateTimeString(), $invite->room_ids);
            $existing = Membership::where('workspace_id', $w->id)->where('user_id', $user->id)->where('status', '!=', 'ended')->first();
            if ($existing) throw ValidationException::withMessages(['invitation' => 'Bạn đã có hồ sơ tham gia tại khu quản lý. Người quản lý cần kiểm tra hồ sơ hiện tại.']);
            $this->access->authorize($inviter, 'member.invite', $w, $invite->property_ids[0] ?? null, $invite->room_ids[0] ?? null);
            $active = app(StaffCapacity::class)->available($w, $user->id);
            $member = Membership::create(['workspace_id' => $w->id, 'user_id' => $user->id,
                'access_group_id' => $groupIds ? app(\App\Modules\AccessControl\WorkspaceGroupCopies::class)->resolve($w, (int) AccessGroup::where('key', 'custom')->value('id'))->id : $group->id,
                'permissions' => $permissions, 'property_ids' => $invite->property_ids, 'room_ids' => $invite->room_ids,
                'permissions_mode' => $invite->access_group_ids ? 'additive' : 'replace', 'requested_group_ids' => $invite->access_group_ids,
                'inviter_id' => $inviter->id, 'invitation_id' => $invite->id,
                'valid_until' => $invite->valid_until, 'status' => $active ? 'active' : 'pending']);
            $invite->update(['accepted_at' => now(), 'recipient_id' => $user->id]);
            if ($active) app(\App\Modules\AccessControl\MembershipAuthority::class)->bind($inviter, $member);
            if ($active && $groupIds) app(GroupAssignments::class)->workspace($inviter, $w, $user, $groupIds, $invite->valid_until?->toDateTimeString());
            event(new InvitationUpdated($invite->id, false));
            $this->journal->record($user->id, 'membership.accepted', $member, $w->id, ['status' => $member->status, 'capacity_pending' => ! $active]);
            app(\App\Modules\Subscription\PropertyCloud::class)->requireActive($w);
            app(DelegationPolicy::class)->workspace($inviter, $w, array_unique([...$permissions, 'member.invite', ...AccessGroup::whereIn('id', $groupIds)->get()->flatMap(fn ($g) => $g->permissions)->all()]), $invite->property_ids, $invite->valid_until?->toDateTimeString(), $invite->room_ids);
        });
    }

    public function inviteTenant(User $owner, Rental $rental): string
    {
        app(ScopedRepository::class)->managedRoom($owner, $rental->room_id, 'rental.create');
        abort_unless($rental->resident_profile_id && ! $rental->tenant_id, 422);
        $token = Str::random(64);
        DB::transaction(function () use ($owner, $rental, $token) {
            $resident = $rental->resident;
            $invite = Invitation::create(['workspace_id' => $resident->workspace_id, 'email' => $resident->email, 'purpose' => 'tenant', 'recipient_id' => User::whereRaw('LOWER(email) = ?', [mb_strtolower($resident->email)])->value('id'), 'inviter_id' => $owner->id, 'resident_profile_id' => $resident->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7)]);
            $this->journal->record($owner->id, 'resident.invited', $invite, $resident->workspace_id);
            event(new InvitationUpdated($invite->id));
        });

        return $token;
    }

    public function grant(User $owner, Workspace $workspace, array $data): void
    {
        app(\App\Modules\AccessControl\DirectGrantService::class)->issue($owner, $workspace, $data);
    }
}
