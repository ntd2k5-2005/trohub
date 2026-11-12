<?php

namespace App\Modules\Trust;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Contracts\DocumentStore;
use App\Modules\DataPlatform\Journal;
use App\Modules\Rental\Models\Rental;
use App\Modules\Trust\Models\Review;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class ReviewDetailsService
{
    private bool $unavailable = false;

    public function __construct(private DocumentStore $documents) {}

    public function read(Review $review): array
    {
        if ($this->unavailable) {
            return ['available' => false, 'document' => []];
        }
        try {
            $document = $this->documents->find('review_details', ['review_id' => $review->id], 1)[0] ?? [];
            $suggestions = data_get($document, 'aspects.shared_areas.suggestions', []);
            if (! is_array($suggestions) || array_filter($suggestions, fn ($value) => ! is_string($value))) {
                throw new \UnexpectedValueException('Unsupported review list');
            }

            return ['available' => true, 'document' => $document];
        } catch (Throwable) {
            $this->unavailable = true;

            return ['available' => false, 'document' => []];
        }
    }

    public function save(User $user, Review $review, array $input): void
    {
        $this->authorize($user, $review);
        $data = Validator::make($input, ['handover_comment' => 'nullable|string|max:2000', 'shared_area_suggestions' => 'nullable|string|max:2000', 'would_recommend' => 'nullable|boolean'])->validate();
        $state = $this->read($review);
        abort_unless($state['available'], 503, 'Đánh giá bổ sung tạm chưa sẵn sàng. Đánh giá cơ bản của bạn vẫn được giữ.');
        $document = $state['document'];
        $id = $document['_id'] ?? 'review-details:'.$review->id;
        abort_unless(is_string($id), 409, 'Nội dung hiện tại cần được kiểm tra trước khi cập nhật.');
        $updated = array_replace_recursive($document, ['review_id' => $review->id, 'rental_id' => $review->rental_id, 'user_id' => $user->id, 'aspects' => ['handover' => ['comment' => $data['handover_comment'] ?? '']]]);
        $updated['aspects']['shared_areas']['suggestions'] = array_values(array_filter(array_map('trim', preg_split('/\R/u', $data['shared_area_suggestions'] ?? ''))));
        $updated['would_recommend'] = isset($data['would_recommend']) ? (bool) $data['would_recommend'] : null;
        try {
            $this->documents->put('review_details', $id, $updated);
        } catch (Throwable) {
            abort(503, 'Chưa lưu được đánh giá bổ sung. Vui lòng thử lại sau.');
        }
        app(Journal::class)->record($user->id, 'review.details.updated', $review);
    }

    public function delete(User $user, Review $review): void
    {
        $this->authorize($user, $review);
        $state = $this->read($review);
        abort_unless($state['available'], 503, 'Đánh giá bổ sung tạm chưa sẵn sàng. Vui lòng thử lại sau.');
        $id = $state['document']['_id'] ?? null;
        if ($id === null) {
            return;
        }
        abort_unless(is_string($id), 409, 'Nội dung hiện tại cần được kiểm tra trước khi thay đổi.');
        try {
            $this->documents->delete('review_details', $id);
        } catch (Throwable) {
            abort(503, 'Chưa xóa được đánh giá bổ sung. Vui lòng thử lại sau.');
        }
        app(Journal::class)->record($user->id, 'review.details.deleted', $review);
    }

    private function authorize(User $user, Review $review): void
    {
        abort_unless($review->user_id === $user->id && app(Authorization::class)->personal($user, 'community.review') && Rental::whereKey($review->rental_id)->where('tenant_id', $user->id)->where('status', 'closed')->exists(), 403);
    }
}
