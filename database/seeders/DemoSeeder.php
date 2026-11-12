<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceItem;
use App\Modules\Billing\Models\ServiceRate;
use App\Modules\IoT\Models\Device;
use App\Modules\IoT\Models\DeviceAssignment;
use App\Modules\IoT\Models\DeviceConfig;
use App\Modules\Listing\Models\Listing;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Modules\Rental\Models\ContractVersion;
use App\Modules\Rental\Models\Deposit;
use App\Modules\Rental\Models\Occupant;
use App\Modules\Rental\Models\Rental;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Seeder;

final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $users = [];
        foreach (['owner' => 'Minh Anh', 'tenant' => 'Nguyễn An', 'staff' => 'Thùy Linh', 'accountant' => 'Ngọc Trâm', 'platform' => 'Quản trị nền tảng', 'system' => 'Quản trị hệ thống', 'other' => 'Chủ trọ khác'] as $key => $name) {
            $users[$key] = User::firstOrCreate(['email' => $key.'@trohub.test'], ['name' => (config('business_history.display_names')[$key.'@trohub.test'][1]??$name), 'password' => config('demo.password')]);
        }
        foreach (['platform' => 'Quản trị nền tảng', 'system' => 'Quản trị hệ thống'] as $key => $group) {
            $users[$key]->forceFill(['access_group_id' => AccessGroup::where('name', $group)->value('id')])->save();
        }
        foreach (['owner', 'other'] as $key) {
            $w = Workspace::firstOrCreate(['owner_id' => $users[$key]->id], ['name' => $key === 'owner' ? 'Nhà trọ Minh Anh' : 'Nhà trọ Bình An', 'plan' => 'yearly', 'status' => 'active', 'provenance' => 'synthetic', 'expires_at' => now()->addYear()]);
            $p = Property::firstOrCreate(['workspace_id' => $w->id, 'name' => $key === 'owner' ? 'Nhà trọ Nguyễn Gia Trí' : 'Nhà trọ Lê Văn Việt'], ['address' => $key === 'owner' ? '25 Nguyễn Gia Trí, TP. Hồ Chí Minh' : '40 Lê Văn Việt, TP. Hồ Chí Minh', 'district' => $key === 'owner' ? 'Bình Thạnh' : 'Thủ Đức']);
            for ($i = 1; $i <= 6; $i++) {
                $room = Room::firstOrCreate(['property_id' => $p->id, 'name' => 'Phòng '.(100 + $i)], ['area' => 20 + $i * 2, 'rent' => 2500000 + $i * 200000, 'deposit' => 3000000, 'capacity' => 2, 'amenities' => 'Wifi, chỗ để xe, nhà vệ sinh riêng', 'rules' => 'Giữ vệ sinh chung, không hút thuốc trong phòng.']);
                Listing::firstOrCreate(['room_id' => $room->id], ['title' => 'Phòng thoáng sáng '.$room->area.' m² tại '.$p->district, 'description' => 'Phòng có cửa sổ, khu vực yên tĩnh, thuận tiện đi học và đi làm. Liên hệ để sắp xếp lịch xem.', 'status' => 'published', 'published_at' => now()]);
            }
            foreach (['Điện' => ['electricity', 3500], 'Nước' => ['water', 18000], 'Internet' => ['fixed', 100000]] as $name => [$kind,$rate]) {
                ServiceRate::firstOrCreate(['property_id' => $p->id, 'name' => $name], ['kind' => $kind, 'rate' => $rate, 'effective_from' => now()->startOfYear()]);
            }
            if ($key !== 'owner') {
                continue;
            }
            foreach (['staff' => 'Quản lý vận hành', 'accountant' => 'Kế toán'] as $member => $group) {
                $private = app(\App\Modules\AccessControl\WorkspaceGroupCopies::class)->resolve($w, (int) AccessGroup::where('name', $group)->whereNull('workspace_id')->value('id'));
                Membership::where('status', '!=', 'ended')->firstOrCreate(['workspace_id' => $w->id, 'user_id' => $users[$member]->id], ['access_group_id' => $private->id, 'status' => 'active', 'property_ids' => [$p->id]]);
            }
            $room = $p->rooms()->first();
            $r = Rental::firstOrCreate(['room_id' => $room->id, 'tenant_id' => $users['tenant']->id], ['starts_at' => now()->startOfMonth()->toDateString(), 'ends_at' => now()->addYear()->toDateString(), 'rent' => $room->rent, 'deposit' => $room->deposit, 'terms' => 'Thanh toán trước ngày 5 hàng tháng. Báo trước khi trả phòng theo thỏa thuận.', 'status' => 'active', 'confirmed_at' => now()]);
            $room->update(['status' => 'occupied']);
            $room->listings()->update(['status' => 'paused']);
            ContractVersion::firstOrCreate(['rental_id' => $r->id, 'version' => 1], ['actor_id' => $users['owner']->id, 'terms' => $r->only(['starts_at', 'ends_at', 'rent', 'deposit', 'terms'])]);
            Occupant::firstOrCreate(['rental_id' => $r->id, 'user_id' => $users['tenant']->id], ['relationship' => 'primary', 'starts_at' => $r->starts_at]);
            Deposit::firstOrCreate(['rental_id' => $r->id], ['amount' => $r->deposit, 'received_at' => now()]);
            $invoice = Invoice::firstOrCreate(['rental_id' => $r->id, 'period' => now()->format('Y-m')], ['total' => $r->rent, 'status' => 'issued', 'issued_at' => now(), 'due_at' => now()->addDays(7)]);
            InvoiceItem::firstOrCreate(['invoice_id' => $invoice->id, 'description' => 'Tiền phòng'], ['quantity' => 1, 'unit_price' => $r->rent, 'amount' => $r->rent, 'actor_id' => $users['owner']->id]);
            $device = Device::firstOrCreate(['serial' => 'DEMO-SAFETY-01'], ['workspace_id' => $w->id, 'name' => 'Cảm biến an toàn phòng 101', 'token_hash' => hash('sha256', 'development-device-token-change-before-deployment')]);
            DeviceAssignment::firstOrCreate(['device_id' => $device->id], ['room_id' => $room->id, 'effective_from' => now()->startOfMonth()]);
            DeviceConfig::firstOrCreate(['device_id' => $device->id, 'version' => 1], ['effective_from' => now()->startOfMonth(), 'settings' => ['local_safety' => true, 'heartbeat_seconds' => 180]]);
        }
        $this->call(DemoAccountsSeeder::class);
        $this->call(MultiWorkspaceDemoSeeder::class);
        $this->call(HousingImagesSeeder::class);
        $this->call(AuthorizationDemoSeeder::class);
    }
}
