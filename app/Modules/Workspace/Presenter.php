<?php

namespace App\Modules\Workspace;

use App\Modules\Billing\ReceivingAccountPresenter;
use App\Support\ProductPresenter;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        if ($slug === 'workspace-dashboard') {
            $this->request->validate(['tab'=>'nullable|in:business,operations']);
            $finance=app(\App\Modules\Billing\DashboardScope::class)->financial($this->user,$this->workspace);
            if($this->request->input('tab')==='business'||$this->request->filled('workspace_scope')||$finance&&$this->request->input('tab')!=='operations')$this->vm=app(\App\Modules\Billing\DashboardPresenter::class)->present($this->request,$slug);
            else{$this->vm = app(\App\Modules\Identity\Presenter::class)->present($this->request, $slug);if($finance)$this->vm['links'][]=$this->link('Tổng quan kinh doanh','workspace-dashboard',['tab'=>'business']);}

            return;
        }
        if ($slug === 'team-permissions') {
            $this->vm = app(TeamPresenter::class)->present($this->request, $slug);
        } else {
            $section = $this->request->validate(['section' => 'nullable|in:general,banking'])['section'] ?? 'general';
            $bankVisible = $this->can('bank_account.view') || $this->can('bank_account.create');
            $this->vm['tabs'] = [$this->link('Thông tin khu quản lý', 'workspace-settings')];
            if ($bankVisible) {
                $this->vm['tabs'][] = $this->link('Tài khoản ngân hàng', 'workspace-settings', ['section' => 'banking']);
            }
            if ($section === 'banking') {
                abort_unless($bankVisible, 403);
                $tabs = $this->vm['tabs'];
                $this->vm = app(ReceivingAccountPresenter::class)->present($this->request, $slug);
                $this->vm['tabs'] = $tabs;

                return;
            }
            $this->vm['notice'] = 'Nhà trọ: '.$this->workspace->name;
            $this->vm['stats'] = ['Trạng thái khu quản lý' => self::label($this->workspace->effective_status)];
            if ($this->access->allowed($this->user, 'workspace.update', $this->workspace)) {
                $this->vm['forms'][] = $this->rentalPolicyForm('workspace', $this->workspace);
                $this->vm['forms'][] = $this->form('Cập nhật tên quản lý', 'workspace.save', [$this->field('name', 'Tên hiển thị', value: $this->workspace->name)]);
                $state = $this->workspace->lifecycle === 'paused' ? 'active' : 'paused';
                $this->vm['forms'][] = $this->form($state === 'paused' ? 'Tạm dừng tiếp nhận mới' : 'Tiếp tục tiếp nhận mới', 'workspace.lifecycle', [$this->field('state', 'Trạng thái', 'hidden', value: $state), $this->field('version', 'Phiên bản', 'hidden', value: $this->workspace->version), $this->field('reason', 'Lý do', 'textarea'), $this->field('confirm', 'Tôi xác nhận thay đổi trạng thái', 'checkbox')], $this->workspace->id);
                $this->vm['forms'][] = $this->form('Lưu trữ khu quản lý', 'workspace.archive', [$this->field('reason', 'Lý do lưu trữ', 'textarea'), $this->field('confirm', 'Tôi đã kiểm tra các nghĩa vụ còn lại và xác nhận lưu trữ', 'checkbox')]) + ['confirmation' => 'Khu quản lý sẽ dừng hoạt động và nhân sự được tạm ngừng. Dữ liệu được giữ nguyên; thao tác này không tự tất toán công nợ hoặc cọc.'];
            }
            foreach (['properties' => 'Nhà và phòng', 'team-permissions' => 'Nhân viên', 'subscription' => 'Gói dịch vụ'] as $slug => $label) {
                if ($this->can(['properties' => 'property.view', 'team-permissions' => 'member.view', 'subscription' => 'subscription.manage'][$slug])) {
                    $this->vm['links'][] = $this->link($label, $slug);
                }
            }
        }
    }
}
