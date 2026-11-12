<?php

namespace App\Modules\Roommate;

use App\Models\User;
use App\Modules\Roommate\Models\RoommateNeed;
use App\Modules\Roommate\Models\RoommateRequest;
use App\Support\ProductPresenter;

final class Presenter extends ProductPresenter
{
    protected function build(string $slug, string $q): void
    {
        $this->roommate($q);
    }

    protected function roommate(string $q): void
    {
        $this->request->validate(['mode' => 'nullable|in:has_room,search_together', 'max_budget' => 'nullable|integer|min:0|max:100000000', 'move_before' => 'nullable|date']);
        $this->vm['roommate'] = true;
        $this->vm['tabs'] = [$this->link('Tất cả nhu cầu', 'roommate-discovery'), $this->link('Có phòng cần người', 'roommate-discovery', ['mode' => 'has_room']), $this->link('Cùng tìm phòng', 'roommate-discovery', ['mode' => 'search_together'])];
        $own = RoommateNeed::cloudAccessible()->where('user_id', $this->user->id)->where('status', 'open')->where('expires_at', '>', today())->first();
        if ($own) {
            $recommendations = app(RecommendationRepository::class)->candidates($own);
            $this->vm['notice'] = $recommendations['available'] ? 'Có '.count($recommendations['ids']).' nhu cầu gần với khu vực và ngân sách bạn đã chọn.' : 'Gợi ý phù hợp tạm chưa sẵn sàng. Bạn vẫn có thể tìm và kết nối ở ghép.';
        }
        $profile = app(RoommateProfileService::class)->read($this->user);
        if ($profile['available']) {
            $document = $profile['document'];
            $this->vm['forms'][] = $this->form('Cập nhật hồ sơ sở thích', 'roommate.profile.save', [
                $this->field('minimum_budget', 'Ngân sách tối thiểu mỗi người (đồng)', 'number', value: data_get($document, 'budget.minimum'), required: false),
                $this->field('maximum_budget', 'Ngân sách tối đa mỗi người (đồng)', 'number', value: data_get($document, 'budget.maximum'), required: false),
                $this->field('quiet_from', 'Giờ bắt đầu nghỉ ngơi', 'time', value: data_get($document, 'preferences.quiet_hours.from'), required: false),
                $this->field('quiet_until', 'Giờ kết thúc nghỉ ngơi', 'time', value: data_get($document, 'preferences.quiet_hours.to'), required: false),
                $this->field('smoking', 'Bạn có hút thuốc?', 'select', [0 => 'Không', 1 => 'Có'], (int) data_get($document, 'preferences.smoking', false)),
                $this->field('pets', 'Bạn có nuôi thú cưng?', 'select', [0 => 'Không', 1 => 'Có'], (int) data_get($document, 'preferences.pets', false)),
                $this->field('interests', 'Sở thích (mỗi dòng một mục)', 'textarea', value: implode("\n", data_get($document, 'preferences.interests', [])), required: false),
                $this->field('move_in_after', 'Có thể chuyển vào từ ngày', 'date', value: data_get($document, 'availability.move_in_after'), required: false),
                $this->field('minimum_months', 'Dự kiến ở tối thiểu (tháng)', 'number', value: data_get($document, 'availability.minimum_months'), required: false),
            ]);
            if ($document) {
                $this->vm['forms'][] = $this->form('Xóa hồ sơ sở thích', 'roommate.profile.delete', [$this->field('confirm', 'Tôi muốn xóa sở thích riêng; giữ nguyên nhu cầu đang đăng', 'checkbox')]);
            }
            $this->vm['notice'] = trim(($this->vm['notice'] ?? '').' Hồ sơ sở thích chỉ hiển thị với bạn. Phần giới thiệu trong nhu cầu đã đăng vẫn được chia sẻ như trước.');
        } else {
            $this->vm['notice'] = trim(($this->vm['notice'] ?? '').' Hồ sơ sở thích tạm chưa sẵn sàng. Bạn vẫn có thể đăng và tìm nhu cầu ở ghép.');
        }
        $this->vm['forms'][] = $this->form($own ? 'Cập nhật nhu cầu ở ghép' : 'Đăng nhu cầu ở ghép', 'roommate.save', [$this->field('mode', 'Bạn đang tìm ai?', 'select', ['has_room' => 'Đã có phòng, tìm người ở cùng', 'search_together' => 'Chưa có phòng, cùng tìm chỗ ở'], $own?->mode ?? 'search_together'), $this->field('room_id', 'Phòng đang thuê (chỉ chọn khi đã có phòng)', 'select', $this->roomOptions('property.view'), $own?->room_id, false), $this->field('people_needed', 'Số người muốn tìm', 'number', value: $own?->people_needed ?? 1), $this->field('budget_basis', 'Ngân sách tính theo', 'select', ['per_person' => 'Mỗi người mỗi tháng', 'total' => 'Tổng tiền phòng mỗi tháng'], $own?->budget_basis ?? 'per_person'), $this->field('duration_months', 'Dự kiến ở bao nhiêu tháng?', 'number', value: $own?->duration_months, required: false), $this->field('district', 'Khu vực', value: $own?->district), $this->field('budget', 'Ngân sách mỗi tháng (đồng)', 'number', value: $own?->budget), $this->field('move_in', 'Ngày chuyển vào', 'date', value: $own?->move_in), $this->field('lifestyle', 'Thói quen và mong muốn chia sẻ', 'textarea', value: $own?->lifestyle), $this->field('expires_at', 'Hiển thị đến ngày', 'date', value: $own?->expires_at?->format('Y-m-d'))], $own?->id);
        $suggested = $recommendations['ids'] ?? [];
        $query = RoommateNeed::cloudAccessible()->where('status', 'open')->where('expires_at', '>', now())->where('district', 'like', '%'.$q.'%');
        $query->when($this->request->filled('mode'), fn ($b) => $b->where('mode', $this->request->input('mode')))->when($this->request->filled('max_budget'), fn ($b) => $b->where('budget', '<=', $this->request->integer('max_budget')))->when($this->request->filled('move_before'), fn ($b) => $b->where('move_in', '<=', $this->request->input('move_before')));
        if ($suggested) {
            $query->orderByRaw('CASE WHEN id IN ('.implode(',', array_fill(0, count($suggested), '?')).') THEN 0 ELSE 1 END', $suggested);
        }
        $this->records($query->latest(), fn ($n) => $this->card((in_array($n->id, $suggested) ? 'Gợi ý ở ghép tại ' : 'Tìm người ở ghép tại ').$n->district, $n->lifestyle, ['Nhu cầu' => $n->mode === 'has_room' ? 'Đã có phòng, tìm người ở cùng' : 'Cùng tìm phòng', 'Số người cần tìm' => $n->people_needed, 'Ngân sách' => self::money($n->budget).($n->budget_basis === 'total' ? '/phòng' : '/người'), 'Ngày vào' => $n->move_in], [$this->form($n->user_id === $this->user->id ? 'Đóng nhu cầu' : 'Gửi kết nối', $n->user_id === $this->user->id ? 'roommate.close' : 'roommate.connect', [], $n->id)]));
        foreach (RoommateRequest::whereIn('roommate_need_id', RoommateNeed::cloudAccessible()->where('user_id', $this->user->id)->select('id'))->where('status', 'pending')->get() as $r) {
            $this->vm['records'][] = $this->card('Lời mời ở ghép', User::find($r->user_id)?->name ?? '', [], [$this->form('Phản hồi', 'roommate.respond', [$this->field('status', 'Phản hồi', 'select', ['accepted' => 'Đồng ý', 'rejected' => 'Từ chối'])], $r->id)]);
        }
        foreach (RoommateRequest::where('user_id', $this->user->id)->orWhereIn('roommate_need_id', RoommateNeed::cloudAccessible()->where('user_id', $this->user->id)->select('id'))->latest()->limit(20)->get() as $r) {
            $need = RoommateNeed::cloudAccessible()->find($r->roommate_need_id);
            if (! $need) {
                continue;
            }
            $partner = User::find($r->user_id === $this->user->id ? $need->user_id : $r->user_id);
            $facts = ['Trạng thái' => self::label($r->status)];
            if ($r->status === 'accepted') {
                $facts['Email liên hệ'] = $partner->email;
            }
            $this->vm['records'][] = $this->card('Kết nối tại '.$need->district, $partner->name, $facts);
        }
    }
}
