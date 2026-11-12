<?php

namespace App\Modules\Property;

use App\Modules\Listing\Models\Listing;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class HousingMedia
{
    private array $cache = [];

    public function preload(array $records): void
    {
        $groups = collect($records)->groupBy(fn ($record) => $this->type($record));
        foreach ($groups as $type => $models) {
            $rows = DB::table('housing_media')->where('resource_type', $type)->whereIn('resource_id', $models->pluck('id'))->orderBy('position')->get()->groupBy('resource_id');
            foreach ($models as $record) {
                $this->cache[$type.':'.$record->id] = $rows->get($record->id, collect());
            }
        }
    }

    private function type(Model $record): string
    {
        return match (true) {
            $record instanceof Property => 'property', $record instanceof Room => 'room', $record instanceof Listing => 'listing', default => throw new \InvalidArgumentException('Unsupported photo resource')
        };
    }

    public function rows(Model $record)
    {
        return $this->cache[$this->type($record).':'.$record->id] ??= DB::table('housing_media')->where('resource_type', $this->type($record))->where('resource_id', $record->id)->orderBy('position')->orderBy('created_at')->get();
    }

    public function save(Model $record, array $files, array $remove): void
    {
        Validator::make(['gallery' => $files, 'remove_images' => $remove], ['gallery' => 'array|max:'.config('housing.max_images'), 'gallery.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:4096', 'remove_images' => 'array', 'remove_images.*' => 'required|uuid|distinct'])->validate();
        $rows = $this->rows($record);
        if (array_diff($remove, $rows->pluck('id')->all())) {
            abort(403);
        }
        if ($rows->count() - count($remove) + count($files) + count($record->image_urls ?? []) > config('housing.max_images')) {
            throw ValidationException::withMessages(['gallery' => 'Tổng số ảnh vượt quá số lượng cho phép.']);
        }
        $workspace = $record instanceof Property ? $record->workspace_id : ($record instanceof Room ? $record->property->workspace_id : $record->room->property->workspace_id);
        $position = ((int) $rows->max('position')) + 1;
        foreach ($files as $file) {
            DB::table('housing_media')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'resource_type' => $this->type($record), 'resource_id' => $record->id, 'path' => $file->store('housing-images', 'local'), 'position' => $position++, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($rows->whereIn('id', $remove) as $row) {
            DB::table('housing_media')->where('id', $row->id)->delete();
            DB::afterCommit(fn () => Storage::disk('local')->delete($row->path));
        }
        unset($this->cache[$this->type($record).':'.$record->id]);
    }
}
