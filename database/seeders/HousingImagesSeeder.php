<?php

namespace Database\Seeders;

use App\Modules\Property\Models\Property;
use Illuminate\Database\Seeder;

final class HousingImagesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        Property::whereNull('image_urls')->whereHas('workspace', fn ($q) => $q->where('provenance', 'synthetic')->whereHas('owner', fn ($u) => $u->whereIn('email', ['owner@trohub.test', 'other@trohub.test'])))
            ->each(fn ($p) => $p->update(['image_urls' => config('housing.demo_images')]));
    }
}
