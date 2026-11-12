<?php

namespace Database\Factories;

use App\Modules\Property\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoomFactory extends Factory
{
    protected $model = Room::class;

    public function definition(): array
    {
        return ['property_id' => PropertyFactory::new(), 'name' => 'Phòng '.fake()->unique()->numerify('####'), 'area' => fake()->numberBetween(15, 40), 'capacity' => 2, 'rent' => 2000000, 'deposit' => 1000000, 'room_type' => 'single', 'status' => 'vacant', 'amenity_ids' => ['window'], 'custom_amenities' => [], 'image_urls' => []];
    }
}
