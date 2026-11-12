<?php

namespace App\Modules\Maintenance;

use App\Modules\DataPlatform\Models\AuditEntry;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Support\ProductPresenter;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $this->maintenance($q);
    }

    protected function maintenance(string $q): void
    {
        $this->vm['forms'][] = $this->form('Báo sự cố', 'maintenance.create', [$this->field('room_id', 'Phòng cần hỗ trợ', 'select', $this->roomOptions('maintenance.create')), $this->field('title', 'Vấn đề cần hỗ trợ'), $this->field('description', 'Mô tả chi tiết', 'textarea')]);
        $query = MaintenanceRequest::with('room.property')->where('title', 'like', '%'.$q.'%');
        if ($this->workspace) {
            $query->whereIn('room_id', $this->rooms('maintenance.view')->select('id'));
        } else {
            $query->where('reporter_id', $this->user->id);
        }
        $this->records($query->latest(), function ($m) {
            $next = ['new' => 'acknowledged', 'acknowledged' => 'in_progress', 'in_progress' => 'resolved', 'resolved' => 'closed'];
            $forms = [];
            if ($m->status !== 'closed' && ($m->reporter_id === $this->user->id || $this->can('maintenance.comment', $m->room->property_id, $m->room_id))) {
                $forms[] = $this->form('Bổ sung thông tin', 'maintenance.comment', [$this->field('note', 'Nội dung', 'textarea')], $m->id);
            }
            if ($this->can('maintenance.manage', $m->room->property_id, $m->room_id) && isset($next[$m->status])) {
                $forms[] = $this->form('Cập nhật xử lý', 'maintenance.update', [$this->field('status', 'Bước xử lý', 'select', [$next[$m->status] => self::label($next[$m->status])]), $this->field('note', 'Nội dung xử lý', 'textarea')], $m->id);
            }
            $facts = ['Phòng' => $m->room->name, 'Trạng thái' => self::label($m->status)];
            foreach (AuditEntry::where('resource_type', $m->getMorphClass())->where('resource_id', $m->id)->latest()->limit(10)->get() as $audit) {
                if (isset($audit->changes['note'])) {
                    $facts[$audit->created_at->format('d/m/Y H:i').' #'.$audit->id] = $audit->changes['note'];
                }
            }

            return $this->card($m->title, $m->description, $facts, $forms);
        });
    }
}
