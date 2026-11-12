<?php

namespace App\Modules\AccessControl;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\Property\Models\Room;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class AccessService implements Authorization
{
    public function grants(User $user, string $permission): Builder
    {
        $checks = PermissionCodes::checks($permission);
        $legacy = array_keys(array_filter(config('authorization.legacy'), fn ($leaves) => count(array_intersect($checks, $leaves)) > 0));

        return $this->liveGrants($user)->whereIn('permission', array_unique([...$checks, ...$legacy]));
    }

    private function liveGrants(User $user): Builder
    {
        return DirectGrant::where('user_id', $user->id)->whereNull('revoked_at')->where('valid_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()));
    }

    public function assignments(User $user): Collection
    {
        return GroupAssignment::with('group')->where('user_id', $user->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->get()->filter(fn ($a) => $a->group?->active);
    }

    private function sources(User $user, ?Workspace $workspace = null): array
    {
        // One fresh snapshot per authorization operation. Never retained across writes or requests.
        $current = User::find($user->id);
        $primary = $current?->access_group_id ? AccessGroup::find($current->access_group_id) : null;
        $assignments = $this->assignments($user);
        $accountGroups = $assignments->filter(fn ($a) => ! $a->workspace_id && ! $a->property_id && ! $a->room_id)->pluck('group');
        if ($primary?->active) {
            $accountGroups->push($primary);
        }
        $platform = PermissionCodes::expand($accountGroups->where('scope', 'global')->flatMap(fn ($g) => $g->permissions)->all());
        $personal = PermissionCodes::expand($accountGroups->whereIn('scope', ['global', 'personal'])->flatMap(fn ($g) => $g->permissions)->all());

        $grants = $this->liveGrants($user)->get()->filter(fn ($g) => app(GrantGraph::class)->usable($g));
        $account = $workspace ? app(\App\Modules\Subscription\AccountSubscriptionService::class)->current($workspace->owner_id) : null;
        return ['active' => $current?->status === 'active', 'catalog' => Permission::where('active', true)->get()->keyBy('code'),
            'platform' => $platform, 'personal' => $personal, 'assignments' => $assignments, 'grants' => $grants,
            'member' => $workspace ? $this->member($user, $workspace) : null, 'workspace_live' => $workspace ? app(\App\Modules\Subscription\PropertyCloud::class)->active($workspace) : false, 'plan_features' => $account?->planVersion->snapshot['features'] ?? null];
    }

    private function accountAllowed(array $sources, string $leaf, bool $personal): bool
    {
        $scope = $personal ? 'personal' : 'platform';
        $p = $sources['catalog']->get($leaf);
        if (! $sources['active'] || ! $p || $p->scopes !== null && ! in_array($scope, $p->scopes, true)) {
            return false;
        }
        if (in_array($leaf, $sources[$personal ? 'personal' : 'platform'], true)) {
            return true;
        }

        return $sources['grants']->contains(fn ($g) => ! $g->workspace_id && ! $g->property_id && ! $g->room_id
            && in_array($g->scope_type, $personal ? ['auto', 'personal', 'platform'] : ['auto', 'platform'], true)
            && in_array($leaf, PermissionCodes::expand([$g->permission]), true));
    }

    public function global(User $user, string $permission): bool
    {
        $sources = $this->sources($user);
        foreach (PermissionCodes::checks($permission) as $leaf) {
            if ($this->accountAllowed($sources, $leaf, false)) {
                return true;
            }
        }

        return false;
    }

    /** Administrative comparison only: locking does not erase a target's assigned authority. */
    public function platformAuthorityCodes(User $user): array
    {
        $sources = $this->sources($user);
        $sources['active'] = true;

        return $sources['catalog']->filter(fn ($p) => $this->accountAllowed($sources, $p->code, false))->keys()->all();
    }

    public function personal(User $user, string $permission): bool
    {
        $sources = $this->sources($user);
        foreach (PermissionCodes::checks($permission) as $leaf) {
            if ($this->accountAllowed($sources, $leaf, true)) {
                return true;
            }
        }

        return false;
    }

    public function member(User $user, Workspace $workspace): ?Membership
    {
        return Membership::with('group')->where('user_id', $user->id)->where('workspace_id', $workspace->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->first();
    }

    private function fits(?array $properties, ?array $rooms, ?int $property, ?int $room): bool
    {
        if ($properties !== null && ($property === null || ! in_array($property, $properties, true))) {
            return false;
        }
        if ($rooms !== null && ($room === null || ! in_array($room, $rooms, true))) {
            return false;
        }

        return true;
    }

    public function canManageLifecycle(User $user, Workspace $workspace): bool
    {
        $workspace = $workspace->fresh();
        return $this->evaluate($user, $this->sources($user, $workspace), 'workspace.update', $workspace, administrative: true);
    }

    private function evaluate(User $user, array $sources, string $permission, Workspace $workspace, ?int $property = null, ?int $room = null, bool $administrative = false): bool
    {
        if (! $sources['active'] || $workspace->purged_at || $workspace->status === 'purging') {
            return false;
        }
        $live = $sources['workspace_live'] ?? app(\App\Modules\Subscription\PropertyCloud::class)->active($workspace);
        $member = $sources['member'];
        foreach (PermissionCodes::checks($permission) as $leaf) {
            $p = $sources['catalog']->get($leaf);
            $scope = $room !== null ? 'room' : ($property !== null ? 'property' : 'workspace');
            if (! $p || ! in_array($scope, $p->scopes ?? [], true)) {
                continue;
            }
            if ($sources['plan_features'] !== null) {
                $blocked = false;
                foreach (config('subscriptions.feature_permissions') as $prefix => $feature) if (str_starts_with($leaf, $prefix) && ! in_array($feature, $sources['plan_features'], true)) $blocked = true;
                if ($blocked) continue;
            }
            if (! $administrative && ($workspace->lifecycle ?? 'active') !== 'active' && in_array($leaf, config('authorization.new_commercial_permissions'), true)) continue;
            if (! $live && ! ($administrative && $leaf === 'workspace.update') && ! in_array($leaf, config('authorization.continuity'), true)) {
                continue;
            }
            if ($leaf === 'analytics.view' && (! config('features.analytics') || ! in_array('analytics', config('subscriptions.plans.'.$workspace->plan.'.features', []), true) || $member?->property_ids !== null && $workspace->owner_id !== $user->id)) {
                continue;
            }
            if ($this->accountAllowed($sources, $leaf, false)) {
                return true;
            }
            if ($member && $this->fits($member->property_ids, $member->room_ids, $property, $room)) {
                $validGroup = $member->group?->active && $member->group->scope === 'workspace' && (! $member->group->workspace_id || $member->group->workspace_id === $workspace->id);
                $groupCodes = $validGroup ? $member->group->permissions : [];
                $base = $member->permissions_mode === 'additive' ? [...$groupCodes, ...($member->permissions ?? [])] : ($member->permissions ?? $groupCodes);
                if ($member->permission_origin !== 'personal' && in_array($leaf, PermissionCodes::expand($base), true)) {
                    return true;
                }
                foreach ($sources['grants'] as $grant) {
                    if ($grant->membership_id === $member->id && $grant->workspace_id === $workspace->id && (! $grant->property_id || $grant->property_id === $property) && (! $grant->room_id || $grant->room_id === $room) && in_array($leaf, PermissionCodes::expand([$grant->permission]), true)) {
                        return true;
                    }
                }
            }
            foreach ($sources['assignments'] as $a) {
                if ($a->workspace_id !== $workspace->id || $a->group->scope !== 'workspace' || $a->group->workspace_id && $a->group->workspace_id !== $workspace->id) {
                    continue;
                }
                if ($a->origin === 'ownership' && $workspace->owner_id !== $user->id) {
                    continue;
                }
                if ($a->origin !== 'ownership' && (! $member || ! $this->fits($member->property_ids, $member->room_ids, $property, $room))) {
                    continue;
                }
                if ($a->origin !== 'ownership' && ($a->membership_id !== $member->id || $a->permission_origin === 'personal')) continue;
                if ($a->property_id !== null && $a->property_id !== $property || $a->room_id !== null && $a->room_id !== $room) {
                    continue;
                }
                if (in_array($leaf, PermissionCodes::expand($a->group->permissions), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function allowed(User $user, string $permission, Workspace $workspace, ?int $propertyId = null, ?int $roomId = null): bool
    {
        $workspace = Workspace::find($workspace->id);
        if (! $workspace) {
            return false;
        }
        if ($propertyId !== null && ! $workspace->properties()->whereKey($propertyId)->exists()) {
            return false;
        }
        if ($roomId !== null) {
            $room = Room::whereKey($roomId)->whereHas('property', fn ($q) => $q->where('workspace_id', $workspace->id))->first();
            if (! $room || $propertyId !== null && $room->property_id !== $propertyId) {
                return false;
            }
            $propertyId = $room->property_id;
        }

        return $this->evaluate($user, $this->sources($user, $workspace), $permission, $workspace, $propertyId, $roomId);
    }

    public function authorize(User $user, string $permission, Workspace $workspace, ?int $propertyId = null, ?int $roomId = null): void
    {
        abort_unless($this->allowed($user, $permission, $workspace, $propertyId, $roomId), 403, 'Bạn không có quyền thực hiện trong phạm vi này.');
    }

    public function workspaces(User $user): Collection
    {
        if (! User::whereKey($user->id)->where('status', 'active')->exists()) {
            return collect();
        }
        if ($this->global($user, 'workspace.view_all')) {
            return Workspace::whereNull('purged_at')->get();
        }

        return Workspace::whereNull('purged_at')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhereIn('id', Membership::where('user_id', $user->id)->where('status', 'active')->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->select('workspace_id')))->get();
    }

    public function roomIds(User $user, Workspace $workspace, string $permission): array
    {
        $workspace = Workspace::find($workspace->id);
        if (! $workspace) {
            return [];
        }
        $sources = $this->sources($user, $workspace);

        return Room::whereHas('property', fn ($q) => $q->where('workspace_id', $workspace->id))->get(['id', 'property_id'])
            ->filter(fn ($r) => $this->evaluate($user, $sources, $permission, $workspace, $r->property_id, $r->id))->pluck('id')->all();
    }

    public function propertyIds(User $user, Workspace $workspace, string $permission): array
    {
        $workspace = Workspace::find($workspace->id);
        if (! $workspace) {
            return [];
        }
        $sources = $this->sources($user, $workspace);
        $whole = $workspace->properties()->pluck('id')->filter(fn ($id) => $this->evaluate($user, $sources, $permission, $workspace, $id))->all();
        $partial = Room::whereHas('property', fn ($q) => $q->where('workspace_id', $workspace->id))->get(['id', 'property_id'])->filter(fn ($r) => $this->evaluate($user, $sources, $permission, $workspace, $r->property_id, $r->id))->pluck('property_id')->all();

        return array_values(array_unique([...$whole, ...$partial]));
    }

    public function permissionPropertyMap(User $user, Workspace $workspace, array $permissions): array
    {
        $map = array_fill_keys(array_unique($permissions), []);
        $workspace = Workspace::find($workspace->id);
        if (! $workspace) return $map;
        // One fresh, local snapshot for this read; never retained across writes or requests.
        $sources = $this->sources($user, $workspace);
        $sources['owner_active'] = $workspace->owner()->where('status', 'active')->exists();
        $properties = $workspace->properties()->pluck('id')->all();
        $rooms = Room::whereIn('property_id', $properties)->get(['id', 'property_id']);
        foreach ($map as $code => $unused) {
            foreach ($properties as $property) if ($this->evaluate($user, $sources, $code, $workspace, $property)) $map[$code][] = $property;
            foreach ($rooms as $room) if ($this->evaluate($user, $sources, $code, $workspace, $room->property_id, $room->id)) $map[$code][] = $room->property_id;
            $map[$code] = array_values(array_unique($map[$code]));
        }
        return $map;
    }

    public function effectiveCodes(User $user, ?Workspace $workspace = null): array
    {
        $workspace = $workspace ? Workspace::find($workspace->id) : null;
        $sources = $this->sources($user, $workspace);
        $rooms = $workspace ? Room::whereHas('property', fn ($q) => $q->where('workspace_id', $workspace->id))->get(['id', 'property_id']) : collect();

        return $sources['catalog']->filter(fn ($p) => $workspace ? ($this->evaluate($user, $sources, $p->code, $workspace) || $rooms->contains(fn ($r) => $this->evaluate($user, $sources, $p->code, $workspace, $r->property_id, $r->id))) : ($this->accountAllowed($sources, $p->code, false) || $this->accountAllowed($sources, $p->code, true)))->keys()->all();
    }

    /** Self-service explanation reuses this evaluator with one authority source at a time. */
    public function explanation(User $user,?Workspace $workspace=null):array
    {
        $workspace=$workspace?->fresh();$sources=$this->sources($user,$workspace);$candidates=[];
        $empty=$sources;$empty['platform']=[];$empty['personal']=[];$empty['assignments']=collect();$empty['grants']=collect();
        if($empty['member']){$m=clone $empty['member'];$m->permissions=[];$m->setRelation('group',null);$empty['member']=$m;}
        $primary=User::find($user->id)?->access_group_id;
        $accountGroups=GroupAssignment::with('group')->where('user_id',$user->id)->whereNull('workspace_id')->whereNull('property_id')->whereNull('room_id')->get();
        foreach($accountGroups as$a){$g=$a->group;if(!$g||!in_array($g->scope,['global','personal'],true))continue;$one=$empty;
            if($sources['assignments']->contains('id',$a->id)){$one['platform']=$g->scope==='global'?PermissionCodes::expand($g->permissions):[];$one['personal']=PermissionCodes::expand($g->permissions);}
            $candidates[]=[$g->name,$g->permissions,$one,'Cấp cho tài khoản'];
        }
        if($primary&&($g=AccessGroup::find($primary))&&in_array($g->scope,['global','personal'],true)){
            $one=$empty;if($g->active){$one['platform']=$g->scope==='global'?PermissionCodes::expand($g->permissions):[];$one['personal']=PermissionCodes::expand($g->permissions);}$candidates[]=[$g->name,$g->permissions,$one,'Cấp cho tài khoản'];
        }
        if($workspace){
            if($member=$sources['member']){$one=$empty;$one['member']=$member;$one['grants']=$sources['grants']->filter(fn($g)=>$g->origin_kind==='membership'&&$g->origin_id===$member->id);$codes=$member->permissions_mode==='additive'?[...($member->group?->permissions??[]),...($member->permissions??[])]:($member->permissions??$member->group?->permissions??[]);$candidates[]=['Phân công trong lần tham gia',$codes,$one,'Theo nhà và phòng được giao'];}
            foreach(GroupAssignment::with('group')->where('user_id',$user->id)->where('workspace_id',$workspace->id)->get()as$a){$g=$a->group;if(!$g)continue;$one=$empty;$one['assignments']=$sources['assignments']->where('id',$a->id);$one['grants']=$sources['grants']->filter(fn($grant)=>$grant->origin_kind==='group'&&$grant->origin_id===$a->id);$candidates[]=[$g->name,$g->permissions,$one,'Theo phạm vi của nhóm được giao'];}
        }
        $rooms=$workspace?Room::whereHas('property',fn($q)=>$q->where('workspace_id',$workspace->id))->get(['id','property_id']):collect();$properties=$workspace?->properties()->pluck('id')??collect();$result=[];
        $policies=Permission::all()->keyBy('code');
        foreach($candidates as[$name,$codes,$one,$scope]){$yes=[];$no=[];
            foreach(PermissionCodes::expand($codes)as$code){$p=$policies->get($code);if(!$p)continue;
                $valid=$workspace?($this->evaluate($user,$one,$code,$workspace)||$properties->contains(fn($id)=>$this->evaluate($user,$one,$code,$workspace,$id))||$rooms->contains(fn($r)=>$this->evaluate($user,$one,$code,$workspace,$r->property_id,$r->id))):($this->accountAllowed($one,$code,false)||$this->accountAllowed($one,$code,true));
                if($valid)$yes[]=$p->name;else$no[]=$p->name;
            }
            if($yes||$no)$result[]=['name'=>$name,'effective'=>$yes,'blocked'=>$no,'scope'=>$scope,'reason'=>$no?'Các quyền chưa dùng được cần thỏa đồng thời trạng thái tài khoản, lần tham gia, nhóm, thời hạn, phạm vi và gói dịch vụ.':'Quyền đang dùng được trong phạm vi đã giao.'];
        }
        return $result;
    }
}
