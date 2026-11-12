<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Connection\Models\Connection;
use App\Modules\Listing\ListingService;
use App\Modules\Property\Models\Property;
use App\Modules\Workspace\Models\Membership;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HousingExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@trohub.test')->firstOrFail();
    }

    private function listing()
    {
        return app(ListingService::class)->available()->whereHas('room.property.workspace', fn ($w) => $w->where('owner_id', $this->user('owner')->id))->firstOrFail();
    }

    public function test_galleries_are_saved_and_inherited_without_overwriting_other_houses(): void
    {
        $listing = $this->listing();
        $room = $listing->room;
        $p = $room->property;
        $urls = ['https://example.test/one.jpg', 'https://example.test/two.jpg'];
        $this->actingAs($this->user('owner'))->post('/actions/property.save', ['context' => 'workspace', 'workspace_id' => $p->workspace_id, 'id' => $p->id, 'name' => $p->name, 'address' => $p->address, 'district' => $p->district, 'image_urls' => implode("\n", $urls)])->assertSessionHasNoErrors();
        $this->assertSame($urls, $p->fresh()->image_urls);
        $this->get('/ui/listing-detail?context=public&id='.$listing->id)->assertOk()->assertSee($urls[1])->assertSee('Thông tin phòng')->assertSee('Tiền cọc');
        $this->post('/actions/property.save', ['context' => 'workspace', 'workspace_id' => $p->workspace_id, 'id' => $p->id, 'name' => $p->name, 'address' => $p->address, 'district' => $p->district, 'image_urls' => 'javascript:alert(1)'])->assertSessionHasErrors('image_urls.0');
        $this->assertSame($urls, $p->fresh()->image_urls);
        $foreign = Property::whereHas('workspace', fn ($w) => $w->where('owner_id', $this->user('other')->id))->first();
        $this->post('/actions/room.save', ['context' => 'workspace', 'property_id' => $foreign->id, 'name' => 'X', 'area' => 20, 'rent' => 100, 'deposit' => 0, 'capacity' => 2])->assertForbidden();
    }

    public function test_search_filters_and_private_fields_are_not_leaked(): void
    {
        $l = $this->listing();
        $this->get('/ui/marketplace-search?context=public&district=khongtontai')->assertOk()->assertDontSee($l->title);
        $this->get('/ui/marketplace-search?context=public&amenity=khongtontai')->assertOk()->assertDontSee($l->title);
        $this->get('/ui/listing-detail?context=public&id='.$l->id)->assertOk()->assertSee('Đăng nhập để liên hệ')->assertDontSee('owner@trohub.test')->assertDontSee('tenant@trohub.test');
        $l->update(['moderated' => true]);
        $this->get('/ui/listing-detail?context=public&id='.$l->id)->assertNotFound();
        $this->actingAs($this->user('tenant'))->post('/lien-he-phong', ['listing_id' => $l->id, 'intent' => 'chat'])->assertNotFound();
    }

    public function test_chat_has_real_messages_idempotency_history_and_scope_checks(): void
    {
        $l = $this->listing();
        $this->actingAs($this->user('tenant'))->post('/lien-he-phong', ['listing_id' => $l->id, 'intent' => 'chat'])->assertRedirect();
        $c = Connection::firstOrFail();
        $payload = ['body' => '<script>alert(1)</script> Xin chào', 'client_id' => (string) Str::uuid()];
        $this->postJson('/trao-doi/'.$c->id.'/tin-nhan', $payload)->assertOk();
        $this->postJson('/trao-doi/'.$c->id.'/tin-nhan', $payload)->assertOk();
        $this->assertSame(1, DB::table('chat_messages')->where('client_id', $payload['client_id'])->count());
        $this->getJson('/trao-doi/'.$c->id.'/tin-nhan')->assertOk()->assertJsonFragment(['body' => $payload['body']]);
        $this->actingAs($this->user('other'))->getJson('/trao-doi/'.$c->id.'/tin-nhan')->assertForbidden();
        $this->actingAs($this->user('accountant'))->postJson('/trao-doi/'.$c->id.'/tin-nhan', $payload)->assertForbidden();
        $this->actingAs($this->user('staff'))->getJson('/trao-doi/'.$c->id.'/tin-nhan')->assertOk();
        Membership::where('user_id', $this->user('staff')->id)->update(['permissions' => json_encode([])]);
        $this->getJson('/trao-doi/'.$c->id.'/tin-nhan')->assertForbidden();
        $this->actingAs($this->user('owner'))->postJson('/trao-doi/'.$c->id.'/tin-nhan', ['body' => 'Phòng còn nhé', 'client_id' => (string) Str::uuid()])->assertOk();
        $this->assertSame('contacted', $c->fresh()->status);
        $this->get('/ui/workspace-leads?context=workspace&workspace_id='.$l->room->property->workspace_id.'&id='.$c->id)->assertOk()->assertSee('Mở cuộc trò chuyện');
        $c->update(['status' => 'cancelled']);
        $this->postJson('/trao-doi/'.$c->id.'/tin-nhan', ['body' => 'Không được gửi', 'client_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson('/trao-doi/'.$c->id.'/tin-nhan')->assertOk()->assertJsonPath('can_send', false);
    }

    public function test_viewing_requires_other_party_confirmation_and_rental_request_does_not_reserve_room(): void
    {
        $l = $this->listing();
        $this->actingAs($this->user('tenant'))->post('/lien-he-phong', ['listing_id' => $l->id, 'intent' => 'viewing', 'viewing_at' => now()->addDays(2)->format('Y-m-d H:i:s')])->assertSessionHasNoErrors();
        $c = Connection::firstOrFail();
        $this->assertSame('viewing_scheduled', $c->status);
        $this->post('/actions/connection.update', ['context' => 'personal', 'id' => $c->id, 'status' => 'viewing_confirmed'])->assertForbidden();
        $this->actingAs($this->user('owner'))->post('/actions/connection.update', ['context' => 'workspace', 'workspace_id' => $l->room->property->workspace_id, 'id' => $c->id, 'status' => 'viewing_confirmed'])->assertSessionHasNoErrors();
        $this->assertSame('viewing_confirmed', $c->fresh()->status);
        $this->actingAs($this->user('tenant'))->post('/lien-he-phong', ['listing_id' => $l->id, 'intent' => 'rent'])->assertSessionHasNoErrors();
        $this->assertNotNull($c->fresh()->rental_requested_at);
        $this->assertSame('vacant', $l->room->fresh()->status);
        $this->assertSame(0, $l->room->rentals()->count());
        $this->get('/ui/connections?context=personal&id='.$c->id)->assertOk()->assertSee('Đã gửi yêu cầu thuê');
    }

    public function test_late_viewing_and_cross_workspace_conversation_are_rejected(): void
    {
        $l = $this->listing();
        $this->actingAs($this->user('tenant'))->post('/lien-he-phong', ['listing_id' => $l->id, 'intent' => 'viewing', 'viewing_at' => now()->subDay()->toDateTimeString()])->assertSessionHasErrors('viewing_at');
        $this->assertDatabaseCount('connections', 0);
        $this->post('/lien-he-phong', ['listing_id' => $l->id, 'intent' => 'chat']);
        $c = Connection::firstOrFail();
        $this->actingAs($this->user('owner'))->get('/ui/workspace-leads?context=workspace&workspace_id='.$l->room->property->workspace_id.'&id=999999')->assertNotFound();
        $this->user('tenant')->forceFill(['status' => 'locked'])->save();
        $this->actingAs($this->user('tenant'))->getJson('/trao-doi/'.$c->id.'/tin-nhan')->assertForbidden();
    }

    public function test_login_returns_to_the_listing_and_invalid_room_name_does_not_break_crud(): void
    {
        $l = $this->listing();
        $this->get('/dang-nhap?listing='.$l->id)->assertRedirect()->assertSessionHas('url.intended', route('ui.show', ['slug' => 'listing-detail', 'context' => 'public', 'id' => $l->id]));
        $this->post('/dang-nhap', ['email' => 'tenant@trohub.test', 'password' => 'Abcd@1234'])->assertRedirect(route('ui.show', ['slug' => 'listing-detail', 'context' => 'public', 'id' => $l->id]));
        $this->actingAs($this->user('owner'))->post('/actions/room.save', ['context' => 'workspace', 'workspace_id' => $l->room->property->workspace_id, 'property_id' => $l->room->property_id, 'name' => $l->room->name, 'area' => 20, 'rent' => 1000, 'deposit' => 0, 'capacity' => 2])->assertSessionHasErrors('name');
    }
}
