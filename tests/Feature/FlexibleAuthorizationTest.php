<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\AccessControl\AccountGroups;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\Property\Models\Room;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FlexibleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $key): User
    {
        return User::where('email', $key.'@trohub.test')->firstOrFail();
    }

    private function workspace(): Workspace
    {
        return Workspace::where('owner_id', $this->user('owner')->id)->firstOrFail();
    }

    private function root(): User
    {
        return User::where('email', config('authorization.demo_supreme_email'))->firstOrFail();
    }

    public function test_bootstrap_is_finite_hashed_and_system_is_not_a_superuser(): void
    {
        $this->assertTrue(Hash::check(config('demo.password'), $this->root()->password));
        $this->assertTrue(app(AccessService::class)->global($this->root(), 'access.bootstrap'));
        $this->assertFalse(app(AccessService::class)->global($this->user('system'), 'access.delegate'));
        $this->assertTrue(app(AccessService::class)->global($this->user('system'), 'data.replay'));
    }

    public function test_multiple_groups_union_and_revocation_are_immediate(): void
    {
        $u = $this->user('multi');
        $w = $this->workspace();
        $a = app(AccessService::class);
        $room = Room::whereHas('property', fn ($q) => $q->where('workspace_id', $w->id))->first();
        $this->assertTrue($a->allowed($u, 'room.update', $w, $room->property_id, $room->id));
        $this->assertTrue($a->allowed($u, 'payment.record', $w, $room->property_id, $room->id));
        GroupAssignment::where('user_id', $u->id)->where('workspace_id', $w->id)->update(['revoked_at' => now()]);
        $this->assertTrue($a->allowed($u, 'room.update', $w, $room->property_id, $room->id));
        $this->assertFalse($a->allowed($u, 'payment.record', $w, $room->property_id, $room->id));
    }

    public function test_disabled_group_stops_only_its_own_contribution(): void
    {
        AccessGroup::where('workspace_id', $this->workspace()->id)->where('source_template_id', AccessGroup::where('key', 'accountant')->value('id'))->firstOrFail()->update(['status' => 'disabled']);
        $u = $this->user('multi');
        $w = $this->workspace();
        $room = $w->properties->first()->rooms->first();
        $this->assertFalse(app(AccessService::class)->allowed($u, 'payment.record', $w, $room->property_id, $room->id));
        $this->assertTrue(app(AccessService::class)->allowed($u, 'room.update', $w, $room->property_id, $room->id));
    }

    public function test_room_scope_applies_to_list_detail_write_and_other_workspaces(): void
    {
        $u = $this->user('roomstaff');
        $m = Membership::where('user_id', $u->id)->firstOrFail();
        $allowed = Room::findOrFail($m->room_ids[0]);
        $other = Room::where('property_id', $allowed->property_id)->whereKeyNot($allowed->id)->firstOrFail();
        $this->actingAs($u)->get('/ui/properties?context=workspace&workspace_id='.$m->workspace_id)->assertOk()->assertSee($allowed->name)->assertDontSee('>'.$other->name.'<', false);
        $this->get('/ui/room-detail?context=workspace&workspace_id='.$m->workspace_id.'&id='.$other->id)->assertNotFound();
        $this->post('/actions/iot.register', ['context' => 'workspace', 'workspace_id' => $m->workspace_id, 'room_id' => $other->id, 'name' => 'Trái phép', 'serial' => 'DENIED-ROOM'])->assertForbidden();
        $second = Workspace::where('owner_id', $this->user('other')->id)->firstOrFail();
        $this->get('/ui/properties?context=workspace&workspace_id='.$second->id)->assertForbidden();
    }

    public function test_direct_grant_and_expired_or_future_sources(): void
    {
        $w = $this->workspace();
        $room = $w->properties->first()->rooms->first();
        $a = app(AccessService::class);
        $this->assertTrue($a->allowed($this->user('grant'), 'invoice.view', $w, $room->property_id, $room->id));
        $this->assertFalse($a->allowed($this->user('expired'), 'invoice.view', $w, $room->property_id, $room->id));
        DirectGrant::where('user_id', $this->user('grant')->id)->update(['valid_from' => now()->addDay()]);
        $this->assertFalse($a->allowed($this->user('grant'), 'invoice.view', $w, $room->property_id, $room->id));
    }

    public function test_use_permission_does_not_allow_delegation(): void
    {
        $this->actingAs($this->user('accountant'))->post('/actions/grant.create', ['context' => 'workspace', 'workspace_id' => $this->workspace()->id, 'user_id' => $this->user('staff')->id, 'permission' => 'invoice.view', 'valid_from' => now()->toDateTimeString(), 'valid_until' => now()->addDay()->toDateTimeString(), 'reason' => 'Không đủ thẩm quyền'])->assertForbidden();
        $this->assertDatabaseMissing('direct_grants', ['reason' => 'Không đủ thẩm quyền']);
    }

    public function test_new_groups_and_account_multi_group_assignment_work_through_http(): void
    {
        $this->actingAs($this->root())->post('/actions/group.save', ['context' => 'system-admin', 'name' => 'Nhóm tra cứu mới', 'scope' => 'global', 'permissions' => ['account.view']])->assertRedirect()->assertSessionHasNoErrors();
        $g = AccessGroup::where('name', 'Nhóm tra cứu mới')->firstOrFail();
        $target = $this->user('user');
        $this->post('/actions/account.group', ['context' => 'system-admin', 'user_id' => $target->id, 'access_group_ids' => [$g->id, AccessGroup::where('key', 'system')->value('id')]])->assertRedirect()->assertSessionHasNoErrors();
        $target->refresh();
        $this->assertTrue(app(AccessService::class)->global($target, 'account.view'));
        $this->assertTrue(app(AccessService::class)->global($target, 'data.project'));
        $this->assertFalse(app(AccessService::class)->global($target, 'access.bootstrap'));
    }

    public function test_self_elevation_is_rejected_even_for_a_root_actor(): void
    {
        $root = $this->root();
        $this->actingAs($root)->post('/actions/global.grant', ['context' => 'system-admin', 'user_id' => $root->id, 'permission' => 'invoice.view', 'valid_from' => now()->toDateTimeString(), 'valid_until' => now()->addDay()->toDateTimeString(), 'reason' => 'Tự cấp'])->assertForbidden();
        $this->post('/actions/account.group', ['context' => 'system-admin', 'user_id' => $root->id, 'access_group_ids' => [AccessGroup::where('key', 'supreme')->value('id')]])->assertForbidden();
        $this->assertDatabaseMissing('direct_grants', ['reason' => 'Tự cấp']);
    }

    public function test_last_root_is_protected_against_group_removal(): void
    {
        $g = AccessGroup::where('key', 'supreme')->firstOrFail();
        $this->actingAs($this->root())->post('/actions/group.save', ['context' => 'system-admin', 'id' => $g->id, 'name' => $g->name, 'scope' => 'global', 'permissions' => ['data.view'], 'active' => 1])->assertRedirect()->assertSessionHasErrors('permissions');
        $this->assertContains('access.bootstrap', $g->fresh()->permissions);
        $this->assertTrue(app(AccessService::class)->global($this->root(), 'access.delegate'));
    }

    public function test_platform_admin_cannot_lock_a_root_even_when_another_root_exists(): void
    {
        app(AccountGroups::class)->assign($this->root(), $this->user('user'), [AccessGroup::where('key', 'supreme')->value('id')]);
        $this->actingAs($this->user('platform'))->post('/actions/account.status', ['context' => 'platform-admin', 'id' => $this->root()->id, 'status' => 'locked', 'reason' => 'Vượt quyền'])->assertForbidden();
        $this->assertSame('active', $this->root()->fresh()->status);
    }

    public function test_owner_rights_come_from_an_active_configured_group(): void
    {
        $u = $this->user('owner');
        $w = $this->workspace();
        $room = $w->properties->first()->rooms->first();
        $a = app(AccessService::class);
        $this->assertTrue($a->allowed($u, 'rental.activate', $w, $room->property_id, $room->id));
        AccessGroup::where('key', 'owner')->update(['active' => false]);
        $this->assertTrue($a->allowed($u, 'rental.activate', $w, $room->property_id, $room->id));
        AccessGroup::where('workspace_id', $w->id)->where('source_template_id', AccessGroup::where('key', 'owner')->value('id'))->firstOrFail()->update(['status' => 'disabled']);
        $this->assertFalse($a->allowed($u, 'rental.activate', $w, $room->property_id, $room->id));
    }

    public function test_room_rights_do_not_expand_when_grantor_has_only_one_room(): void
    {
        $u = $this->user('roomstaff');
        $m = Membership::where('user_id', $u->id)->firstOrFail();
        $m->update(['permissions' => ['member.invite', 'member.delegate', 'invoice.view']]);
        $this->actingAs($u)->post('/actions/member.invite', ['context' => 'workspace', 'workspace_id' => $m->workspace_id, 'email' => 'scope@example.test', 'access_group_ids' => [], 'permissions' => ['invoice.view'], 'property_ids' => $m->property_ids, 'room_ids' => []])->assertForbidden();
        $this->assertDatabaseMissing('invitations', ['email' => 'scope@example.test']);
    }
}
