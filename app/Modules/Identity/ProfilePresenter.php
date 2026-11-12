<?php

namespace App\Modules\Identity;

use App\Modules\AccessControl\Models\Permission;
use App\Modules\Property\Models\Property;
use App\Support\PeoplePresentation;
use App\Support\ProductPresenter;

final class ProfilePresenter extends ProductPresenter
{
    use PeoplePresentation;
    use \App\Support\OwnerTransferPresentation;

    protected function build(string $slug, string $q): void
    {
        $section = $this->people($slug);
        if ($section === 'profile') {
            $this->vm['forms'][] = $this->form('Lưu thông tin cá nhân', 'profile', [$this->field('name', 'Họ và tên', value: $this->user->name)]);
            $this->vm['records'][] = $this->card($this->user->name, $this->user->email, ['Tình trạng' => 'Đang hoạt động', 'Ngày tham gia' => $this->user->created_at?->format('d/m/Y') ?? '—']);
        } elseif ($section === 'security') {
            if ($this->user->getAttribute('password_login_enabled') !== false) {
                $this->vm['forms'][] = $this->form('Đổi mật khẩu', 'profile.password', [
                $this->field('current_password', 'Mật khẩu hiện tại', 'password'),
                $this->field('password', 'Mật khẩu mới (ít nhất 10 ký tự)', 'password'),
                $this->field('password_confirmation', 'Nhập lại mật khẩu mới', 'password'),
            ]);
            }
            $social = app(SocialLogin::class);
            if ($social->installed() && app(\App\Modules\AccessControl\AccessService::class)->platformAuthorityCodes($this->user) === []) {
                $this->vm['social_accounts'] = \App\Modules\Identity\Models\SocialAccount::where('user_id', $this->user->id)->pluck('provider')->all();
                $this->vm['has_password'] = $this->user->password_login_enabled;
            }
        } else {
            $this->transferCases();
            $catalog = Permission::where('active', true)->pluck('name', 'code');
            $global = $catalog->only($this->access->effectiveCodes($this->user))->values()->all();
            if ($global) {
                $this->vm['records'][] = $this->card('Quyền tài khoản', 'Quyền cấp cho tài khoản', ['Số quyền' => count($global)]) + ['permissions' => $global,'permission_sources'=>app(\App\Modules\AccessControl\AccessService::class)->explanation($this->user)];
            }
            foreach ($this->access->workspaces($this->user) as $w) {
                $effective = $catalog->only($this->access->effectiveCodes($this->user, $w))->values()->all();
                $member = $this->access->member($this->user, $w);
                $owner = $w->owner_id === $this->user->id;
                $forms = $member && ! $owner ? [$this->leaveForm($member->id)] : [];
                if ($owner && app(\App\Modules\AccessControl\AccessService::class)->canManageLifecycle($this->user, $w)) {
                    $forms[]=$this->form('Đề nghị bàn giao quyền quản lý','transfer.submit',[$this->field('email','Email tài khoản người nhận','email'),$this->field('reference','Mã đề nghị','hidden',value:(string)\Illuminate\Support\Str::uuid()),$this->field('reason','Lý do và phạm vi đề nghị','textarea')],$w->id);
                    foreach (($w->lifecycle === 'active' && $w->status !== 'archived' ? ['paused' => 'Tạm dừng tiếp nhận mới'] : ['active' => 'Khôi phục hoạt động']) as $state => $label) {
                        $forms[] = $this->form($label, 'workspace.lifecycle', [$this->field('state', 'Trạng thái', 'hidden', value: $state), $this->field('version', 'Phiên bản', 'hidden', value: $w->version), $this->field('reason', 'Lý do', 'textarea'), $this->field('confirm', 'Tôi xác nhận thay đổi trạng thái khu quản lý', 'checkbox')], $w->id);
                    }
                }
                $this->vm['records'][] = $this->card($w->name, $owner ? 'Chủ khu quản lý' : 'Nhân viên', [
                    'Nhà được giao' => $owner || $member?->property_ids === null ? 'Toàn bộ nhà trong khu' : Property::whereIn('id', $member->property_ids)->pluck('name')->implode(', '),
                    'Hết hạn truy cập' => $owner ? 'Theo gói dịch vụ' : ($member?->valid_until?->format('d/m/Y H:i') ?? 'Không giới hạn'),
                    'Trạng thái khu' => self::label($w->effective_status),
                ], $forms, [['label' => 'Mở khu quản lý', 'url' => route('ui.enter', ['context' => 'workspace', 'workspace_id' => $w->id])]]) + ['_key'=>'workspaces:'.$w->id] + ['permissions' => $effective,'permission_sources'=>app(\App\Modules\AccessControl\AccessService::class)->explanation($this->user,$w)];
            }
            foreach (\App\Modules\Workspace\Models\Membership::with('workspace')->where('user_id', $this->user->id)->whereIn('status', ['pending', 'suspended'])->get() as $m) {
                $this->vm['records'][] = $this->card($m->workspace->name, 'Lần tham gia của bạn', ['Trạng thái' => $m->status === 'pending' ? 'Chờ kích hoạt' : 'Tạm ngừng làm việc'], [$this->leaveForm($m->id)]) + ['_key'=>'memberships:'.$m->id];
            }
            $this->vm['people']['empty'] = 'Bạn đang sử dụng tài khoản cá nhân. Các khu quản lý sẽ xuất hiện tại đây khi bạn tham gia.';
        }
    }

    private function leaveForm(int $id): array
    {
        return $this->form('Rời khu quản lý', 'member.leave', [$this->field('reason', 'Lý do', 'textarea'), $this->field('confirm', 'Tôi xác nhận kết thúc lần tham gia này', 'checkbox')], $id);
    }
}
