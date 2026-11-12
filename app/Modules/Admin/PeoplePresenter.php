<?php

namespace App\Modules\Admin;

use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\AccessControl\AccountPolicy;
use App\Modules\AccessControl\DelegationPolicy;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\Models\PermissionCategory;
use App\Modules\DataPlatform\Models\AuditEntry;
use App\Support\PeoplePresentation;
use App\Support\ProductPresenter;

final class PeoplePresenter extends ProductPresenter
{
    use PeoplePresentation;
    use \App\Support\GroupChangePresentation;

    protected function build(string $slug, string $q): void
    {
        $section = $this->people($slug);
        if ($slug === 'admin-accounts') {
            $this->accounts($q);
            if($this->access->global($this->user,'workspace.transfer.review')&&\App\Modules\Workspace\Models\OwnerTransferCase::whereNotIn('status',['completed','cancelled','rejected'])->exists())$this->vm['links'][]=$this->link('Hồ sơ chuyển quyền quản lý','admin-dashboard',['section'=>'ownership']);

            return;
        }
        $permissions = app(DelegationPolicy::class)->options($this->user);
        $workspacePermissions = Permission::whereIn('code', array_keys($permissions))->whereIn('code', array_keys(array_filter(config('authorization.catalog'), fn ($p) => in_array('workspace', $p['scopes'], true))))->where('delegable', true)->pluck('name', 'code')->all();
        $groups = AccessGroup::whereNull('workspace_id')->get()->filter(fn ($g) => ! array_diff($g->permissions, array_keys($permissions)));
        $groupNames = AccessGroup::pluck('name', 'id');
        if ($section === 'history') {
            $this->records(AuditEntry::whereNull('workspace_id')->whereIn('action', array_keys(config('people.audit_labels')))->latest(), fn ($a) => $this->card(config('people.audit_labels')[$a->action], User::find($a->actor_id)?->name ?? 'Hệ thống', [
                'Thời gian' => $a->created_at->format('d/m/Y H:i'), 'Nội dung' => $a->changes['summary'] ?? $a->changes['after']['name'] ?? $a->changes['after']['reason'] ?? 'Đã ghi nhận thay đổi.',
            ]));

            return;
        }
        if ($section === 'catalog') {
            foreach (\App\Modules\AccessControl\Models\PermissionChange::with('permission')->where('status', 'pending_review')->latest()->get() as $change) {
                $forms = [];
                if (app(\App\Modules\AccessControl\PermissionPolicyChanges::class)->canApprove($this->user,$change)) $forms[] = $this->form('Phê duyệt chính sách', 'permission.change.approve', [], $change->id);
                if (count($change->approvals) >= config('authorization.group_change.independent_approvals')) $forms[] = $this->form('Áp dụng chính sách đã duyệt', 'permission.change.apply', [], $change->id);
                $this->vm['records'][] = $this->card('Chờ duyệt: '.$change->permission->name, 'Thay đổi chính sách quyền', [
                    'Lý do' => $change->reason, 'Cho phép ủy quyền' => ($change->after['delegable'] ?? $change->before['delegable']) ? 'Có' : 'Không',
                    'Số cấp ủy quyền tối đa' => $change->after['max_depth'] ?? config('authorization.delegation.default_max_depth'),
                    'Nhân sự liên quan' => count($change->impact['members']), 'Quyền cấp riêng liên quan' => count($change->impact['grants']),
                    'Phê duyệt độc lập' => count($change->approvals).' / '.config('authorization.group_change.independent_approvals'),
                ], $forms) + ['_key'=>'permission_changes:'.$change->id];
            }
            $categories = PermissionCategory::where('active', true)->pluck('name', 'name')->all();
            $this->vm['forms'][] = $this->form('Thêm danh mục quyền', 'category.save', [$this->field('name', 'Tên danh mục')]);
            foreach (PermissionCategory::orderBy('name')->get() as $category) {
                $forms = [$this->form('Đổi tên danh mục', 'category.save', [$this->field('name', 'Tên danh mục', value: $category->name)], $category->id)];
                foreach (Permission::where('category_id', $category->id)->whereNotIn('code', array_keys(config('authorization.legacy')))->orderBy('name')->get() as $permission) {
                    $forms[] = $this->form('Chỉnh sửa: '.$permission->name, 'permission.save', [
                        $this->field('code', 'Quyền', 'hidden', value: $permission->code),
                        $this->field('name', 'Tên quyền', value: $permission->name),
                        $this->field('description', 'Mô tả', 'textarea', value: $permission->description, required: false),
                        $this->field('category', 'Danh mục', 'select', $categories, $category->name),
                        $this->field('delegable', 'Cho phép ủy quyền', 'select', [1 => 'Có', 0 => 'Không'], (int) $permission->delegable),
                        $this->field('max_depth', 'Số cấp ủy quyền tối đa (bỏ trống dùng mặc định)', 'number', value: $permission->max_depth, required: false),
                        $this->field('active', 'Trạng thái', 'select', [1 => 'Đang sử dụng', 0 => 'Tạm ngừng'], (int) $permission->active),
                        $this->field('reason', 'Lý do thay đổi', 'textarea'),
                    ], $permission->id);
                }
                $this->vm['records'][] = $this->card($category->name, 'Danh mục quyền', ['Số quyền' => count($forms) - 1], $forms) + ['_key'=>'permission_categories:'.$category->id];
            }

            return;
        }
        if ($section === 'groups') {
            $this->vm['forms'][] = $this->form('Tạo nhóm quyền mẫu', 'group.save', [
                $this->field('name', 'Tên nhóm'), $this->field('scope', 'Phạm vi', 'select', ['global' => 'Toàn nền tảng', 'personal' => 'Chức năng cá nhân'] + ($workspacePermissions ? ['workspace' => 'Nhân viên nhà trọ'] : []), 'global'),
                $this->field('permissions', 'Quyền trong nhóm', 'permissions', $permissions, [], false),
            ]);
            $records = $this->groupChanges();
            foreach ($groups as $group) {
                $records[] = $this->card($group->name, $group->scope === 'global' ? 'Tài khoản hệ thống' : 'Nhân viên nhà trọ', ['Số quyền' => count($group->permissions)], [
                    $this->form('Xem trước thay đổi', 'group.save', [
                        $this->field('name', 'Tên nhóm', value: $group->name), $this->field('scope', 'Phạm vi', 'hidden', value: $group->scope),
                        $this->field('permissions', 'Quyền trong nhóm', 'permissions', $group->scope === 'workspace' && $group->key !== config('authorization.owner_group') ? $workspacePermissions : $permissions, $group->permissions, false),
                        $this->field('status', 'Trạng thái', 'select', ['active' => 'Đang sử dụng', 'retired' => 'Ngừng mở rộng', 'disabled' => 'Tạm ngừng'], $group->status),
                    ], $group->id),
                    $this->form('Tạm ngừng ngay', 'group.emergency_disable', [$this->field('reason', 'Lý do', 'textarea')], $group->id),
                ]) + ['_key'=>'access_groups:'.$group->id] + ['state' => $group->status === 'retired' ? 'retired' : ($group->active ? 'active' : 'inactive'), 'state_label' => ['active' => 'Đang sử dụng', 'retired' => 'Ngừng mở rộng', 'disabled' => 'Tạm ngừng'][$group->status], 'permissions' => collect($group->permissions)->map(fn ($p) => $permissions[$p])->all()];
            }
            $this->vm['records'] = $this->filterPeople($records, $q, ['active' => 'Đang sử dụng', 'retired' => 'Ngừng mở rộng', 'inactive' => 'Tạm ngừng']);

            return;
        }
        $targets = User::where('status', 'active')->get()->filter(fn ($target) => $target->id !== $this->user->id && ! array_diff($this->access->effectiveCodes($target), array_keys($permissions)));
        $groups = $groups->where('status', 'active');
        $users = $targets->mapWithKeys(fn ($u) => [$u->id => $u->name.' · '.$u->email])->all();
        $this->vm['forms'][] = $this->form('Gán nhóm cho tài khoản', 'account.group', [
            $this->field('user_id', 'Tài khoản', 'select', $users), $this->field('access_group_ids', 'Nhóm quyền', 'groups', $groups->whereIn('scope', ['global', 'personal'])->where('active', true)->mapWithKeys(fn ($g) => [$g->id => ['name' => $g->name, 'permissions' => $g->permissions]])->all(), [], false), $this->field('valid_until', 'Hết hạn các nhóm được gán (không bắt buộc)', 'datetime-local', required: false),
        ]);
        $grantPermissions = Permission::whereIn('code', array_keys($permissions))->where('active', true)->where('delegable', true)->pluck('name', 'code')->all();
        $this->vm['forms'][] = $this->form('Cấp quyền có thời hạn', 'global.grant', [
            $this->field('user_id', 'Tài khoản', 'select', $users), $this->field('scope_type', 'Phạm vi', 'select', ['auto' => 'Theo từng quyền', 'personal' => 'Chức năng và dữ liệu cá nhân', 'platform' => 'Toàn nền tảng'], 'auto'), $this->field('permissions', 'Quyền được cấp', 'permissions', $grantPermissions, [], false),
            $this->field('valid_from', 'Bắt đầu', 'datetime-local', value: now()->format('Y-m-d\TH:i')), $this->field('valid_until', 'Kết thúc', 'datetime-local'), $this->field('reason', 'Lý do', 'textarea'),
        ]);
        $records = [];
        foreach ($targets as $target) {
            $accountGroups = $groups->whereIn('scope', ['global', 'personal'])->where('active', true)->pluck('name', 'id')->all();
            $individual = [$this->form('Gán nhóm bổ sung', 'group.assignment.save', $this->assignmentFields($target->id, $accountGroups)), ...$this->assignmentCards($target->id, $accountGroups)];
            $effective = collect($permissions)->only($this->access->effectiveCodes($target))->values()->all();
            $records[] = $this->card($target->name, $target->email, ['Nhóm hiện tại' => collect([$groupNames[$target->access_group_id] ?? null])->merge(app(AccessService::class)->assignments($target)->whereNull('workspace_id')->pluck('group.name'))->filter()->unique()->implode(', '), 'Số quyền hiệu lực' => count($effective)], [$this->form('Chỉnh sửa nhóm quyền', 'account.group', [$this->field('user_id', 'Tài khoản', 'hidden', value: $target->id), $this->field('access_group_ids', 'Nhóm quyền', 'groups', $groups->whereIn('scope', ['global', 'personal'])->where('active', true)->mapWithKeys(fn ($g) => [$g->id => ['name' => $g->name, 'permissions' => $g->permissions]])->all(), array_values(array_unique(array_filter([$target->access_group_id, ...GroupAssignment::where('user_id', $target->id)->whereNull('workspace_id')->whereNull('revoked_at')->pluck('access_group_id')->all()]))), false), $this->field('valid_until', 'Hết hạn các nhóm được gán (không bắt buộc)', 'datetime-local', required: false)]), ...$individual]) + ['_key'=>'users:'.$target->id] + ['permissions' => $effective];
        }
        foreach (DirectGrant::whereNull('workspace_id')->whereIn('permission', array_keys($permissions))->latest()->get() as $g) {
            $state = $g->revoked_at ? 'revoked' : ($g->valid_until?->lte(now()) ? 'expired' : ($g->valid_from->isFuture() ? 'scheduled' : (app(\App\Modules\AccessControl\GrantGraph::class)->usable($g) ? 'active' : 'blocked')));
            $records[] = $this->card($permissions[$g->permission], User::find($g->user_id)?->email ?? '', ['Người cấp' => User::find($g->grantor_id)?->name ?? 'Chưa đối chiếu', 'Áp dụng' => $g->scope_type === 'personal' ? 'Chức năng cá nhân' : 'Toàn nền tảng', 'Bắt đầu' => $g->valid_from->format('d/m/Y H:i'), 'Kết thúc' => $g->valid_until?->format('d/m/Y H:i') ?? 'Không giới hạn', 'Lý do' => $g->reason],
                in_array($state, ['active', 'scheduled', 'blocked']) ? [$this->form('Thu hồi quyền', 'grant.revoke', [], $g->id) + ['confirmation' => 'Quyền bổ sung sẽ ngừng hiệu lực. Các quyền khác vẫn được giữ.']] : []) + ['_key'=>'direct_grants:'.$g->id] + ['state' => $state, 'state_label' => ['active' => 'Đang hiệu lực', 'scheduled' => 'Chưa đến hạn', 'expired' => 'Hết hạn', 'revoked' => 'Đã thu hồi', 'blocked' => 'Tạm ngừng hiệu lực'][$state]];
        }
        $this->vm['records'] = $this->filterPeople($records, $q);
    }

