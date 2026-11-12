<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\DataPlatform\Models\AuditEntry;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Authoritative dependency graph. Temporary blockers never rewrite permanent provenance. */
final class GrantGraph
{
    public function depthLimit(string $permission): int
    {
        return Permission::where('code', $permission)->value('max_depth') ?? config('authorization.delegation.default_max_depth', 1);
    }

    public function usable(DirectGrant $grant, bool $permanentOnly = false, array $seen = []): bool
    {
        if (isset($seen[$grant->id]) || $grant->revoked_at || in_array($grant->status, ['revoked', 'source_revoked'], true)) return false;
        $seen[$grant->id] = true;
        if ($grant->valid_until && $grant->valid_until->lte(now()) || ! $permanentOnly && $grant->valid_from->gt(now())) return false;
        if (! $grant->workspace_id) {
            if ($grant->authority_kind === 'legacy') return true;
            if ($grant->membership_id || $grant->property_id || $grant->room_id || ! in_array($grant->scope_type, ['personal', 'platform'], true)) return false;
            $policy = Permission::where('code', $grant->permission)->first();
            if (! $policy?->active || ! $policy->delegable || $grant->depth > $this->depthLimit($grant->permission) || ! in_array($grant->scope_type, $policy->scopes ?? [], true)) return false;
            $recipient = User::find($grant->user_id);
            if (! $recipient || ! $permanentOnly && $recipient->status !== 'active') return false;
            foreach ([[$grant->source_kind, $grant->source_id, $grant->source_snapshot, $grant->permission], [$grant->delegation_source_kind, $grant->delegation_source_id, $grant->delegation_snapshot, 'access.delegate']] as [$kind, $id, $snapshot, $code]) {
                if (! $this->sourceValid($kind, $id, $snapshot, $code, $grant, $permanentOnly, $seen)) return false;
            }
            return true;
        }
        $member = Membership::find($grant->membership_id);
        if (! $member || $member->status === 'ended' || $member->user_id !== $grant->user_id || $member->workspace_id !== $grant->workspace_id) return false;
        if (! $permanentOnly && ($member->status !== 'active' || $member->valid_until?->lte(now()))) return false;
        if ($grant->authority_kind === 'legacy') return true; // Existing use only; never a new delegating source.
        if ($member->valid_until && (! $grant->valid_until || $grant->valid_until->gt($member->valid_until))) return false;
        $policy = Permission::where('code', $grant->permission)->first();
        if (! $policy || ! $policy->active || ! $policy->delegable || $grant->depth > $this->depthLimit($grant->permission)) return false;
        if (! ResourceScope::fits($member->property_ids, $member->room_ids, $grant->property_id, $grant->room_id)) return false;
        if ($grant->origin_kind === 'membership') {
            if ($grant->origin_id !== $member->id || $member->permission_origin !== 'personal') return false;
            $codes = $member->permissions ?? $member->group?->permissions ?? [];
            if (! in_array($grant->permission, PermissionCodes::expand($codes), true)) return false;
            if ($member->permissions === null && ! $permanentOnly && ! $member->group?->active) return false;
        }
        if ($grant->origin_kind === 'group') {
            $binding = GroupAssignment::with('group')->find($grant->origin_id);
            if (! $binding || $binding->membership_id !== $member->id || $binding->revoked_at || $binding->permission_origin !== 'personal' || ! in_array($grant->permission, PermissionCodes::expand($binding->group?->permissions ?? []), true)) return false;
            if (! ResourceScope::fits($binding->property_id ? [$binding->property_id] : null, $binding->room_id ? [$binding->room_id] : null, $grant->property_id, $grant->room_id)) return false;
            if (! $permanentOnly && (! $binding->group?->active || $binding->valid_from?->gt(now()) || $binding->valid_until?->lte(now()))) return false;
        }
        $workspace = Workspace::find($grant->workspace_id);
        if (! $workspace) return false;
        try { ResourceScope::target($workspace, $grant->property_id, $grant->room_id); }
        catch (\Illuminate\Database\Eloquent\ModelNotFoundException | \Symfony\Component\HttpKernel\Exception\HttpException $e) { return false; }
        if ($grant->authority_kind === 'organization' && $workspace->owner_id !== $grant->grantor_id) return false;
        if ($grant->authority_kind === 'personal') {
            $issuer = Membership::find($grant->issuer_membership_id);
            if (! $issuer || $issuer->user_id !== $grant->grantor_id || $issuer->workspace_id !== $workspace->id || $issuer->status === 'ended') return false;
            if (! ResourceScope::fits($issuer->property_ids, $issuer->room_ids, $grant->property_id, $grant->room_id) || $issuer->valid_until && (! $grant->valid_until || $grant->valid_until->gt($issuer->valid_until))) return false;
            if (! $permanentOnly && ($issuer->status !== 'active' || $issuer->valid_until?->lte(now()))) return false;
        }
        foreach ([[$grant->source_kind, $grant->source_id, $grant->source_snapshot, $grant->permission], [$grant->delegation_source_kind, $grant->delegation_source_id, $grant->delegation_snapshot, 'member.delegate']] as [$kind, $id, $snapshot, $code]) {
            if (! $this->sourceValid($kind, $id, $snapshot, $code, $grant, $permanentOnly, $seen)) return false;
        }
        return true;
    }

