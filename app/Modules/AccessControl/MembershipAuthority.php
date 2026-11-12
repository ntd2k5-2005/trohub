<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\Workspace\Models\Membership;

/** Personal delegation through membership/group UI uses the same graph as direct grants. */
final class MembershipAuthority
{
    public function bind(User $actor, Membership $member, ?GroupAssignment $assignment = null): void
    {
        $organization = $member->workspace->owner_id === $actor->id;
        if ($organization && ($assignment?->permission_origin ?? $member->permission_origin) === 'organization') return;
        $kind = $assignment ? 'group' : 'membership';
        $id = $assignment?->id ?? $member->id;
        DirectGrant::where('origin_kind', $kind)->where('origin_id', $id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'status' => 'revoked', 'revocation_reason' => 'Thay nguồn cấp quyền']);
        $origin = $organization ? 'organization' : 'personal';
        if ($assignment) $assignment->update(['permission_origin' => $origin]);
        else $member->update(['permission_origin' => $origin, 'source_revision' => $member->source_revision + 1]);
        if (! $organization) {
            $codes = $assignment ? $assignment->group->permissions : ($member->permissions ?? $member->group?->permissions ?? []);
            $this->issueCodes($actor, $member, $assignment, $codes);
        }
        app(GrantGraph::class)->reconcile();
    }

    public function expandGroup(User $actor, \App\Modules\AccessControl\Models\AccessGroup $group, array $added): void
    {
        if (! $added) return;
        foreach (GroupAssignment::where('access_group_id', $group->id)->where('permission_origin', 'personal')->whereNull('revoked_at')->get() as $assignment) {
            $member = Membership::whereKey($assignment->membership_id)->where('status', 'active')->first();
            if ($member && (! $member->valid_until || $member->valid_until->isFuture()) && (! $assignment->valid_until || $assignment->valid_until->isFuture())) $this->issueCodes($actor, $member, $assignment, $added);
        }
        foreach (Membership::where('access_group_id', $group->id)->where('permission_origin', 'personal')->whereNull('permissions')->where('status', 'active')->get() as $member) {
            if (! $member->valid_until || $member->valid_until->isFuture()) $this->issueCodes($actor, $member, null, $added);
        }
    }

    private function issueCodes(User $actor, Membership $member, ?GroupAssignment $assignment, array $codes): void
    {
            $kind = $assignment ? 'group' : 'membership';
            $id = $assignment?->id ?? $member->id;
            $properties = $assignment?->property_id ? [$assignment->property_id] : $member->property_ids;
            $rooms = $assignment?->room_id ? [$assignment->room_id] : $member->room_ids;
            if ($rooms && $properties) $rooms = \App\Modules\Property\Models\Room::whereIn('id', $rooms)->whereIn('property_id', $properties)->pluck('id')->all();
            $until = $assignment?->valid_until ?? $member->valid_until;
            if ($member->valid_until && (! $until || $member->valid_until->lt($until))) $until = $member->valid_until;
            $targets = $rooms ? array_map(fn ($room) => [\App\Modules\Property\Models\Room::findOrFail($room)->property_id, $room], $rooms) : array_map(fn ($property) => [$property, null], $properties ?: [null]);
            foreach ($targets as [$property, $room]) foreach (PermissionCodes::expand($codes) as $code) {
                $scopes = \App\Modules\AccessControl\Models\Permission::where('code', $code)->value('scopes');
                if (is_string($scopes)) $scopes = json_decode($scopes, true);
                if (! in_array($room ? 'room' : ($property ? 'property' : 'workspace'), $scopes ?? [], true)) continue;
                $grant = app(DirectGrantService::class)->issue($actor, $member->workspace, ['user_id' => $member->user_id, 'permission' => $code, 'property_id' => $property, 'room_id' => $room, 'valid_from' => $assignment?->valid_from?->toDateTimeString() ?? now()->toDateTimeString(), 'valid_until' => $until?->toDateTimeString(), 'reason' => 'Cấp quyền làm việc đã được xác nhận']);
                $grant->update(['origin_kind' => $kind, 'origin_id' => $id]);
            }
    }
}
