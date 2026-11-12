<?php

namespace App\Modules\Identity;

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Connection\Models\Connection;
use App\Modules\DataPlatform\Contracts\HotCache;
use App\Modules\Listing\Models\Favorite;
use App\Modules\Listing\Models\Listing;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use App\Modules\Notification\Models\UserNotification;
use App\Modules\Rental\Models\RentalRequest;
use App\Modules\Subscription\Models\SubscriptionOrder;
use App\Modules\Trust\Models\Report;
use App\Modules\Workspace\Models\Workspace;
use App\Support\ProductPresenter;

final class Presenter extends ProductPresenter
{
    use \App\Support\OwnerTransferPresentation;
    protected function build(string $slug, string $q): void
    {
        if (in_array($slug, ['login', 'register', 'recover-access'])) {
            $this->vm['auth'] = $slug;
        } elseif ($slug === 'profile-security') {
            $this->vm = app(ProfilePresenter::class)->present($this->request, $slug);
        } else {
            $this->dashboard();
        }
    }

    protected function dashboard(): void
    {
        if ($this->workspace) {
            if ($this->can('property.view')) {
                $this->vm['stats'] += ['Phòng trong phạm vi' => $this->rooms()->count(), 'Phòng đang thuê' => $this->rooms()->where('status', 'occupied')->count()];
                $this->vm['stats'] += ['Phòng trống' => $this->rooms()->where('status', 'vacant')->count(), 'Phòng đang giữ' => $this->rooms()->where('status', 'reserved')->count()];
            }
            if ($this->can('rental.view')) {
                $this->vm['stats']['Hồ sơ thuê'] = $this->scope->rentals($this->user, $this->workspace)->count();
                $this->vm['stats']['Yêu cầu thuê cần xem'] = RentalRequest::whereIn('room_id', $this->access->roomIds($this->user, $this->workspace, 'rental.view'))->whereIn('status', ['submitted', 'counter_offered'])->count();
            }
            if ($this->can('invoice.view')) {
                $invoices = Invoice::whereIn('rental_id', $this->scope->rentals($this->user, $this->workspace, 'invoice.view')->select('id'))->whereNotNull('issued_at')->with('payments')->get();
                $this->vm['stats']['Còn phải thu'] = self::money($invoices->sum(fn ($i) => $i->outstanding));
                $this->vm['stats']['Hóa đơn quá hạn'] = $invoices->filter(fn ($i) => $i->payment_state === 'overdue')->count();
            }
            if ($this->can('maintenance.view')) {
                $this->vm['stats']['Sửa chữa chưa xong'] = MaintenanceRequest::whereIn('room_id', $this->access->roomIds($this->user, $this->workspace, 'maintenance.view'))->whereNotIn('status', ['resolved', 'closed'])->count();
            }
            $this->vm['notice'] = 'Cập nhật lúc '.now()->format('H:i d/m/Y').'. Chọn một công việc bên dưới để tiếp tục xử lý.';
            foreach (['properties' => 'Nhà và phòng', 'workspace-leads' => 'Khách quan tâm', 'rentals' => 'Hồ sơ thuê', 'billing' => 'Hóa đơn và thanh toán', 'maintenance' => 'Yêu cầu sửa chữa', 'iot-devices' => 'An toàn nhà trọ'] as $slug => $label) {
                if ($this->request->attributes->get('ui.access')->can('ui.'.$slug)) {
                    $this->vm['links'][] = $this->link($label, $slug);
                }
            }
        } elseif ($this->request->attributes->get('ui.context') === 'platform-admin') {
            if ($this->request->filled('section')) {
                $section = $this->request->input('section');
                if ($section === 'ownership') { abort_unless($this->access->global($this->user, 'workspace.transfer.review'), 403); $this->transferCases(); }
                elseif ($section === 'legacy') $this->vm = app(\App\Modules\Subscription\LegacyReviewPresenter::class)->present($this->request, 'admin-dashboard');
                elseif (in_array($section, ['business','revenue'], true)) $this->vm = app(\App\Modules\Subscription\BusinessPresenter::class)->present($this->request, 'admin-dashboard');
                elseif ($section === 'banking') $this->vm = app(\App\Modules\Subscription\PlatformBankPresenter::class)->present($this->request, 'admin-dashboard');
                elseif ($section === 'plans') $this->vm = app(\App\Modules\Subscription\PlanPresenter::class)->present($this->request, 'admin-dashboard');
                else $this->vm = app(\App\Modules\Subscription\AdminPresenter::class)->present($this->request, 'admin-dashboard');
                return;
            }
            if ($this->access->global($this->user, 'account.view')) $this->vm['stats']['Tài khoản'] = User::count();
            if ($this->access->global($this->user, 'report.manage')) $this->vm['stats']['Phản ánh chưa xử lý xong'] = Report::whereIn('status', ['open', 'under_review'])->count();
            if ($this->access->global($this->user, 'listing.moderate')) $this->vm['stats']['Tin đăng chờ kiểm duyệt'] = Listing::where('status', 'pending_review')->count();
            foreach (config('navigation.admin_sections') as $section => $definition) {
                if (($definition['nav'] ?? true) && collect($definition['permissions'])->contains(fn ($permission) => $this->access->global($this->user, $permission))) $this->vm['links'][] = $this->link($definition['label'], 'admin-dashboard', ['section' => $section]);
            }
            if ($this->access->global($this->user, 'subscription.plan.manage')) {
                $this->vm['stats']['Gói chưa phát hành'] = collect(array_keys(config('subscriptions.plans')))->filter(fn ($key) => !app(\App\Modules\Subscription\PlanCatalog::class)->latest($key))->count();
            }
            if ($this->access->global($this->user, 'subscription.approve')) {
                $quotes = \App\Modules\Subscription\Models\SubscriptionQuote::where('status', 'pending');
                $this->vm['stats']['Yêu cầu gói chưa hoàn tất'] = (clone $quotes)->count();
                $this->vm['stats']['Chờ đối soát chuyển khoản'] = (clone $quotes)->whereNotNull('payer_reported_at')->count();
                $this->vm['stats']['Hồ sơ gói cũ chờ xem'] = SubscriptionOrder::where('status', 'pending')->count();
                foreach ((clone $quotes)->with('accountSubscription.owner')->orderByRaw('payer_reported_at IS NULL')->latest()->limit(config('navigation.admin_preview_limit'))->get() as $quote) $this->vm['records'][] = app(\App\Modules\Subscription\AdminPresenter::class)->quoteCard($quote, [$this->link('Xem yêu cầu', 'admin-dashboard', ['section' => 'requests', 'q' => $quote->reference])]);
                $this->vm['links'][] = $this->link('Xem tất cả yêu cầu gói', 'admin-dashboard', ['section' => 'requests']);
            }
            foreach (['admin-accounts' => 'Tài khoản', 'moderation' => 'Kiểm duyệt nội dung', 'admin-reports' => 'Xử lý phản ánh'] as $target => $label) if ($this->request->attributes->get('ui.access')->can('ui.'.$target)) $this->vm['links'][] = $this->link($label, $target);
        } elseif ($this->user) {
            $this->vm['notice'] = 'Chào '.$this->user->name.'. Theo dõi các việc cần làm và thông tin thuê của bạn tại đây.';
            $caps = $this->access->effectiveCodes($this->user);
            if (in_array('favorite.manage', $caps, true)) {
                $this->vm['stats']['Phòng đã lưu'] = Favorite::where('user_id', $this->user->id)->count();
            }
            if (in_array('contact.view', $caps, true)) {
                $this->vm['stats']['Liên hệ'] = Connection::where('user_id', $this->user->id)->whereHas('listing.room.property.workspace', fn ($w) => app(\App\Modules\Subscription\PropertyCloud::class)->eligible($w))->count();
                $this->vm['stats']['Yêu cầu thuê đang chờ'] = RentalRequest::where('user_id', $this->user->id)->whereHas('room.property.workspace', fn ($w) => app(\App\Modules\Subscription\PropertyCloud::class)->eligible($w))->whereIn('status', ['submitted', 'counter_offered', 'accepted'])->count();
            }
            if (in_array('rental.view', $caps, true)) {
                $this->vm['stats']['Đang thuê'] = count($this->scope->currentRoomIds($this->user));
            }
            if (in_array('notification.view', $caps, true)) {
                $this->vm['stats']['Thông báo mới'] = UserNotification::where('user_id', $this->user->id)->whereNull('read_at')->count();
            }
            foreach (['rentals' => 'Hồ sơ thuê', 'my-invoices' => 'Hóa đơn của tôi', 'maintenance' => 'Báo sự cố', 'room-safety' => 'An toàn phòng', 'landlord-upgrade' => 'Quản lý nhà trọ'] as $slug => $label) {
                if ($this->request->attributes->get('ui.access')->can('ui.'.$slug)) {
                    $this->vm['links'][] = $this->link($label, $slug);
                }
            }
        }
    }
}
