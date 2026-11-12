<?php

namespace App\Support;

use App\Modules\AccessControl\GroupChangeService;
use App\Modules\AccessControl\Models\GroupChange;
use App\Modules\AccessControl\Models\Permission;
use Illuminate\Support\Facades\DB;

trait GroupChangePresentation
{
    protected function groupChanges(): array
    {
        $records = [];
        foreach (GroupChange::with('group')->where('workspace_id', $this->workspace?->id)->where('status', 'pending_review')->latest()->get() as $change) {
            try { app(GroupChangeService::class)->authorize($this->user, $change->group, array_unique([...$change->before['permissions'], ...$change->after['permissions']])); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if ($e->getStatusCode() === 403) continue; throw $e; }
            $count = DB::table('group_change_approvals')->where('group_change_id', $change->id)->count();
            $forms = [];
            if (app(GroupChangeService::class)->canApprove($this->user, $change)) $forms[] = $this->form('Phê duyệt thay đổi', 'group.change.approve', [], $change->id);
            if ($count >= $change->required_approvals) $forms[] = $this->form('Áp dụng thay đổi đã xem trước', 'group.change.apply', [], $change->id);
            $names = Permission::pluck('name', 'code');
            $records[] = $this->card('Chờ duyệt: '.$change->group->name, 'Xem trước thay đổi nhóm quyền', [
                'Tên sau thay đổi' => $change->after['name'],
                'Trạng thái sau thay đổi' => ['active' => 'Đang sử dụng', 'retired' => 'Ngừng mở rộng', 'disabled' => 'Tạm ngừng'][$change->after['status']],
                'Người có thể bị ảnh hưởng' => count(array_unique([...array_column($change->impact['members'], 'user_id'), ...array_column($change->impact['assignments'], 'user_id'), ...array_column($change->impact['primary_users'], 'id')])),
                'Quyền được thêm' => $names->only(array_diff($change->after['permissions'], $change->before['permissions']))->implode(', ') ?: 'Không có',
                'Quyền bị bỏ' => $names->only(array_diff($change->before['permissions'], $change->after['permissions']))->implode(', ') ?: 'Không có',
                'Phê duyệt độc lập' => $count.' / '.$change->required_approvals,
            ], $forms);
        }
        return $records;
    }
}
