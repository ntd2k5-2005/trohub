<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Carbon;

final class AuthoritySources
{
    public function select(User $actor, Workspace $workspace, string $code, ?int $property, ?int $room, ?string $until, ?int $parent = null): array
    {
        $member = app(AccessService::class)->member($actor, $workspace);
        $candidates = [];
        $primary = AccessGroup::find(User::whereKey($actor->id)->value('access_group_id'));
        if ($primary?->active && $primary->scope === 'global' && $primary->status !== 'retired' && in_array($code, PermissionCodes::expand($primary->permissions), true)) {
            $candidates[] = $this->source('platform', $primary->id, null, null, null, $primary->id, null);
        }
        foreach (GroupAssignment::with('group')->where('user_id', $actor->id)->whereNull('revoked_at')->get() as $a) {
            if ($a->permission_origin === 'personal' || ! $a->group?->active || $a->group->status === 'retired' || $a->valid_from?->gt(now()) || $a->valid_until?->lte(now()) || ! in_array($code, PermissionCodes::expand($a->group->permissions), true)) continue;
            if ($a->workspace_id !== null && $a->workspace_id !== $workspace->id || ! $a->workspace_id && $a->group->scope !== 'global') continue;
            if ($a->workspace_id && ($a->group->scope !== 'workspace' || $a->group->workspace_id !== null && $a->group->workspace_id !== $workspace->id)) continue;
            $ownership = $a->origin === 'ownership' && $workspace->owner_id === $actor->id && $a->workspace_id === $workspace->id;
            if ($a->workspace_id && ! $ownership && (! $member || $a->membership_id !== $member->id)) continue;
            $props = $a->property_id ? [$a->property_id] : null;
            $rooms = $a->room_id ? [$a->room_id] : null;
            if ($member && $a->workspace_id && ! $ownership) {
                $props = $this->intersection($props, $member->property_ids);
                $rooms = $this->intersection($rooms, $member->room_ids);
            }
            $end = $a->valid_until;
            if ($member && $a->workspace_id && ! $ownership && $member->valid_until && (! $end || $member->valid_until->lt($end))) $end = $member->valid_until;
            $candidates[] = $this->source($ownership ? 'ownership' : 'assignment', $a->id, $props, $rooms, $end?->toIso8601String(), $a->access_group_id, $ownership || ! $a->workspace_id ? null : $member?->id, from: $a->valid_from?->toIso8601String());
        }
        if ($member && $member->permission_origin !== 'personal') {
            if (in_array($code, PermissionCodes::expand($member->permissions ?? []), true)) $candidates[] = $this->source('membership', $member->id, $member->property_ids, $member->room_ids, $member->valid_until?->toIso8601String(), null, $member->id, 0, $member->source_revision);
            if (($member->permissions_mode === 'additive' || $member->permissions === null) && $member->group?->active && $member->group->scope === 'workspace' && (! $member->group->workspace_id || $member->group->workspace_id === $workspace->id) && $member->group->status !== 'retired' && in_array($code, PermissionCodes::expand($member->group->permissions), true)) $candidates[] = $this->source('membership', $member->id, $member->property_ids, $member->room_ids, $member->valid_until?->toIso8601String(), $member->access_group_id, $member->id, 0, $member->source_revision);
        }
        foreach (DirectGrant::where('user_id', $actor->id)->where(fn ($q) => $q->where('workspace_id', $workspace->id)->orWhereNull('workspace_id'))->whereNull('revoked_at')->get() as $grant) {
            if (! $grant->workspace_id && $grant->scope_type !== 'platform') continue;
            if ($grant->authority_kind === 'legacy' || ! in_array($code, PermissionCodes::expand([$grant->permission]), true) || ! app(GrantGraph::class)->usable($grant)) continue;
            if ($this->retiredRoot($grant)) continue;
            $candidates[] = $this->source('grant', $grant->id, $grant->property_id ? [$grant->property_id] : null, $grant->room_id ? [$grant->room_id] : null, $grant->valid_until?->toIso8601String(), null, $grant->workspace_id ? $member?->id : null, $grant->depth, from: $grant->valid_from->toIso8601String());
        }
        foreach ($candidates as $source) {
            if ($parent !== null && ($source['kind'] !== 'grant' || $source['id'] !== $parent)) continue;
            if (! ResourceScope::fits($source['snapshot']['properties'], $source['snapshot']['rooms'], $property, $room)) continue;
            if ($source['snapshot']['until'] && (! $until || Carbon::parse($until)->gt(Carbon::parse($source['snapshot']['until'])))) continue;
            return $source;
        }
        abort(403, 'Không có nguồn cấp quyền còn hiệu lực bao phủ đúng phạm vi và thời hạn này.');
    }

    private function source(string $kind, int $id, ?array $properties, ?array $rooms, ?string $until, ?int $group, ?int $member, int $depth = 0, ?int $revision = null, ?string $from = null): array
    {
        return ['kind' => $kind, 'id' => $id, 'member' => $member, 'depth' => $depth,
            'snapshot' => ['properties' => $properties, 'rooms' => $rooms, 'until' => $until, 'group_id' => $group, 'revision' => $revision, 'from' => $from]];
    }

    private function intersection(?array $a, ?array $b): ?array
    {
        return $a === null ? $b : ($b === null ? $a : array_values(array_intersect($a, $b)));
    }

    public function retiredRoot(DirectGrant $grant, array $seen = []): bool
    {
        if (isset($seen[$grant->id])) return true;
        $seen[$grant->id] = true;
        if ($grant->origin_kind === 'group' && GroupAssignment::find($grant->origin_id)?->group?->status === 'retired') return true;
        foreach ([[$grant->source_kind, $grant->source_id, $grant->source_snapshot], [$grant->delegation_source_kind, $grant->delegation_source_id, $grant->delegation_snapshot]] as [$kind,$id,$snapshot]) {
            if (! empty($snapshot['group_id']) && AccessGroup::whereKey($snapshot['group_id'])->value('status') === 'retired') return true;
            if ($kind === 'grant' && ($parent = DirectGrant::find($id)) && $this->retiredRoot($parent, $seen)) return true;
        }
        return false;
    }
}
