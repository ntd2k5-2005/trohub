<?php

namespace App\Modules\Roommate;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Contracts\DocumentStore;
use App\Modules\DataPlatform\Journal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Private preferences; public introductions remain on the explicitly published need. */
final class RoommateProfileService
{
    public function __construct(private DocumentStore $documents) {}

    public function read(User $user): array
    {
        try {
            $document = $this->documents->find('roommate_profiles', ['user_id' => $user->id], 1)[0] ?? [];
            $interests = data_get($document, 'preferences.interests', []);
            if (! is_array($interests) || array_filter($interests, fn ($value) => ! is_string($value))) {
                throw new \UnexpectedValueException('Unsupported preference list');
            }

            return ['available' => true, 'document' => $document];
        } catch (Throwable) {
            return ['available' => false, 'document' => []];
        }
    }

    public function save(User $user, array $input): void
    {
        $this->authorize($user);
        $data = Validator::make($input, [
            'minimum_budget' => 'nullable|integer|min:0|max:100000000', 'maximum_budget' => 'nullable|integer|min:0|max:100000000',
            'quiet_from' => 'nullable|date_format:H:i', 'quiet_until' => 'nullable|date_format:H:i',
            'smoking' => 'required|boolean', 'pets' => 'required|boolean', 'interests' => 'nullable|string|max:1000',
            'move_in_after' => 'nullable|date', 'minimum_months' => 'nullable|integer|min:1|max:120',
        ])->validate();
        if (isset($data['minimum_budget'],$data['maximum_budget']) && $data['maximum_budget'] < $data['minimum_budget']) {
            throw ValidationException::withMessages(['maximum_budget' => 'Ngân sách tối đa cần lớn hơn hoặc bằng mức tối thiểu.']);
        }
        $interests = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/u', $data['interests'] ?? '')))));
        Validator::make(['interests' => $interests], ['interests' => 'array|max:20', 'interests.*' => 'string|max:80'])->validate();
        $state = $this->read($user);
        abort_unless($state['available'], 503, 'Hồ sơ sở thích tạm chưa sẵn sàng. Bạn vẫn có thể đăng và tìm nhu cầu ở ghép.');
        $document = $state['document'];
        $id = $document['_id'] ?? 'roommate-profile:'.$user->id;
        abort_unless(is_string($id), 409, 'Hồ sơ hiện tại cần được kiểm tra trước khi cập nhật.');
        $updated = array_replace_recursive($document, [
            'user_id' => $user->id,
            'budget' => ['minimum' => isset($data['minimum_budget']) ? (int) $data['minimum_budget'] : null, 'maximum' => isset($data['maximum_budget']) ? (int) $data['maximum_budget'] : null, 'currency' => 'VND', 'basis' => 'per_person'],
            'preferences' => ['quiet_hours' => ['from' => $data['quiet_from'] ?? null, 'to' => $data['quiet_until'] ?? null], 'smoking' => (bool) $data['smoking'], 'pets' => (bool) $data['pets']],
            'availability' => ['move_in_after' => $data['move_in_after'] ?? null, 'minimum_months' => isset($data['minimum_months']) ? (int) $data['minimum_months'] : null],
        ]);
        // Lists replace old values; recursive merging would retain removed interests.
        $updated['preferences']['interests'] = $interests;
        try {
            $this->documents->put('roommate_profiles', $id, $updated);
        } catch (Throwable) {
            abort(503, 'Chưa lưu được hồ sơ sở thích. Vui lòng thử lại sau; nhu cầu ở ghép của bạn vẫn được giữ.');
        }
        app(Journal::class)->record($user->id, 'roommate.profile.updated', $user);
    }

    public function delete(User $user): void
    {
        $this->authorize($user);
        $state = $this->read($user);
        abort_unless($state['available'], 503, 'Hồ sơ sở thích tạm chưa sẵn sàng. Vui lòng thử lại sau.');
        $id = $state['document']['_id'] ?? null;
        if ($id === null) {
            return;
        }
        abort_unless(is_string($id), 409, 'Hồ sơ hiện tại cần được kiểm tra trước khi thay đổi.');
        try {
            $this->documents->delete('roommate_profiles', $id);
        } catch (Throwable) {
            abort(503, 'Chưa xóa được hồ sơ sở thích. Vui lòng thử lại sau.');
        }
        app(Journal::class)->record($user->id, 'roommate.profile.deleted', $user);
    }

    private function authorize(User $user): void
    {
        abort_unless(config('features.roommate') && app(Authorization::class)->personal($user, 'roommate.update'), 403);
    }
}