    private function sourceValid(?string $kind, ?int $id, ?array $snapshot, string $code, DirectGrant $child, bool $permanentOnly, array $seen): bool
    {
        if (! $kind || ! $id || ! $snapshot) return false;
        if (! Permission::where('code', $code)->where('active', true)->exists()) return false;
        if (! empty($snapshot['from']) && $child->valid_from->lt(Carbon::parse($snapshot['from']))) return false;
        if (! ResourceScope::fits($snapshot['properties'] ?? null, $snapshot['rooms'] ?? null, $child->property_id, $child->room_id)) return false;
        if (! empty($snapshot['until']) && Carbon::parse($snapshot['until'])->lte(now())) return false;
        if (! empty($snapshot['until']) && (! $child->valid_until || $child->valid_until->gt(Carbon::parse($snapshot['until'])))) return false;
        if (! $permanentOnly && ! User::whereKey($child->grantor_id)->where('status', 'active')->exists()) return false;
        if ($kind === 'grant') {
            $parent = DirectGrant::find($id);
            return $parent && $parent->authority_kind !== 'legacy' && ($parent->workspace_id === $child->workspace_id || $parent->workspace_id === null && $parent->scope_type === 'platform' && $child->workspace_id !== null) && $parent->user_id === $child->grantor_id
                && (! $child->workspace_id ? $parent->scope_type === 'platform' || $child->scope_type === 'personal' : $parent->workspace_id !== null || $parent->scope_type === 'platform')
                && in_array($code, PermissionCodes::expand([$parent->permission]), true)
                && $child->valid_from->gte($parent->valid_from)
                && ResourceScope::fits($parent->property_id ? [$parent->property_id] : null, $parent->room_id ? [$parent->room_id] : null, $child->property_id, $child->room_id)
                && (! $parent->valid_until || $child->valid_until && $child->valid_until->lte($parent->valid_until))
                && $this->usable($parent, $permanentOnly, $seen);
        }
        if ($kind === 'membership') {
            $member = Membership::find($id);
            if (! $member || $member->permission_origin === 'personal' || isset($snapshot['revision']) && $snapshot['revision'] !== $member->source_revision || $member->status === 'ended' || $member->user_id !== $child->grantor_id || $member->workspace_id !== $child->workspace_id) return false;
            if ($member->valid_until && (! $child->valid_until || $child->valid_until->gt($member->valid_until))) return false;
            if (! $permanentOnly && ($member->status !== 'active' || $member->valid_until?->lte(now()))) return false;
            if (! empty($snapshot['group_id'])) {
                if ($member->access_group_id !== $snapshot['group_id'] || $member->permissions_mode !== 'additive' && $member->permissions !== null || ! $permanentOnly && ! $member->group?->active) return false;
                if ($member->group?->scope !== 'workspace' || $member->group->workspace_id !== null && $member->group->workspace_id !== $child->workspace_id) return false;
                $codes = $member->group?->permissions ?? [];
            } else $codes = $member->permissions ?? [];
            return in_array($code, PermissionCodes::expand($codes), true) && ResourceScope::fits($member->property_ids, $member->room_ids, $child->property_id, $child->room_id);
        }
        if (in_array($kind, ['assignment', 'ownership'], true)) {
            $a = GroupAssignment::with('group')->find($id);
            if (! $a || $a->permission_origin === 'personal' || $a->revoked_at || $a->user_id !== $child->grantor_id || $a->access_group_id !== ($snapshot['group_id'] ?? null)) return false;
            if ($a->valid_until?->lte(now())) return false;
            if ($a->valid_from && $child->valid_from->lt($a->valid_from)) return false;
            if ($a->valid_until && (! $child->valid_until || $child->valid_until->gt($a->valid_until))) return false;
            if (! $a->workspace_id && ! in_array($a->group?->scope, ! $child->workspace_id && $child->scope_type === 'personal' && $code !== 'access.delegate' ? ['global', 'personal'] : ['global'], true)) return false;
            if ($a->workspace_id && ($a->group?->scope !== 'workspace' || $a->group->workspace_id !== null && $a->group->workspace_id !== $child->workspace_id)) return false;
            if (! $permanentOnly && ($a->valid_from?->gt(now()) || ! $a->group?->active)) return false;
            if ($kind === 'ownership') {
                if ($a->origin !== 'ownership' || Workspace::whereKey($child->workspace_id)->value('owner_id') !== $a->user_id) return false;
            } elseif ($a->workspace_id && ($a->workspace_id !== $child->workspace_id || $a->membership_id !== $child->issuer_membership_id)) return false;
            return in_array($code, PermissionCodes::expand($a->group?->permissions ?? []), true)
                && ResourceScope::fits($a->property_id ? [$a->property_id] : null, $a->room_id ? [$a->room_id] : null, $child->property_id, $child->room_id);
        }
        if ($kind === 'platform') {
            $user = User::find($child->grantor_id);
            $group = AccessGroup::find($id);
            return $user && $user->access_group_id === $id && ! $group?->workspace_id && in_array($group?->scope, ! $child->workspace_id && $child->scope_type === 'personal' && $code !== 'access.delegate' ? ['global', 'personal'] : ['global'], true)
                && ($permanentOnly || $group->active) && in_array($code, PermissionCodes::expand($group->permissions), true);
        }
        return false;
    }

