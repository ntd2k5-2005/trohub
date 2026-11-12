<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkspaceFactory extends Factory
{
    protected $model = Workspace::class;

    public function definition(): array
    {
        return ['owner_id' => User::factory(), 'name' => 'Khu quản lý '.fake()->unique()->numerify('####'), 'plan' => 'monthly', 'status' => 'active', 'expires_at' => now()->addMonth(), 'provenance' => 'synthetic'];
    }
}
