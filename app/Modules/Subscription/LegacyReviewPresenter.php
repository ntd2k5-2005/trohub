<?php

namespace App\Modules\Subscription;

use App\Models\User;
use App\Modules\Subscription\Models\LegacySubscriptionReview as Review;
use App\Modules\Subscription\Models\SubscriptionOrder;
use App\Modules\Workspace\Models\Workspace;
use App\Support\ProductPresenter;

/** Manual review inside the existing subscription administration page. */
final class LegacyReviewPresenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        abort_unless($this->access->global($this->user, 'subscription.approve'), 403);
        $data = $this->request->validate(['owner_id' => 'nullable|integer|min:1']);
        $labels = ['retained' => 'Giữ nguyên kỳ hiện có', 'unresolved' => 'Chưa đủ căn cứ đối chiếu', 'closed' => 'Đã kết thúc kỳ cũ, có thể đăng ký mới'];
        $this->vm['notice'] = 'Đối chiếu hồ sơ và chứng từ gói cũ. Kỳ gói đã lưu được giữ nguyên.';
        $this->vm['links'][] = $this->link('Quay lại quản lý dịch vụ', 'admin-dashboard');
        if (empty($data['owner_id'])) {
            $this->vm['admin_filter'] = ['section'=>'legacy','states'=>[]];
            $owners = User::whereIn('id', Workspace::select('owner_id'))->when($q !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')));
            $this->records($owners, fn ($owner) => $this->card($owner->name, $owner->email, [], [], [$this->link('Xem hồ sơ gói', 'admin-dashboard', ['section' => 'legacy', 'owner_id' => $owner->id])]));
            return;
        }
        $owner = User::findOrFail($data['owner_id']);
        $service = app(LegacySubscriptionReview::class);
        $report = $service->report($owner->id);
        $workspaces = Workspace::where('owner_id', $owner->id)->get();
        $this->vm['stats'] = ['Chủ tài khoản' => $owner->name, 'Khu quản lý' => $workspaces->count(), 'Hồ sơ đăng ký cũ' => $report['order_count']];
        foreach (['workspaces'=>'Khu tính hạn mức', 'properties'=>'Nhà đang khai thác', 'rooms'=>'Phòng đang khai thác', 'staff'=>'Nhân sự đang hoạt động'] as $kind=>$label) $this->vm['stats'][$label] = app(\App\Modules\Workspace\OwnerQuota::class)->usage($owner->id, $kind);
        foreach ($service->originalPeriods($owner->id) as $period) $this->vm['records'][] = $this->card('Lịch sử kỳ gói trước', config('subscriptions.plans.'.$period['plan'].'.label'), ['Hết hạn đã ghi nhận' => $period['expires_at'] ? \Illuminate\Support\Carbon::parse($period['expires_at'])->format('d/m/Y H:i') : 'Chưa xác định', 'Trạng thái đã ghi nhận' => self::label($period['status'])]);
        foreach ($workspaces as $workspace) $this->vm['records'][] = $this->card($workspace->name, 'Kỳ gói đã lưu', ['Gói' => config('subscriptions.plans.'.$workspace->plan.'.label'), 'Ngày tạo khu' => $workspace->created_at->format('d/m/Y'), 'Hết hạn' => $workspace->expires_at?->format('d/m/Y H:i') ?? 'Chưa xác định', 'Trạng thái' => self::label($workspace->effective_status)]);
        $periodCards = $this->vm['records'];
        $this->records(SubscriptionOrder::whereIn('workspace_id', $workspaces->pluck('id')), fn ($order) => $this->card('Hồ sơ đăng ký cũ', config('subscriptions.plans.'.$order->plan.'.label'), ['Ngày đăng ký' => $order->created_at->format('d/m/Y H:i'), 'Trạng thái hồ sơ' => ['pending'=>'Chờ xử lý','confirmed'=>'Đã ghi nhận hồ sơ','cancelled'=>'Đã hủy','rejected'=>'Đã từ chối'][$order->status] ?? 'Cần kiểm tra', 'Thanh toán' => 'Chưa có xác nhận thực nhận', 'Số tiền đã đối soát' => 'Chưa xác định', 'Bắt đầu kỳ' => 'Chưa có thông tin trong hồ sơ', 'Kết thúc kỳ' => 'Xem kỳ gói đã lưu của khu quản lý']), 'orders_page');
        $this->vm['records'] = array_merge($periodCards, $this->vm['records']);
        if (!app(AccountSubscriptionService::class)->current($owner->id)) $this->vm['forms'][] = $this->form('Ghi nhận đối chiếu', 'legacy.subscription.review', [$this->field('owner_id', '', 'hidden', value: $owner->id), $this->field('source_hash', '', 'hidden', value: $report['source_hash']), $this->field('decision', 'Kết quả', 'select', $labels), $this->field('note', 'Căn cứ và ghi chú kiểm tra', 'textarea'), $this->field('confirm', 'Tôi đã kiểm tra kỳ gói và căn cứ hiện có', 'checkbox')]);
        foreach (Review::where('owner_id', $owner->id)->latest()->limit(30)->get() as $review) $this->vm['records'][] = $this->card('Lịch sử đối chiếu', $labels[$review->decision], ['Thời điểm' => $review->created_at->format('d/m/Y H:i'), 'Người kiểm tra' => User::find($review->reviewed_by)?->name ?? 'Tài khoản không còn hoạt động', 'Căn cứ' => $review->note]);
    }
}
