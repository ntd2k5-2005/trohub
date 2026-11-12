<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use Illuminate\Support\Carbon;

final class AccountAuthoritySources
{
    public function select(User $actor, string $code, string $scope, string $from, string $until, ?int $parent = null): array
    {
        $candidates = [];
        $primary = AccessGroup::find(User::whereKey($actor->id)->value('access_group_id'));
        if ($primary && ! $primary->workspace_id) $candidates[] = ['kind' => 'platform', 'id' => $primary->id, 'group' => $primary, 'from' => null, 'until' => null];
        foreach (GroupAssignment::with('group')->where('user_id', $actor->id)->whereNull('workspace_id')->whereNull('property_id')->whereNull('room_id')->whereNull('revoked_at')->get() as $a) {
            if ($a->valid_from?->gt(now()) || $a->valid_until?->lte(now()) || $a->group?->workspace_id) continue;
            $candidates[] = ['kind' => 'assignment', 'id' => $a->id, 'group' => $a->group, 'from' => $a->valid_from, 'until' => $a->valid_until];
        }
        foreach ($candidates as $source) {
            $g = $source['group'];
            if ($parent !== null || ! $g?->active || $g->status === 'retired' || ! in_array($g->scope, $scope === 'platform' ? ['global'] : ['global', 'personal'], true) || ! in_array($code, PermissionCodes::expand($g->permissions), true)) continue;
            if ($source['from'] && Carbon::parse($from)->lt($source['from']) || $source['until'] && Carbon::parse($until)->gt($source['until'])) continue;
            return ['kind' => $source['kind'], 'id' => $source['id'], 'depth' => 0, 'snapshot' => ['group_id' => $g->id, 'scope_type' => $g->scope === 'global' ? 'platform' : 'personal', 'from' => $source['from']?->toIso8601String(), 'until' => $source['until']?->toIso8601String(), 'properties' => null, 'rooms' => null]];
        }
        foreach (DirectGrant::where('user_id', $actor->id)->whereNull('workspace_id')->where('authority_kind', '!=', 'legacy')->whereNull('revoked_at')->get() as $g) {
            if ($parent !== null && $g->id !== $parent || $g->permission !== $code || $scope === 'platform' && $g->scope_type !== 'platform' || ! app(GrantGraph::class)->usable($g) || app(AuthoritySources::class)->retiredRoot($g)) continue;
            if (Carbon::parse($from)->lt($g->valid_from) || $g->valid_until && Carbon::parse($until)->gt($g->valid_until)) continue;
            return ['kind' => 'grant', 'id' => $g->id, 'depth' => $g->depth, 'snapshot' => ['group_id' => null, 'scope_type' => $g->scope_type, 'from' => $g->valid_from->toIso8601String(), 'until' => $g->valid_until?->toIso8601String(), 'properties' => null, 'rooms' => null]];
        }
        abort(403, 'Không có nguồn cấp quyền còn hiệu lực cho phạm vi và thời hạn này.');
    }
}
