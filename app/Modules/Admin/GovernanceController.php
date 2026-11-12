<?php

namespace App\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Modules\AccessControl\GovernanceService;
use App\Modules\Roommate\RoommateService;
use App\Modules\Trust\TrustService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class GovernanceController extends Controller
{
    public function action(Request $request, string $action)
    {
        if ($request->has('_groups_present')) {
            $request->merge(['access_group_ids' => $request->input('access_group_ids', [])]);
        }
        if ($action === 'group.save') {
            $request->merge(['permissions' => $request->input('permissions', [])]);
        }
        $rules = match ($action) {
            'report.account' => ['email' => 'required|email|max:254', 'reason' => 'required|string|min:5|max:2000'],
            'report.create' => ['target_type' => 'required|in:listing,account', 'target_id' => 'required|integer', 'reason' => 'required|string|min:5|max:2000'],
            'report.resolve' => ['status' => 'required|in:under_review,resolved,rejected', 'resolution' => 'required|string|max:2000'],
            'listing.moderate' => ['reason' => 'required|string|max:2000'],
            'account.status' => ['status' => 'required|in:active,locked', 'reason' => 'required|string|max:2000'],
            'permission.save' => ['code' => 'required|regex:/^[a-z]+[a-z._]*$/|max:100', 'name' => 'required|string|max:100', 'category' => 'required|string|max:100', 'delegable' => 'required|boolean', 'max_depth' => 'nullable|integer|min:1|max:'.config('authorization.delegation.max_supported_depth'), 'description' => 'nullable|string|max:2000', 'active' => 'sometimes|boolean', 'reason' => 'sometimes|string|min:5|max:2000'],
            'category.save' => ['name' => 'required|string|max:100'],
            'group.save' => ['name' => 'required|string|max:100', 'scope' => 'required|in:global,personal,workspace', 'status' => 'sometimes|in:active,retired,disabled', 'active' => 'sometimes|boolean', 'permissions' => 'present|array', 'permissions.*' => 'required|exists:permissions,code'],
            'account.group' => ['user_id' => 'required|integer|exists:users,id', 'access_group_id' => [Rule::requiredIf(! $request->has('access_group_ids')), 'nullable', 'integer', 'exists:access_groups,id'], 'access_group_ids' => 'sometimes|array', 'access_group_ids.*' => 'integer|distinct|exists:access_groups,id', 'valid_until' => 'nullable|date|after:now'],
            'global.grant' => ['scope_type' => 'sometimes|in:auto,personal,platform', 'source_grant_id' => 'nullable|integer', 'user_id' => 'required|exists:users,id', 'permission' => 'required_without:permissions|exists:permissions,code', 'permissions' => 'required_without:permission|array|min:1', 'permissions.*' => 'required|string|distinct|exists:permissions,code', 'valid_from' => 'required|date', 'valid_until' => 'required|date|after:valid_from', 'reason' => 'required|string|max:1000'],
            'review.create' => ['rental_id' => 'required|integer', 'rating' => 'required|integer|between:1,5', 'comment' => 'required|string|max:2000'],
            'roommate.save' => ['mode' => 'sometimes|in:has_room,search_together', 'room_id' => 'nullable|integer|min:1', 'budget_basis' => 'sometimes|in:per_person,total', 'people_needed' => 'sometimes|integer|min:1|max:20', 'duration_months' => 'nullable|integer|min:1|max:120', 'district' => 'required|string|max:100', 'budget' => 'required|integer|min:0|max:100000000', 'move_in' => 'required|date', 'lifestyle' => 'required|string|max:2000', 'expires_at' => 'required|date|after:today'],
            'roommate.close' => [],
            'roommate.connect' => [],
            'roommate.respond' => ['status' => 'required|in:accepted,rejected'],
            default => abort(404),
        };
        $service = match ($action) {
            'report.account','report.create','report.resolve','listing.moderate','review.create' => TrustService::class,
            'roommate.save','roommate.close','roommate.connect','roommate.respond' => RoommateService::class,
            'account.status','permission.save','category.save','group.save','account.group','global.grant' => GovernanceService::class,
        };
        $data = $request->validate($rules);
        if ($action === 'global.grant') {
            DB::transaction(function () use ($request, $data, $action, $service) {
                $codes = $data['permissions'] ?? [$data['permission']];
                unset($data['permissions']);
                foreach ($codes as $code) {
                    app($service)->execute($request->user(), $action, 0, array_replace($data, ['permission' => $code]));
                }
            });
        } else {
            app($service)->execute($request->user(), $action, (int) $request->input('id'), $data);
        }

        return \App\Support\UiRedirect::success($request, $action === 'permission.save' ? 'Đã ghi nhận. Thay đổi mở rộng quyền cần được phê duyệt trước khi áp dụng.' : ($action === 'group.save' && $request->integer('id') ? 'Đã tạo bản xem trước thay đổi. Hãy kiểm tra tác động và phê duyệt trước khi áp dụng.' : 'Đã lưu thay đổi.'));
    }
}
