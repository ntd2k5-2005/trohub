<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\Property\Models\Room;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DelegationPolicy
{
    public function __construct(private AccessService $access) {}

    public function options(User $user, ?Workspace $workspace = null): array
    {
        $codes = $this->access->effectiveCodes($user, $workspace);

        return Permission::where('active', true)->when($workspace, fn ($q) => $q->where('delegable', true))->get()
            ->filter(fn ($p) => in_array($p->code, $codes, true) && (! $workspace || in_array('workspace', $p->scopes ?? [], true)))
            ->pluck('name', 'code')->all();
    }

    public function canWorkspace(User $user, Workspace $workspace, array $codes, ?array $properties, ?string $until = null, ?array $rooms = null): bool
    {
        try {
            $this->workspace($user, $workspace, $codes, $properties, $until, $rooms);

            return true;
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 403) {
                throw $e;
            }

            return false;
        }
    }

    public function membershipCodes(Membership $member): array
    {
        $base = $member->permissions_mode === 'additive' ? [...($member->group?->permissions ?? []), ...($member->permissions ?? [])] : ($member->permissions ?? $member->group?->permissions ?? []);
        foreach ($this->access->assignments($member->user) as $a) {
            if ($a->workspace_id === $member->workspace_id && $a->membership_id === $member->id) {
                $base = [...$base, ...$a->group->permissions];
            }
        }
        $additional = DirectGrant::where('membership_id', $member->id)->where('workspace_id', $member->workspace_id)->whereNull('revoked_at')->where('valid_from', '<=', now())->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->get()->filter(fn ($g) => app(GrantGraph::class)->usable($g))->pluck('permission')->all();
        $base = [...$base, ...$additional];

        return PermissionCodes::expand($base);
    }

    private function covers(User $user, string $code, ?Workspace $workspace, ?int $property, ?int $room, ?string $until): bool
    {
        if ($workspace) {
            try {
                app(AuthoritySources::class)->select($user, $workspace, $code, $property, $room, $until);
                return true;
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 403) throw $e;
                return false;
            }
        }
        $end = $until ? Carbon::parse($until) : null;
        $platform = ! $workspace && $this->access->global($user, $code);
        $g = AccessGroup::find(User::whereKey($user->id)->value('access_group_id'));
        if ($g?->active && $g->status !== 'retired' && in_array($g->scope, $workspace || $platform ? ['global'] : ['global', 'personal'], true) && in_array($code, PermissionCodes::expand($g->permissions), true)) {
            return true;
        }
        $member = $workspace ? $this->access->member($user, $workspace) : null;
        if ($member) {
            $valid = $member->group?->active && $member->group->scope === 'workspace' && (! $member->group->workspace_id || $member->group->workspace_id === $workspace->id);
            $groupCodes = $valid ? $member->group->permissions : [];
            $base = $member->permissions_mode === 'additive' ? [...$groupCodes, ...($member->permissions ?? [])] : ($valid ? ($member->permissions ?? $groupCodes) : []);
            if (in_array($code, PermissionCodes::expand($base), true) && (! $member->valid_until || $end && $member->valid_until->gte($end))) {
                return true;
            }
        }
        foreach ($this->access->assignments($user) as $a) {
            if ($a->group->status === 'retired') continue;
            if (! $workspace && $platform && $a->group->scope !== 'global') {
                continue;
            }
            if ($a->workspace_id === null && $workspace && $a->group->scope !== 'global') {
                continue;
            }
            if ($workspace && $a->origin === 'ownership' && $workspace->owner_id !== $user->id) {
                continue;
            }
            if ($a->workspace_id && $a->origin !== 'ownership' && (! $member || $member->valid_until && (! $end || $member->valid_until->lt($end)))) {
                continue;
            }
            if ($a->workspace_id !== null && $a->workspace_id !== $workspace?->id || $a->property_id !== null && $a->property_id !== $property || $a->room_id !== null && $a->room_id !== $room) {
                continue;
            }
            if (in_array($code, PermissionCodes::expand($a->group->permissions), true) && (! $a->valid_until || $end && $a->valid_until->gte($end))) {
                return true;
            }
        }

        return $this->access->grants($user, $code)->when($workspace, fn ($q) => $q->where(fn ($q) => $q->where('workspace_id', $workspace->id)->when(! $member || $member->valid_until && (! $end || $member->valid_until->lt($end)), fn ($q) => $q->whereRaw('1=0'))->orWhere(fn ($q) => $q->whereNull('workspace_id')->whereIn('scope_type', ['auto', 'platform']))), fn ($q) => $q->whereNull('workspace_id')->when($platform, fn ($q) => $q->whereIn('scope_type', ['auto', 'platform'])))
            ->where(fn ($q) => $q->whereNull('property_id')->when($property !== null, fn ($q) => $q->orWhere('property_id', $property)))
            ->where(fn ($q) => $q->whereNull('room_id')->when($room !== null, fn ($q) => $q->orWhere('room_id', $room)))
            ->where(fn ($q) => $q->whereNull('valid_until')->when($end, fn ($q) => $q->orWhere('valid_until', '>=', $end)))->exists();
    }

    public function global(User $user, array $codes, ?string $until = null): void
    {
        abort_unless($this->access->global($user, 'access.delegate'), 403);
        foreach (array_unique([...PermissionCodes::expand($codes), 'access.delegate']) as $code) {
            $p = Permission::where('code', $code)->where('active', true)->first();
            abort_unless($p && ($this->access->global($user, $code) || $this->access->personal($user, $code)), 403);
            abort_unless($this->covers($user, $code, null, null, null, $until), 403);
            if (! $p->delegable) {
                abort_unless($this->access->global($user, config('authorization.bootstrap_permission')), 403);
            }
        }
    }

    public function workspace(User $user, Workspace $workspace, array $codes, ?array $properties, ?string $until = null, ?array $rooms = null): void
    {
        $properties = $properties ? array_map('intval', $properties) : null;
        $rooms = $rooms ? array_map('intval', $rooms) : null;
        $codes = PermissionCodes::expand($codes);
        abort_if(array_diff($codes, array_keys($this->options($user, $workspace))) !== [], 403);
        $member = $this->access->member($user, $workspace);
        if ($workspace->owner_id !== $user->id && ! $this->access->global($user, 'member.delegate')) {
            abort_unless($member, 403);
            abort_if($member->property_ids !== null && (! $properties || array_diff($properties, $member->property_ids)), 403);
            abort_if($member->room_ids !== null && (! $rooms || array_diff($rooms, $member->room_ids)), 403);
            if ($member->valid_until) {
                abort_unless($until && Carbon::parse($until)->lte($member->valid_until), 403);
            }
        }
        $targets = [];
        if ($rooms) {
            foreach ($rooms as $id) {
                $room = Room::whereHas('property', fn ($q) => $q->where('workspace_id', $workspace->id))->findOrFail($id);
                abort_unless(! $properties || in_array($room->property_id, $properties, true), 403);
                $targets[] = [$room->property_id, $room->id];
            }
        } else {
            foreach ($properties ?: [null] as $property) {
                $targets[] = [$property, null];
            }
        }
        foreach ($targets as [$property,$room]) {
            foreach (array_unique([...$codes, 'member.delegate']) as $code) {
                $supported = Permission::where('code', $code)->where('active', true)->value('scopes');
                $supported = is_string($supported) ? json_decode($supported, true) : $supported;
                $scope = $room !== null ? 'room' : ($property !== null ? 'property' : 'workspace');
                // House-only actions in a group never become effective in a room binding.
                if ($code !== 'member.delegate' && ! in_array($scope, $supported ?? [], true)) {
                    continue;
                }
                $this->access->authorize($user, $code, $workspace, $property, $room);
                abort_unless($this->covers($user, $code, $workspace, $property, $room, $until), 403);
            }
        }
    }
}
