<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\DataPlatform\Journal;
use App\Modules\Subscription\PropertyCloud;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Schema;

final class DirectGrantService
{
    public function issue(User $actor, Workspace $workspace, array $data): DirectGrant
    {
        $data = \Illuminate\Support\Facades\Validator::make($data, ['user_id' => 'required|integer|exists:users,id', 'permission' => 'required|string', 'property_id' => 'nullable|integer', 'room_id' => 'nullable|integer', 'source_grant_id' => 'nullable|integer', 'valid_from' => 'required|date', 'valid_until' => 'nullable|date|after:valid_from', 'reason' => 'required|string|max:2000'])->validate();
        abort_unless(Schema::hasColumn('direct_grants', 'source_kind'), 503, 'Phân quyền đang chờ cập nhật dữ liệu an toàn.');
        [$property, $room] = ResourceScope::target($workspace, ! empty($data['property_id']) ? (int) $data['property_id'] : null, ! empty($data['room_id']) ? (int) $data['room_id'] : null);
        $permission = Permission::where('code', $data['permission'])->where('active', true)->where('delegable', true)->firstOrFail();
        abort_unless(in_array($room ? 'room' : ($property ? 'property' : 'workspace'), $permission->scopes ?? [], true), 403);
        abort_if($actor->id === (int) $data['user_id'], 403);
        $authority = function () use ($actor, $workspace, $permission, $property, $room) {
            app(AccessService::class)->authorize($actor, $permission->code, $workspace, $property, $room);
            app(AccessService::class)->authorize($actor, 'member.delegate', $workspace, $property, $room);
        };
        return app(PropertyCloud::class)->transaction($workspace->id, function () use ($actor, $workspace, $data, $permission, $property, $room) {
            Permission::whereIn('code', [$permission->code, 'member.delegate'])->orderBy('id')->lockForUpdate()->get();
            $recipient = Membership::where('workspace_id', $workspace->id)->where('user_id', $data['user_id'])->where('status', 'active')->lockForUpdate()->firstOrFail();
            abort_unless(! $recipient->valid_until || ! empty($data['valid_until']) && \Illuminate\Support\Carbon::parse($data['valid_until'])->lte($recipient->valid_until), 403);
            abort_unless(ResourceScope::fits($recipient->property_ids, $recipient->room_ids, $property, $room), 403, 'Phạm vi cấp quyền vượt phạm vi làm việc của người nhận.');
            $sources = app(AuthoritySources::class);
            $use = $sources->select($actor, $workspace, $permission->code, $property, $room, $data['valid_until'] ?? null, isset($data['source_grant_id']) ? (int) $data['source_grant_id'] : null);
            $delegate = $sources->select($actor, $workspace, 'member.delegate', $property, $room, $data['valid_until'] ?? null);
            app(GrantGraph::class)->lockAuthorities($actor, [$use, $delegate]);
            foreach ([$use, $delegate] as $source) abort_if(! empty($source['snapshot']['from']) && \Illuminate\Support\Carbon::parse($data['valid_from'])->lt(\Illuminate\Support\Carbon::parse($source['snapshot']['from'])), 403, 'Thời điểm bắt đầu không được sớm hơn nguồn cấp quyền.');
            $depth = max($use['depth'], $delegate['depth']) + 1;
            abort_unless($depth <= app(GrantGraph::class)->depthLimit($permission->code), 403, 'Quyền này không cho phép ủy quyền sâu hơn mức hiện tại.');
            app(GrantGraph::class)->assertAcyclic((int) $data['user_id'], [$use, $delegate]);
            $issuer = $use['member'] ?? $delegate['member'];
            $kind = $use['kind'] === 'ownership' && ! $issuer ? 'organization' : ($issuer ? 'personal' : 'platform');
            $grant = DirectGrant::create([
                'user_id' => $recipient->user_id, 'membership_id' => $recipient->id, 'workspace_id' => $workspace->id,
                'property_id' => $property, 'room_id' => $room, 'permission' => $permission->code,
                'grantor_id' => $actor->id, 'issuer_membership_id' => $issuer, 'authority_kind' => $kind,
                'source_kind' => $use['kind'], 'source_id' => $use['id'], 'source_snapshot' => $use['snapshot'],
                'delegation_source_kind' => $delegate['kind'], 'delegation_source_id' => $delegate['id'], 'delegation_snapshot' => $delegate['snapshot'],
                'depth' => $depth, 'valid_from' => $data['valid_from'], 'valid_until' => $data['valid_until'] ?? null, 'reason' => $data['reason'],
            ]);
            abort_unless(app(GrantGraph::class)->issuable($grant), 403);
            app(Journal::class)->record($actor->id, 'permission.granted', $grant, $workspace->id, ['membership_id' => $recipient->id, 'issuer_membership_id' => $grant->issuer_membership_id, 'source_kind' => $use['kind'], 'source_id' => $use['id'], 'depth' => $depth]);
            abort_unless(app(GrantGraph::class)->issuable($grant->fresh()), 403);
            return $grant;
        }, $authority);
    }

}
