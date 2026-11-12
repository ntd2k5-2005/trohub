<?php

namespace App\Modules\Rental;

use App\Modules\Property\Models\Room;
use Illuminate\Support\Facades\Validator;

final class RentalPolicy
{
    public function quote(Room $room): string
    {
        return hash_hmac('sha256', json_encode([$room->id, $room->rent, $room->deposit, $room->capacity, $this->forRoom($room)]), config('app.key'));
    }

    public function forRoom(Room $room): array
    {
        return array_replace(collect(config('rental_workflow'))->except('statuses')->all(), $room->property->workspace->rental_policy ?? [], $room->property->rental_policy ?? [], $room->rental_policy ?? []);
    }

    public function validate(array $input): array
    {
        return Validator::make($input, ['hold_hours' => 'sometimes|integer|min:1|max:720', 'extension_hours' => 'sometimes|integer|min:1|max:24', 'max_extensions' => 'sometimes|integer|min:0|max:1', 'exception_hours' => 'sometimes|integer|min:1|max:24', 'partial_deposit' => 'sometimes|boolean', 'deposit_terms' => 'sometimes|required|string|max:5000'])->validate();
    }
}
