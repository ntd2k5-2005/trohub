<?php

namespace App\Modules\Property;

use App\Modules\Listing\ListingService;
use App\Modules\Property\Models\Room;
use Illuminate\Support\Facades\Validator;

/** Built-in keys remain compatible; custom labels use the existing room_type column. */
final class HousingTypes
{
    public static function label(?string $value): string
    {
        return config('housing.room_types', [])[$value] ?? $value ?? 'Chưa cập nhật';
    }

    public static function rules(): array
    {
        return ['nullable', 'string', 'max:'.config('housing.room_type_max_length'), 'not_regex:/[<>\x00-\x1F\x7F]/u'];
    }

    public static function normalize(?string $value): ?string
    {
        $value = $value === null ? null : preg_replace('/\s+/u', ' ', trim($value));
        Validator::make(['room_type' => $value], ['room_type' => self::rules()])->validate();
        if ($value === null || $value === '') {
            return null;
        }
        foreach (config('housing.room_types') as $key => $label) {
            if (mb_strtolower($value) === mb_strtolower($key) || mb_strtolower($value) === mb_strtolower($label)) {
                return $key;
            }
        }

        return $value;
    }

    public static function options(?int $workspace = null): array
    {
        $rooms = Room::whereNotNull('room_type');
        if ($workspace !== null) {
            $rooms->whereHas('property', fn ($p) => $p->where('workspace_id', $workspace));
        } else {
            $rooms->whereIn('id', app(ListingService::class)->available()->select('room_id'));
        }

        return collect(config('housing.room_types'))->union($rooms->distinct()->pluck('room_type')->mapWithKeys(fn ($type) => [$type => self::label($type)]))->all();
    }
}
