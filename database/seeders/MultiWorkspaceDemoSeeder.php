<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Seeder;

final class MultiWorkspaceDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $owner = User::where('email', 'owner@trohub.test')->first();
        if (! $owner) {
            return;
        }
        $workspace = Workspace::firstOrCreate(['owner_id' => $owner->id, 'name' => 'Nhà trọ An Phú'], [
            'plan' => 'yearly', 'status' => 'active', 'provenance' => 'synthetic', 'expires_at' => now()->addYear(),
        ]);
        $property = Property::firstOrCreate(['workspace_id' => $workspace->id, 'name' => 'Nhà trọ An Phú'], [
            'address' => '18 đường số 12, An Phú, TP. Hồ Chí Minh', 'district' => 'Thủ Đức',
        ]);
        foreach (['AP 101', 'AP 102'] as $name) {
            Room::firstOrCreate(['property_id' => $property->id, 'name' => 'Phòng '.$name], [
                'area' => 28, 'rent' => 3500000, 'deposit' => 3500000, 'capacity' => 2,
                'amenities' => 'Wifi, máy lạnh, chỗ để xe', 'rules' => 'Giữ vệ sinh chung.',
            ]);
        }
    }
}
