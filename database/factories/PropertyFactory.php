<?php

namespace Database\Factories;

use App\Modules\Property\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

class PropertyFactory extends Factory
{
    protected $model = Property::class;

    public function definition(): array
    {
        return ['workspace_id' => WorkspaceFactory::new(), 'name' => 'Nhà trọ '.fake()->unique()->numerify('####'), 'address' => fake()->numberBetween(1, 500).' Nguyễn Văn Cừ', 'district' => 'TP Hồ Chí Minh', 'public_address' => 'Khu vực Nguyễn Văn Cừ, TP Hồ Chí Minh', 'amenity_ids' => ['parking', 'shared_wifi'], 'custom_amenities' => [], 'image_urls' => []];
    }
}