    public function assertAcyclic(int $recipient, array $sources): void
    {
        $queue = array_column(array_filter($sources, fn ($s) => $s['kind'] === 'grant'), 'id'); $seen = [];
        while ($queue) {
            $id = array_pop($queue);
            if (isset($seen[$id])) continue;
            $seen[$id] = true; $grant = DirectGrant::findOrFail($id);
            abort_if($grant->grantor_id === $recipient || $grant->user_id === $recipient, 403, 'Không thể tạo vòng ủy quyền.');
            if ($grant->source_kind === 'grant') $queue[] = $grant->source_id;
            if ($grant->delegation_source_kind === 'grant') $queue[] = $grant->delegation_source_id;
        }
    }

    public function lockAuthorities(User $actor, array $sources): void
    {
        // Grant creation and source mutation share these SQL rows. No process/cache mutex.
        $groups = array_values(array_unique(array_filter(array_column(array_column($sources, 'snapshot'), 'group_id')))); sort($groups);
        if ($groups) AccessGroup::whereIn('id', $groups)->orderBy('id')->lockForUpdate()->get();
        foreach (['assignment', 'ownership', 'membership', 'grant'] as $kind) {
            $ids = array_values(array_unique(array_column(array_filter($sources, fn ($s) => $s['kind'] === $kind), 'id'))); sort($ids);
            $model = match ($kind) { 'assignment', 'ownership' => GroupAssignment::class, 'membership' => Membership::class, 'grant' => DirectGrant::class };
            if ($ids) $model::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        }
        if (collect($sources)->contains(fn ($s) => $s['kind'] === 'platform')) User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
    }

    public function issuable(DirectGrant $grant): bool
    {
        // A scheduled grant may start later, but its pinned sources must be usable NOW.
        // Permanent-only validation intentionally ignores temporary suspension during reconciliation.
        if (! $this->usable($grant, true) || app(AuthoritySources::class)->retiredRoot($grant)) return false;
        $delegate = $grant->workspace_id ? 'member.delegate' : 'access.delegate';
        foreach ([[$grant->source_kind, $grant->source_id, $grant->source_snapshot, $grant->permission], [$grant->delegation_source_kind, $grant->delegation_source_id, $grant->delegation_snapshot, $delegate]] as [$kind, $id, $snapshot, $code]) {
            if (! $this->sourceValid($kind, $id, $snapshot, $code, $grant, false, [$grant->id => true])) return false;
        }
        return true;
    }

    public function reconcile(): void
    {
        if (! Schema::hasColumn('direct_grants', 'source_kind')) return;
        // Iterate until closure so dependent branches are terminated in the same transaction.
        do {
            $changed = false;
            foreach (DirectGrant::where('authority_kind', '!=', 'legacy')->whereNull('revoked_at')->orderBy('id')->get() as $grant) {
                if (! $this->usable($grant, true)) {
                    DB::table('direct_grants')->where('id', $grant->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'status' => 'source_revoked', 'revocation_reason' => 'Nguồn cấp quyền đã kết thúc hoặc bị thu hẹp', 'version' => $grant->version + 1, 'updated_at' => now()]);
                    AuditEntry::create(['workspace_id' => $grant->workspace_id, 'action' => 'permission.dependency_revoked', 'resource_type' => DirectGrant::class, 'resource_id' => $grant->id, 'changes' => ['source_kind' => $grant->source_kind, 'source_id' => $grant->source_id, 'membership_id' => $grant->membership_id]]);
                    $changed = true;
                }
            }
        } while ($changed);
    }
}
