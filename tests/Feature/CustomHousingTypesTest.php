<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Listing\ListingService;
use App\Modules\Property\HousingTypes;
use App\Modules\Property\Models\Room;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomHousingTypesTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('email', 'owner@trohub.test')->firstOrFail();
        $room = Room::whereHas('property.workspace', fn ($q) => $q->where('owner_id', $owner->id))->where('status', 'vacant')->firstOrFail();
        $data = ['context' => 'workspace', 'workspace_id' => $room->property->workspace_id, 'id' => $room->id, 'property_id' => $room->property_id, 'name' => $room->name, 'rent' => $room->rent, 'deposit' => $room->deposit, 'capacity' => $room->capacity, 'area' => 23.75, 'length' => 5.5, 'width' => 4.32];

        return [$owner, $room, $data];
    }

    public function test_custom_type_tags_and_decimal_dimensions_survive_save_reload_and_public_filter(): void
    {
        [$owner, $room, $data] = $this->fixture();
        $this->actingAs($owner)->post('/actions/room.save', $data + ['room_type' => '  Nhà vườn   mini ', 'custom_amenities' => ['Tủ đồ riêng', ' tủ đồ riêng ', 'Rèm cách nhiệt']])->assertSessionHasNoErrors();
        $room->refresh();
        $this->assertSame('Nhà vườn mini', $room->room_type);
        $this->assertSame(['Tủ đồ riêng', 'Rèm cách nhiệt'], $room->custom_amenities);
        $this->assertEquals(23.75, $room->area);
        $this->assertEquals(5.5, $room->length);
        $this->assertEquals(4.32, $room->width);
        $this->get('/ui/room-detail?context=workspace&workspace_id='.$data['workspace_id'].'&id='.$room->id)->assertOk()->assertSee('Nhà vườn mini')->assertSee('Tủ đồ riêng');
        $listing = app(ListingService::class)->save($owner, $room->id, ['title' => 'Nhà vườn kiểm thử', 'description' => 'Không gian xanh', 'status' => 'published']);
        $this->get('/ui/marketplace-search?context=public&room_type='.urlencode('Nhà vườn mini'))->assertOk()->assertSee($listing->title);
        $this->get('/ui/marketplace-search?context=public&room_type=office')->assertOk()->assertDontSee($listing->title);
    }

    public function test_builtin_labels_normalize_without_exposing_codes_and_invalid_or_foreign_writes_fail(): void
    {
        [$owner, $room, $data] = $this->fixture();
        $this->actingAs($owner)->post('/actions/room.save', $data + ['room_type' => 'Văn phòng'])->assertSessionHasNoErrors();
        $this->assertSame('office', $room->fresh()->room_type);
        $this->get('/ui/room-detail?context=workspace&workspace_id='.$data['workspace_id'].'&id='.$room->id)->assertOk()->assertSee('Văn phòng')->assertSee('Căn hộ dịch vụ');
        $this->post('/actions/room.save', $data + ['room_type' => str_repeat('x', 41)])->assertSessionHasErrors('room_type');
        $this->post('/actions/room.save', $data + ['room_type' => '<script>'])->assertSessionHasErrors('room_type');
        $this->assertSame('office', $room->fresh()->room_type);
        $tenant = User::where('email', 'tenant@trohub.test')->firstOrFail();
        $this->actingAs($tenant)->post('/actions/room.save', $data + ['room_type' => 'Không được ghi'])->assertForbidden();
        $this->assertSame('office', $room->fresh()->room_type);
        $this->assertArrayHasKey('whole_house', HousingTypes::options($data['workspace_id']));
    }
}
