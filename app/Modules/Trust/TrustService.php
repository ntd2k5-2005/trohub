<?php

namespace App\Modules\Trust;

use App\Contracts\Authorization as AccessService;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Listing\Models\Listing;
use App\Modules\Rental\Models\Rental;
use App\Modules\Trust\Models\Report;
use App\Modules\Trust\Models\Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TrustService
{
    public function execute(User $u, string $action, int $id, array $data): void
    {
        $access = app(AccessService::class);
        if (in_array($action, ['report.account', 'report.create', 'review.create'], true)) {
            abort_unless($access->personal($u, $action === 'review.create' ? 'community.review' : 'community.report'), 403);
        }
        $journal = app(Journal::class);
        DB::transaction(function () use ($action, $u, $id, $data, $access, $journal) {
            $before = [];
            switch ($action) {
                case 'report.account':
                    $target = User::where('email', mb_strtolower($data['email']))->first();
                    if (! $target || $target->id === $u->id) {
                        throw ValidationException::withMessages(['email' => 'Không tìm thấy tài khoản phù hợp để báo cáo.']);
                    }
                    $record = Report::create(['target_type' => 'account', 'target_id' => $target->id, 'reporter_id' => $u->id, 'reason' => $data['reason']]);
                    break;
                case 'report.create':

                    ($data['target_type'] === 'listing' ? Listing::query() : User::query())->findOrFail($data['target_id']);
                    $record = Report::create($data + ['reporter_id' => $u->id]);
                    break;
                case 'report.resolve':
                    abort_unless($access->global($u, 'report.manage'), 403);

                    $record = Report::whereKey($id)->lockForUpdate()->firstOrFail();
                    abort_unless(($record->status === 'open' && $data['status'] === 'under_review') || ($record->status === 'under_review' && in_array($data['status'], ['resolved', 'rejected'])), 422, 'Trạng thái xử lý không hợp lệ.');
                    $record->update($data);
                    $journal->notify($record->reporter_id, 'Báo cáo được cập nhật', $data['resolution']);
                    break;
                case 'listing.moderate':
                    abort_unless($access->global($u, 'listing.moderate'), 403);
                    $record = Listing::findOrFail($id);
                    $record->update(['moderated' => true, 'status' => 'paused']);
                    $journal->notify($record->room->property->workspace->owner_id, 'Tin đăng đã được tạm ẩn', $data['reason']);
                    break;
                case 'review.create':

                    Rental::where('tenant_id', $u->id)->where('status', 'closed')->findOrFail($data['rental_id']);
                    if (Review::where('rental_id', $data['rental_id'])->exists()) {
                        throw ValidationException::withMessages(['review' => 'Bạn đã đánh giá lần thuê này.']);
                    }
                    $record = Review::create($data + ['user_id' => $u->id]);
                    break;
                default: abort(404);
            }
            $journal->record($u->id, $action, $record, null, ['before' => $before, 'after' => array_intersect_key($data, array_flip(['name', 'scope', 'permissions', 'access_group_id', 'user_id', 'permission', 'valid_from', 'valid_until', 'reason', 'status', 'resolution', 'code', 'category', 'delegable']))]);
        });
    }
}