    private function accounts(string $q): void
    {
        $status = $this->request->validate(['status' => 'nullable|in:active,locked'])['status'] ?? '';
        $this->vm['stats'] = ['Tổng tài khoản' => User::count(), 'Đang hoạt động' => User::where('status', 'active')->count(), 'Đã khóa' => User::where('status', 'locked')->count()];
        $this->vm['people']['search'] = true;
        $this->vm['people']['filters'] = ['active' => 'Đang hoạt động', 'locked' => 'Đã khóa'];
        $this->records(User::where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%'))->when($status, fn ($query) => $query->where('status', $status))->latest(), function ($u) {
            $forms = [];
            if (app(AccountPolicy::class)->canChangeStatus($this->user, $u)) {
                $forms[] = $this->form($u->status === 'locked' ? 'Mở khóa tài khoản' : 'Khóa tài khoản', 'account.status', [
                    $this->field('status', 'Trạng thái', 'hidden', value: $u->status === 'locked' ? 'active' : 'locked'), $this->field('reason', 'Lý do thay đổi', 'textarea'),
                ], $u->id) + ['confirmation' => $u->status === 'locked' ? 'Tài khoản sẽ được phép đăng nhập trở lại.' : 'Tài khoản sẽ ngừng được phép truy cập. Dữ liệu và lịch sử được giữ lại.'];
            }

            if ($this->access->global($this->user, 'system.configure') && \App\Modules\Workspace\Models\Workspace::where('owner_id',$u->id)->exists()) {
                $forms[]=$this->form('Hạn chế nghiệp vụ của chủ trọ','account.restrict',[$this->field('owner_id','Tài khoản','hidden',value:$u->id),$this->field('cause','Mã hồ sơ xử lý'),$this->field('reason','Căn cứ hạn chế','textarea')]);
                foreach(\Illuminate\Support\Facades\DB::table('account_restrictions')->where('owner_id',$u->id)->whereNull('released_at')->get() as $r)$forms[]=$this->form('Gỡ hạn chế: '.$r->reason,'account.release',[$this->field('reason','Kết quả xử lý','textarea')],$r->id);
                foreach (\App\Modules\Workspace\Models\Workspace::where('owner_id', $u->id)->get() as $w) {
                    $forms[] = $this->form('Hạn chế khu: '.$w->name, 'workspace.restrict', [$this->field('target_workspace_id', 'Khu quản lý', 'hidden', value: $w->id), $this->field('cause', 'Mã hồ sơ xử lý'), $this->field('reason', 'Lý do hạn chế', 'textarea')]);
                    foreach (\App\Modules\Workspace\Models\WorkspaceRestriction::where('workspace_id', $w->id)->whereNull('released_at')->get() as $restriction) $forms[] = $this->form('Gỡ hạn chế: '.$restriction->reason, 'workspace.release', [$this->field('reason', 'Kết quả xử lý', 'textarea')], $restriction->id);
                }
            }
            return $this->card($u->name, $u->email, ['Tham gia ngày' => $u->created_at?->format('d/m/Y') ?? '—'], $forms) + ['state' => $u->status, 'state_label' => $u->status === 'locked' ? 'Đã khóa' : 'Đang hoạt động'];
        });
    }
}
