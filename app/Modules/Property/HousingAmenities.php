<?php

namespace App\Modules\Property;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class HousingAmenities
{
    public static function filter(Builder $rooms, string $value): Builder
    {
        $fold = fn ($text) => preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($text)));
        $needle = $fold($value);
        $matching = fn ($scope) => collect(config('housing.amenities.'.$scope))->filter(fn ($label, $id) => $needle !== '' && (str_contains($fold($label), $needle) || str_contains($fold($id), $needle)))->keys()->all();
        $roomIds = $matching('room');
        $propertyIds = $matching('property');

        return $rooms->where(function ($any) use ($value, $roomIds, $propertyIds) {
            $any->where('amenities', 'like', '%'.$value.'%')->orWhereJsonContains('custom_amenities', $value);
            foreach ($roomIds as $id) {
                $any->orWhereJsonContains('amenity_ids', $id);
            }
            $any->orWhereHas('property', function ($property) use ($value, $propertyIds) {
                $property->where(function ($any) use ($value, $propertyIds) {
                    $any->whereJsonContains('custom_amenities', $value);
                    foreach ($propertyIds as $id) {
                        $any->orWhereJsonContains('amenity_ids', $id);
                    }
                });
            });
        });
    }

    public static function normalize(array $data, string $scope): array
    {
        if (! array_key_exists('amenity_ids', $data) && ! array_key_exists('custom_amenities', $data) && ! array_key_exists('_amenities_present', $data)) {
            return $data;
        }
        $values = Validator::make($data, [
            'amenity_ids' => 'nullable|array|max:40', 'amenity_ids.*' => ['string', 'distinct', Rule::in(array_keys(config('housing.amenities.'.$scope)))],
            'custom_amenities' => 'nullable|array|max:'.config('housing.custom_amenities_limit'), 'custom_amenities.*' => 'required|string|max:'.config('housing.custom_amenity_length'),
        ])->validate();
        $data['amenity_ids'] = array_values($values['amenity_ids'] ?? []);
        $data['custom_amenities'] = collect($values['custom_amenities'] ?? [])->map(fn ($s) => trim(strip_tags($s)))->filter()->unique(fn ($s) => mb_strtolower($s))->values()->all();
        if ($scope === 'room') {
            $data['amenities'] = null;
        }
        unset($data['_amenities_present']);

        return $data;
    }

    public static function labels($model, string $scope): string
    {
        $labels = collect($model->amenity_ids ?? [])->map(fn ($id) => config('housing.amenities.'.$scope.'.'.$id))->filter()->merge($model->custom_amenities ?? []);
        // Preserve old free-text amenities until the owner edits and classifies them.
        if ($scope === 'room' && $model->amenities) {
            $labels->push($model->amenities);
        }

        return $labels->unique()->implode(', ');
    }
}
