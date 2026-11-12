<?php

namespace App\Support;

use App\Modules\AccessControl\Models\GroupAssignment;
use Illuminate\Pagination\LengthAwarePaginator;

trait PeoplePresentation
{
    protected function people(string $slug): string
    {
        $tabs = config('people.tabs.'.$slug);
        $section = $this->request->validate(['section' => 'nullable|string|in:'.implode(',', array_keys($tabs))])['section'] ?? null;
        if ($slug === 'access-control') {
            $required = ['assignments' => ['access.assign', 'access.delegate', 'access.revoke'], 'groups' => ['access.view'], 'catalog' => ['access.catalog.edit'], 'history' => ['access.audit.view']];
            $tabs = array_filter($tabs, fn ($key) => collect($required[$key])->contains(fn ($code) => $this->access->global($this->user, $code)), ARRAY_FILTER_USE_KEY);
            abort_unless($tabs && (! $section || isset($tabs[$section])), 403);
        }
        $section ??= array_key_first($tabs);
        $this->vm['people'] = ['description' => config('people.descriptions.'.$slug), 'section' => $section, 'tabs' => collect($tabs)->map(fn ($label, $key) => $this->link($label, $slug, ['section' => $key]))->all(), 'filters' => [], 'empty' => 'Chưa có thông tin trong mục này.'];

        return $section;
    }

    protected function assignmentFields(int $userId, array $groups, ?GroupAssignment $assignment = null): array
    {
        $fields = [$this->field('user_id', 'Tài khoản', 'hidden', value: $userId), $this->field('access_group_id', 'Nhóm quyền', 'select', $groups, $assignment?->access_group_id)];
        if ($this->workspace) {
            $fields[] = $this->field('target_workspace_id', 'Khu quản lý', 'hidden', value: $this->workspace->id);
            $fields[] = $this->field('property_id', 'Nhà áp dụng (bỏ trống: toàn khu)', 'select', $this->propertyOptions('member.delegate'), $assignment?->property_id, false);
            $fields[] = $this->field('room_id', 'Phòng áp dụng (không bắt buộc)', 'select', $this->roomOptions('member.delegate'), $assignment?->room_id, false);
        }
        $fields[] = $this->field('valid_from', 'Bắt đầu', 'datetime-local', value: $assignment?->valid_from?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i'));
        $fields[] = $this->field('valid_until', 'Kết thúc (không bắt buộc)', 'datetime-local', value: $assignment?->valid_until?->format('Y-m-d\TH:i'), required: false);

        return $fields;
    }

    protected function assignmentCards(int $userId, array $groups): array
    {
        $cards = [];
        $assignments = GroupAssignment::with('group')->where('user_id', $userId)->where('origin', 'explicit')->whereNull('revoked_at')->when($this->workspace, fn ($q) => $q->where('workspace_id', $this->workspace->id), fn ($q) => $q->whereNull('workspace_id'))->get();
        foreach ($assignments as $a) {
            if (! isset($groups[$a->access_group_id])) {
                continue;
            }
            $hidden = $this->workspace ? [$this->field('target_workspace_id', 'Khu quản lý', 'hidden', value: $this->workspace->id)] : [];
            if ($a->group->status === 'active' && (! $a->valid_until || $a->valid_until->isFuture())) $cards[] = $this->form('Điều chỉnh nhóm '.$a->group->name, 'group.assignment.save', $this->assignmentFields($userId, $groups, $a), $a->id);
            $cards[] = $this->form('Thu hồi nhóm '.$a->group->name, 'group.assignment.revoke', $hidden, $a->id) + ['confirmation' => 'Nhóm quyền này sẽ ngừng hiệu lực ngay. Các nguồn quyền khác vẫn được giữ.'];
        }

        return $cards;
    }

    protected function filterPeople(array $records, string $q, array $statuses = []): array
    {
        $status = $this->request->validate(['status' => 'nullable|string|in:'.implode(',', array_keys($statuses))])['status'] ?? '';
        $this->vm['people']['filters'] = $statuses;
        $this->vm['people']['search'] = true;
        $records = collect($records)->filter(fn ($record) => (! $status || ($record['state'] ?? '') === $status)
            && (! $q || str_contains(mb_strtolower($record['title'].' '.$record['subtitle']), mb_strtolower($q))))->values();
        if($this->request->filled('item'))$records=$records->filter(fn($r)=>RecordPresentation::key($r)===$this->request->query('item'))->values();
        $page = $this->request->filled('item')?1:max(1, (int) $this->request->query('page', 1));
        $pager = new LengthAwarePaginator($records->forPage($page, config('business.pagination'))->values(), $records->count(), config('business.pagination'), $page, ['path' => $this->request->url(), 'query' => $this->request->query()]);
        $this->vm['pagination'] = $pager;

        return $pager->items();
    }
}
