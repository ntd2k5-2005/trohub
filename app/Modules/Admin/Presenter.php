<?php

namespace App\Modules\Admin;

use App\Modules\DataPlatform\Models\AuditEntry;
use App\Modules\Listing\Models\Listing;
use App\Modules\Trust\Models\Report;
use App\Support\ProductPresenter;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        if ($slug === 'admin-dashboard') {
            $this->vm = app(\App\Modules\Identity\Presenter::class)->present($this->request, $slug);

            return;
        }
        $this->governance($slug, $q);
    }

    protected function governance(string $slug, string $q): void
    {
        if (in_array($slug, ['admin-accounts', 'access-control'], true)) {
            $this->vm = app(PeoplePresenter::class)->present($this->request, $slug);

            return;
        }
        if ($slug === 'moderation') {
            $tab = $this->request->validate(['tab' => 'nullable|in:all,pending,reported,hidden'])['tab'] ?? 'pending';
            $this->vm['tabs'] = [$this->link('Chờ kiểm duyệt', 'moderation', ['tab' => 'pending']), $this->link('Tin bị phản ánh', 'moderation', ['tab' => 'reported']), $this->link('Đã ẩn', 'moderation', ['tab' => 'hidden']), $this->link('Tất cả tin cần quản lý', 'moderation', ['tab' => 'all'])];
            $query = Listing::with('room.property')->where('title', 'like', '%'.$q.'%')->where(fn ($b) => $b->whereIn('status', ['published', 'pending_review'])->orWhere('moderated', true));
            if ($tab === 'pending') {
                $query->where('status', 'pending_review');
            }
            if ($tab === 'hidden') {
                $query->where('moderated', true);
            }
            if ($tab === 'reported') {
                $query->whereIn('id', Report::where('target_type', 'listing')->whereIn('status', ['open', 'under_review'])->select('target_id'));
            }
            $this->records($query->latest(), function ($listing) {
                $forms = [];
                if ($listing->status === 'published') {
                    $forms[] = $this->form('Ẩn tin vi phạm', 'listing.moderate', [$this->field('reason', 'Lý do', 'textarea')], $listing->id);
                }
                if ($listing->status === 'pending_review' || $listing->moderated) {
                    $forms[] = $this->form('Xử lý kiểm duyệt', 'listing.review', [$this->field('decision', 'Quyết định', 'select', ['approve' => 'Cho phép công khai', 'reject' => 'Chưa cho phép công khai']), $this->field('reason', 'Lý do quyết định', 'textarea')], $listing->id);
                }

                return $this->card($listing->title, $listing->description, ['Nhà trọ' => $listing->room->property->name, 'Trạng thái' => self::label($listing->status), 'Phiên bản nội dung' => $listing->content_version], $forms);
            });

            return;

        }
        if ($slug === 'moderation-history') {
            $this->records(AuditEntry::whereIn('action', ['listing.moderate', 'listing.reviewed', 'account.status', 'report.resolve'])->latest(), fn ($a) => $this->card('Quyết định quản trị', $a->changes['after']['reason'] ?? $a->changes['after']['resolution'] ?? $a->changes['reason'] ?? $a->changes['resolution'] ?? '', ['Thời gian' => $a->created_at->format('d/m/Y H:i')]));

            return;
        }
        $query = Report::query();
        if ($slug === 'my-reports') {
            $query->where('reporter_id', $this->user->id);
            $this->vm['forms'][] = $this->form('Báo cáo tài khoản', 'report.account', [$this->field('email', 'Email tài khoản cần báo cáo', 'email'), $this->field('reason', 'Nội dung và căn cứ', 'textarea')]);
        }
        $this->records($query->latest(), fn ($r) => $this->card($r->target_type === 'listing' ? 'Báo cáo tin đăng' : 'Báo cáo tài khoản', $r->reason, ['Trạng thái' => self::label($r->status), 'Kết quả' => $r->resolution ?: 'Đang chờ xử lý'], $slug === 'admin-reports' && in_array($r->status, ['open', 'under_review']) ? [$this->form('Xử lý báo cáo', 'report.resolve', [$this->field('status', 'Kết quả', 'select', $r->status === 'open' ? ['under_review' => 'Tiếp nhận điều tra'] : ['resolved' => 'Đã giải quyết', 'rejected' => 'Không đủ căn cứ']), $this->field('resolution', 'Nội dung phản hồi', 'textarea')], $r->id)] : []));
    }
}
