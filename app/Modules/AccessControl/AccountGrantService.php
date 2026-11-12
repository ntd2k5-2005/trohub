<?php

namespace App\Modules\AccessControl;

use App\Models\User;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\DataPlatform\Journal;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class AccountGrantService
{
    public function issue(User $actor, array $input): DirectGrant
    {
        $data = Validator::make($input, ['user_id' => 'required|integer|exists:users,id', 'permission' => 'required|string', 'scope_type' => 'sometimes|in:auto,personal,platform', 'source_grant_id' => 'nullable|integer', 'valid_from' => 'required|date', 'valid_until' => 'required|date|after:valid_from', 'reason' => 'required|string|max:1000'])->validate();
        abort_unless(Schema::hasColumn('direct_grants', 'source_kind'), 503, 'Phân quyền đang chờ cập nhật dữ liệu an toàn.');
        abort_if($actor->id === (int) $data['user_id'], 403, 'Không thể tự cấp quyền.');
        $p = Permission::where('code', $data['permission'])->where('active', true)->firstOrFail();
        abort_unless($p->delegable, 403, 'Quyền này không được cấp lại bằng quyền riêng.');
        $scope = $data['scope_type'] ?? 'auto';
        if ($scope === 'auto') $scope = in_array('personal', $p->scopes ?? [], true) ? 'personal' : 'platform';
        abort_unless(in_array($scope, $p->scopes ?? [], true), 403);
        return app(RootProtection::class)->mutation(function () use ($actor, $data, $p, $scope) {
            $authority = function () use ($actor, $p, $scope) {
                $access = app(AccessService::class);
                abort_unless($access->global($actor, 'access.delegate'), 403);
                abort_unless($scope === 'platform' ? $access->global($actor, $p->code) : $access->personal($actor, $p->code), 403);
            };
            $authority();
            $target = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            abort_unless($target->status === 'active', 403);
            $selector = app(AccountAuthoritySources::class);
            $use = $selector->select($actor, $p->code, $scope, $data['valid_from'], $data['valid_until'], isset($data['source_grant_id']) ? (int) $data['source_grant_id'] : null);
            $delegate = $selector->select($actor, 'access.delegate', 'platform', $data['valid_from'], $data['valid_until']);
            $depth = max($use['depth'], $delegate['depth']) + 1;
            abort_unless($depth <= app(GrantGraph::class)->depthLimit($p->code), 403, 'Đã đạt số cấp ủy quyền cho phép.');
            app(GrantGraph::class)->assertAcyclic($target->id, [$use, $delegate]);
            $grant = DirectGrant::create(['user_id' => $target->id, 'permission' => $p->code, 'scope_type' => $scope, 'grantor_id' => $actor->id, 'authority_kind' => 'platform', 'source_kind' => $use['kind'], 'source_id' => $use['id'], 'source_snapshot' => $use['snapshot'], 'delegation_source_kind' => $delegate['kind'], 'delegation_source_id' => $delegate['id'], 'delegation_snapshot' => $delegate['snapshot'], 'depth' => $depth, 'valid_from' => $data['valid_from'], 'valid_until' => $data['valid_until'], 'reason' => $data['reason']]);
            abort_unless(app(GrantGraph::class)->issuable($grant), 403);
            app(Journal::class)->record($actor->id, 'global.grant', $grant, null, ['summary' => $data['reason'], 'source_kind' => $use['kind'], 'source_id' => $use['id'], 'delegation_source_kind' => $delegate['kind'], 'delegation_source_id' => $delegate['id'], 'depth' => $depth, 'scope_type' => $scope]);
            $authority();
            abort_unless(app(GrantGraph::class)->issuable($grant->fresh()), 403);
            return $grant;
        });
    }
}
