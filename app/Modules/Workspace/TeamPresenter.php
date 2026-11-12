<?php

namespace App\Modules\Workspace;

use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\AccessControl\DelegationPolicy;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Support\PeoplePresentation;
use App\Support\ProductPresenter;

final class TeamPresenter extends ProductPresenter
{
    use PeoplePresentation;
    use \App\Support\GroupChangePresentation;

    protected function scopeField(?array $ids = null): array
    {
        $options = $this->propertyOptions('member.manage');
        $all = app(DelegationPolicy::class)->canWorkspace($this->user, $this->workspace, [], null, now()->toDateTimeString());

        return $this->field('property_ids', 'Nhà được giao', 'scope', $options, $ids ?? ($all ? null : array_keys($options)), false) + ['all_allowed' => $all];
    }

    protected function build(string $slug, string $q): void
    {
        $section = $this->people($slug);
        $repo = app(TeamRepository::class);
        $permissions = app(DelegationPolicy::class)->options($this->user, $this->workspace);
        $members = $repo->members($this->user, $this->workspace);
        $invitations = $repo->invitations($this->user, $this->workspace);
        $active = $members->filter(fn ($m) => $m->status === 'active' && (! $m->valid_until || $m->valid_until->isFuture()));
        $pending = $invitations->filter(fn ($i) => ! $i->accepted_at && ! $i->cancelled_at && ! $i->declined_at && $i->expires_at->isFuture() && (! $i->valid_until || $i->valid_until->isFuture()));
        $waiting = $members->where('status', 'pending')->count();
        $this->vm['stats'] = ['Đang làm việc' => $active->count(), 'Chờ kích hoạt' => $waiting, 'Lời mời đang chờ' => $pending->count(), 'Đã ngừng truy cập' => $members->count() - $active->count() - $waiting];
        if ($section === 'groups') {
            $this->vm['records'] = $this->groupChanges();
            $this->vm['forms'][] = $this->form('Tạo nhóm quyền', 'workspace.group.save', [$this->field('name', 'Tên nhóm'), $this->field('permissions', 'Quyền trong nhóm', 'permissions', $permissions, [], false)]);
            foreach (AccessGroup::where('workspace_id', $this->workspace->id)->where('scope', 'workspace')->get()->filter(fn ($g) => ! array_diff($g->permissions, array_keys($permissions))) as $g) {
                $this->vm['records'][] = $this->card($g->name, '', ['Quyền trong nhóm' => count($g->permissions), 'Trạng thái' => ['active' => 'Đang sử dụng', 'retired' => 'Ngừng mở rộng', 'disabled' => 'Tạm ngừng'][$g->status]], [$this->form('Xem trước thay đổi', 'workspace.group.save', [$this->field('name', 'Tên nhóm', value: $g->name), $this->field('permissions', 'Quyền trong nhóm', 'permissions', $permissions, $g->permissions, false), $this->field('status', 'Trạng thái', 'select', ['active' => 'Đang sử dụng', 'retired' => 'Ngừng mở rộng', 'disabled' => 'Tạm ngừng'], $g->status)], $g->id), $this->form('Tạm ngừng ngay', 'group.emergency_disable', [$this->field('reason', 'Lý do', 'textarea')], $g->id)]) + ['_key'=>'access_groups:'.$g->id];
            }

            return;
        }
        if ($section === 'handover') {
            foreach (\App\Modules\Workspace\Models\WorkAssignment::where('workspace_id', $this->workspace->id)->where('status', 'handover_pending')->get() as $assignment) {
                $spec = config('people.work_resources.'.$assignment->resource_type);
                if (! $spec) continue;
                $resource = $spec['model']::find($assignment->resource_id);
                $room = $resource?->room;
                if (! $room || $room->property->workspace_id !== $this->workspace->id || ! $this->access->allowed($this->user, $spec['view_permission'], $this->workspace, $room->property_id, $room->id)) continue;
                $this->vm['records'][] = $this->card($resource->title, $room->property->name.' · '.$room->name, ['Người phụ trách trước' => User::find($assignment->actor_id)?->name ?? 'Nhân viên cũ', 'Trạng thái' => 'Chờ tiếp nhận'], [$this->form('Tiếp nhận công việc', 'work.takeover', [], $assignment->id)], [$this->link($spec['link'], $spec['screen'], ['q' => $resource->title])]);
            }
            return;
        }
        if ($section === 'history') {
            $pager = $repo->history($this->user, $this->workspace);
            $this->vm['pagination'] = $pager;
            foreach ($pager as $entry) {
                $this->vm['records'][] = $this->card(config('people.audit_labels')[$entry->action], User::find($entry->actor_id)?->name ?? 'Hệ thống', ['Thời gian' => $entry->created_at->format('d/m/Y H:i'), 'Chi tiết' => $entry->changes['summary'] ?? $entry->changes['reason'] ?? 'Đã ghi nhận thay đổi.']);
            }

            return;
        }
        if (in_array($section, ['members', 'invitations'])) {
            $groups = AccessGroup::where('scope', 'workspace')->where('active', true)->where('status', 'active')->where(fn ($q) => $q->where('workspace_id', $this->workspace->id)->orWhere(fn ($q) => $q->whereNull('workspace_id')->whereNotIn('id', AccessGroup::where('workspace_id', $this->workspace->id)->whereNotNull('source_template_id')->select('source_template_id'))))->get()->filter(fn ($g) => ! array_diff($g->permissions, array_keys($permissions)))
                ->mapWithKeys(fn ($g) => [$g->id => ['name' => $g->name, 'permissions' => $g->permissions]])->all();
            $this->vm['forms'][] = $this->form('Mời nhân viên', 'member.invite', [
                $this->field('email', 'Email người được mời', 'email'),
                $this->field('access_group_ids', 'Nhóm quyền', 'groups', $groups, [], false),
                $this->field('permissions', 'Quyền riêng bổ sung', 'permissions', $permissions, [], false), $this->scopeField(), $this->field('room_ids', 'Giới hạn phòng (bỏ trống: các phòng trong nhà được giao)', 'multiselect', $this->roomOptions('member.delegate'), [], false),
                $this->field('valid_until', 'Hết hạn truy cập (không bắt buộc)', 'datetime-local', required: false),
            ]);
        }
        if ($section === 'members') {
            $records = [];
            $visibleProperties = array_keys($this->propertyOptions('member.view'));
            foreach ($members as $m) {
                $state = $m->status !== 'active' ? $m->status : ($m->valid_until?->isPast() ? 'expired' : 'active');
                $effective = [];
                $propertyMap = $this->access->permissionPropertyMap($m->user, $this->workspace, array_keys($permissions));
                foreach ($permissions as $code => $label) {
                    $ids = array_values(array_intersect($propertyMap[$code] ?? [], $visibleProperties));
                    if ($ids) {
                        $base = $m->group?->active && in_array($code, $m->permissions ?? $m->group->permissions, true);
                        $source = $base ? ($m->permissions !== null ? 'Cấp riêng' : 'Nhóm '.$m->group->name) : 'Quyền bổ sung';
                        $effective[] = $label.' · '.$source.' · '.Property::whereIn('id', $ids)->pluck('name')->implode(', ');
                    }
                }
                $forms = [];
                if ($m->status === 'active') {
                    $selectedGroups = GroupAssignment::where('membership_id', $m->id)->whereNull('property_id')->whereNull('room_id')->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->pluck('access_group_id')->all();
                    if ($m->permissions === null) $selectedGroups[] = $m->access_group_id;
                    $selectedGroups = array_values(array_unique(array_filter($selectedGroups)));
                    $memberGroups = $groups;
                    foreach (AccessGroup::where('workspace_id', $this->workspace->id)->whereIn('id', $selectedGroups)->get() as $existingGroup) {
                        $memberGroups[$existingGroup->id] = ['name' => $existingGroup->name.($existingGroup->status === 'active' ? '' : ($existingGroup->status === 'retired' ? ' (Ngừng mở rộng)' : ' (Tạm ngừng)')), 'permissions' => array_values(array_intersect($existingGroup->permissions, array_keys($permissions)))];
                    }
                    $forms[] = $this->form('Chỉnh sửa quyền và phạm vi', 'member.update', [
                        $this->field('access_group_ids', 'Nhóm quyền', 'groups', $memberGroups, $selectedGroups, false),
                        $this->field('permissions', 'Quyền riêng bổ sung', 'permissions', $permissions, $m->permissions ?? [], false),
                        $this->scopeField($m->property_ids), $this->field('room_ids', 'Giới hạn phòng (bỏ trống: các phòng trong nhà được giao)', 'multiselect', $this->roomOptions('member.delegate'), $m->room_ids ?? [], false),
                        $this->field('valid_until', 'Hết hạn truy cập (không bắt buộc)', 'datetime-local', value: $m->valid_until?->format('Y-m-d\TH:i'), required: false),
                    ], $m->id);
                    $forms[] = $this->form('Kết thúc quyền truy cập', 'member.end', [], $m->id) + ['confirmation' => 'Nhân viên sẽ không còn truy cập khu này. Quyền bổ sung cũng được thu hồi; lịch sử làm việc được giữ lại.'];
                    $forms[] = $this->form('Tạm ngừng làm việc', 'member.suspend', [$this->field('reason', 'Lý do tạm ngừng', 'textarea')], $m->id);
                }
                if (in_array($m->status, ['pending', 'suspended'], true)) {
                    $forms[] = $this->form('Kích hoạt làm việc', 'member.activate', [], $m->id) + ['confirmation' => 'Kiểm tra suất nhân sự và quyền hiện tại trước khi kích hoạt.'];
                    $forms[] = $this->form('Kết thúc tham gia', 'member.end', [], $m->id);
                }
                if ($m->status === 'active') {
                    $groupNames = collect($groups)->mapWithKeys(fn ($g, $id) => [$id => $g['name']])->all();
                    $forms[] = $this->form('Gán nhóm bổ sung', 'group.assignment.save', $this->assignmentFields($m->user_id, $groupNames));
                    $forms = [...$forms, ...$this->assignmentCards($m->user_id, $groupNames)];
                }
                $records[] = $this->card($m->user->name, $m->user->email, [
                    'Nhóm quyền' => collect([$m->permissions_mode === 'replace' ? $m->group?->name : null])->merge(app(AccessService::class)->assignments($m->user)->where('workspace_id', $this->workspace->id)->pluck('group.name'))->filter()->unique()->implode(', ') ?: 'Quyền được cấp riêng',
                    'Nhà được giao' => $m->property_ids ? Property::whereIn('id', $m->property_ids)->pluck('name')->implode(', ') : 'Toàn bộ nhà trong khu',
                    'Phòng được giao' => $m->room_ids ? Room::whereIn('id', $m->room_ids)->pluck('name')->implode(', ') : 'Các phòng trong nhà được giao',
                    'Quyền hiệu lực' => count($effective), 'Hết hạn' => $m->valid_until?->format('d/m/Y H:i') ?? 'Không giới hạn',
                ], $forms) + ['_key'=>'memberships:'.$m->id] + ['state' => $state, 'state_label' => ['active' => 'Đang làm việc', 'expired' => 'Hết hạn', 'ended' => 'Đã kết thúc', 'pending' => 'Chờ kích hoạt', 'suspended' => 'Tạm ngừng'][$state], 'permissions' => $effective];
            }
            $this->vm['records'] = $this->filterPeople($records, $q, ['active' => 'Đang làm việc', 'pending' => 'Chờ kích hoạt', 'suspended' => 'Tạm ngừng', 'expired' => 'Hết hạn', 'ended' => 'Đã kết thúc']);
            $this->vm['people']['empty'] = 'Mời nhân viên tham gia hoặc điều chỉnh bộ lọc để xem kết quả khác.';
        } elseif ($section === 'invitations') {
            $records = [];
            foreach ($invitations as $i) {
                $state = app(InvitationService::class)->state($i);
                $forms = [];
                if (in_array($state, ['pending', 'expired'])) {
                    if (! $i->valid_until?->isPast() && app(DelegationPolicy::class)->canWorkspace($this->user, $this->workspace, $i->permissions ?? [], $i->property_ids, $i->valid_until?->toDateTimeString(), $i->room_ids)) {
                        $forms[] = $this->form('Tạo lại liên kết mời', 'invitation.renew', [], $i->id) + ['confirmation' => 'Liên kết cũ sẽ hết hiệu lực. Gửi liên kết mới cho đúng người được mời.'];
                    }
                    $forms[] = $this->form('Hủy lời mời', 'invitation.cancel', [], $i->id) + ['confirmation' => 'Người nhận sẽ không thể dùng lời mời này để tham gia.'];
                }
                $records[] = $this->card($i->email, 'Lời mời tham gia', [
                    'Gửi ngày' => $i->created_at->format('d/m/Y H:i'), 'Liên kết hết hạn' => $i->expires_at->format('d/m/Y H:i'),
                    'Nhà được giao' => $i->property_ids ? Property::whereIn('id', $i->property_ids)->pluck('name')->implode(', ') : 'Toàn bộ nhà trong khu',
                    'Số quyền' => count($i->permissions ?? []),
                ], $forms) + ['_key'=>'invitations:'.$i->id] + ['state' => $state, 'state_label' => ['pending' => 'Chờ tham gia', 'expired' => 'Hết hạn', 'accepted' => 'Đã tham gia', 'declined' => 'Đã từ chối', 'cancelled' => 'Đã hủy'][$state]];
            }
            $this->vm['records'] = $this->filterPeople($records, $q, ['pending' => 'Chờ tham gia', 'accepted' => 'Đã tham gia', 'declined' => 'Đã từ chối', 'expired' => 'Hết hạn', 'cancelled' => 'Đã hủy']);
        } else {
            if ($active->isNotEmpty()) {
                $this->vm['forms'][] = $this->form('Cấp quyền có thời hạn', 'grant.create', [
                    $this->field('user_id', 'Nhân viên', 'select', $active->mapWithKeys(fn ($m) => [$m->user_id => $m->user->name.' · '.$m->user->email])->all()),
                    $this->field('property_id', 'Nhà áp dụng (bỏ trống: toàn bộ)', 'select', $this->propertyOptions('member.delegate'), required: false), $this->field('room_id', 'Phòng áp dụng (không bắt buộc)', 'select', $this->roomOptions('member.delegate'), required: false),
                    $this->field('permissions', 'Quyền riêng bổ sung', 'permissions', $permissions, [], false),
                    $this->field('valid_from', 'Bắt đầu', 'datetime-local', value: now()->format('Y-m-d\TH:i')),
                    $this->field('valid_until', 'Kết thúc', 'datetime-local'), $this->field('reason', 'Lý do cấp quyền', 'textarea'),
                ]);
            }
            $records = [];
            foreach ($repo->grants($this->user, $this->workspace, $members->pluck('user_id')->all()) as $g) {
                $state = $g->revoked_at ? 'revoked' : ($g->valid_until?->lte(now()) ? 'expired' : ($g->valid_from->isFuture() ? 'scheduled' : (app(\App\Modules\AccessControl\GrantGraph::class)->usable($g) ? 'active' : 'blocked')));
                $forms = in_array($state, ['active', 'scheduled', 'blocked']) ? [$this->form('Thu hồi quyền', 'grant.revoke', [], $g->id) + ['confirmation' => 'Quyền bổ sung này sẽ ngừng hiệu lực ngay. Quyền đã cấp riêng hoặc qua nhóm vẫn được giữ.']] : [];
                $records[] = $this->card($permissions[$g->permission] ?? 'Quyền bổ sung', User::find($g->user_id)?->name ?? '', [
                    'Nhà áp dụng' => $g->property_id ? Property::find($g->property_id)?->name : 'Toàn bộ nhà được giao',
                    'Người cấp' => User::find($g->grantor_id)?->name ?? 'Chưa đối chiếu',
                    'Nguồn quyền' => ['direct' => 'Cấp riêng', 'membership' => 'Quyền làm việc', 'group' => 'Nhóm quyền'][$g->origin_kind] ?? 'Quyền cũ cần đối chiếu',
                    ...app(\App\Modules\AccessControl\GrantExplanation::class)->details($g),
                    'Bắt đầu' => $g->valid_from->format('d/m/Y H:i'), 'Kết thúc' => $g->valid_until?->format('d/m/Y H:i') ?? 'Không giới hạn', 'Lý do' => $g->reason,
                ], $forms) + ['_key'=>'direct_grants:'.$g->id] + ['state' => $state, 'state_label' => ['active' => 'Đang hiệu lực', 'scheduled' => 'Chưa đến hạn', 'expired' => 'Hết hạn', 'revoked' => 'Đã thu hồi', 'blocked' => 'Tạm ngừng hiệu lực'][$state]];
            }
            $this->vm['records'] = $this->filterPeople($records, $q, ['active' => 'Đang hiệu lực', 'scheduled' => 'Chưa đến hạn', 'expired' => 'Hết hạn', 'revoked' => 'Đã thu hồi', 'blocked' => 'Tạm ngừng hiệu lực']);
        }
    }
}
