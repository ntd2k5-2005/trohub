<?php

namespace App\Support;

use App\Modules\Listing\Models\Listing;
use App\Modules\Property\HousingMedia;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use Illuminate\Support\Facades\Validator;

final class HousingImages
{
    public static function normalize(array $data): array
    {
        if (! array_key_exists('image_urls', $data)) {
            return $data;
        }
        $urls = is_array($data['image_urls']) ? $data['image_urls'] : preg_split('/\R/u', $data['image_urls'] ?? '');
        $urls = array_values(array_unique(array_filter(array_map('trim', $urls))));
        Validator::make(['image_urls' => $urls], [
            'image_urls' => 'array|max:'.config('housing.max_images'),
            'image_urls.*' => ['required', 'string', 'max:2048', 'url:https'],
        ], ['image_urls.*.url' => 'Mỗi ảnh cần một liên kết HTTPS hợp lệ.', 'image_urls.max' => 'Bộ ảnh vượt quá số lượng cho phép.'])->validate();
        $data['image_urls'] = $urls;

        return $data;
    }

    public static function for(Property|Room|Listing $record): array
    {
        $urls = array_merge(app(HousingMedia::class)->rows($record)->map(fn ($row) => route('housing.image', ['media' => $row->id]))->all(), $record->image_urls ?? []);
        if ($record instanceof Listing) {
            if (! $urls) {
                $urls = self::for($record->room);
            }
            if ($record->cover_path) {
                array_unshift($urls, route('listing.cover', ['listing' => $record->id]));
            }
            if (! $urls) {
                $urls = self::for($record->room);
            }
        } elseif ($record instanceof Room && ! $urls) {
            $urls = self::for($record->property);
        }

        return array_slice(array_values(array_unique($urls ?: [config('housing.fallback_image')])), 0, config('housing.max_images'));
    }
}
