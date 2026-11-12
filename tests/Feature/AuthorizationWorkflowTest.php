<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\AccessControl\AccountGroups;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\RootProtection;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Connection\ConversationRepository;
use App\Modules\Connection\ConversationService;
use App\Modules\IoT\IoTService;
use App\Modules\IoT\Models\Device;
use App\Modules\Listing\Models\Listing;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Modules\Subscription\SubscriptionService;
use App\Modules\Subscription\TrialPurge;
use App\Modules\Workspace\Models\Invitation;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\WorkspaceService;
use App\Support\ScopedRepository;
use Database\Seeders\AccessSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthorizationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function u(string $key): User
    {
        return User::where('email', $key.'@trohub.test')->firstOrFail();
    }

    private function w(): Workspace
    {
        return Workspace::where('owner_id', $this->u('owner')->id)->firstOrFail();
    }

    private function rooms()
    {
        return Room::whereHas('property', fn ($p) => $p->where('workspace_id', $this->w()->id))->get();
    }

    private function context(): array
    {
        return ['context' => 'workspace', 'workspace_id' => $this->w()->id, 'target_workspace_id' => $this->w()->id];
    }

    private function customMember(User $user, array $permissions = []): Membership
    {
        return Membership::updateOrCreate(['workspace_id' => $this->w()->id, 'user_id' => $user->id], ['access_group_id' => AccessGroup::where('key', 'custom')->value('id'), 'permissions' => $permissions, 'permissions_mode' => 'additive', 'property_ids' => null, 'room_ids' => null, 'status' => 'active', 'valid_until' => null]);
    }

    public function test_personal_group_with_all_codes_cannot_replace_last_platform_root(): void
    {
        $g = AccessGroup::create(['name' => 'Nhóm cùng tên quản trị', 'scope' => 'personal', 'permissions' => array_keys(config('authorization.catalog'))]);
        GroupAssignment::create(['user_id' => $this->u('user')->id, 'access_group_id' => $g->id, 'scope_key' => 'personal-all']);
        $this->assertFalse(app(AccessService::class)->global($this->u('user'), 'access.bootstrap'));
        $this->assertSame([$this->u('supreme')->id], app(RootProtection::class)->permanentRoots());
        $rootGroup = AccessGroup::where('key', 'supreme')->firstOrFail();
        $this->actingAs($this->u('supreme'))->post('/actions/group.save', ['context' => 'system-admin', 'id' => $rootGroup->id, 'name' => $rootGroup->name, 'scope' => 'global', 'permissions' => $rootGroup->permissions, 'active' => 0])->assertSessionHasErrors('permissions');
    }

    public function test_catalog_seeding_preserves_runtime_group_and_permission_edits(): void
    {
        $permission = Permission::where('code', 'invoice.issue')->firstOrFail();
        $permission->update(['name' => 'Duyệt phát hành', 'active' => false, 'delegable' => false]);
        $group = AccessGroup::where('key', 'manager')->firstOrFail();
        $group->update(['name' => 'Vận hành tại chỗ', 'permissions' => ['workspace.view']]);
        $this->seed(AccessSeeder::class);
        $this->assertSame('Duyệt phát hành', $permission->fresh()->name);
        $this->assertFalse($permission->fresh()->active);
        $this->assertFalse((bool) $permission->fresh()->delegable);
        $this->assertSame('Vận hành tại chỗ', $group->fresh()->name);
        $this->assertSame(['workspace.view'], $group->fresh()->permissions);
    }

    public function test_mixed_direct_grants_resolve_personal_and_platform_scope_separately(): void
    {
        $u = $this->u('user');
        $this->actingAs($this->u('supreme'))->post('/actions/global.grant', ['context' => 'system-admin', 'user_id' => $u->id, 'permissions' => ['invoice.issue', 'iot.configure'], 'scope_type' => 'auto', 'valid_from' => now()->subMinute()->toDateTimeString(), 'valid_until' => now()->addDay()->toDateTimeString(), 'reason' => 'Quyền ở các phạm vi độc lập'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(AccessService::class)->global($u, 'invoice.issue'));
        $this->assertTrue(app(AccessService::class)->personal($u, 'iot.configure'));
        $this->assertFalse(app(AccessService::class)->global($u, 'iot.configure'));
        $this->assertDatabaseHas('direct_grants', ['user_id' => $u->id, 'permission' => 'invoice.issue', 'scope_type' => 'platform']);
        $this->assertDatabaseHas('direct_grants', ['user_id' => $u->id, 'permission' => 'iot.configure', 'scope_type' => 'personal']);
    }

    public function test_multi_context_tenant_must_switch_to_work_context_for_other_rooms(): void
    {
        $u = $this->u('tenant');
        $member = $this->customMember($u);
        $member->update(['access_group_id' => AccessGroup::where('key', 'technician')->value('id')]);
        $ownRooms = app(ScopedRepository::class)->currentRoomIds($u);
        $room = $this->rooms()->first(fn ($r) => ! in_array($r->id, $ownRooms, true));
        app(IoTService::class)->register($this->u('owner'), $room->id, 'Thiết bị nơi làm việc', 'TEST-CONTEXT-DEVICE');
        $device = Device::where('serial', 'TEST-CONTEXT-DEVICE')->firstOrFail();
        $this->actingAs($u)->post('/actions/iot.configure', ['context' => 'personal', 'id' => $device->id, 'heartbeat' => 60])->assertForbidden();
        $this->post('/actions/iot.configure', $this->context() + ['id' => $device->id, 'heartbeat' => 60])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $device->fresh()->config_version);
    }

    public function test_trial_b_preserves_scoped_authority_and_global_accounts(): void
    {
        $owner = $this->u('user');
        $target = $this->u('grant');
        $w = app(SubscriptionService::class)->trial($owner, 'Khu dùng thử kiểm tra quyền');
        $p = Property::create(['workspace_id' => $w->id, 'name' => 'Nhà dùng thử', 'address' => 'Địa chỉ dùng thử', 'district' => 'Quận thử']);
        $room = Room::create(['property_id' => $p->id, 'name' => 'Phòng thử', 'area' => 20, 'rent' => 1000000, 'deposit' => 0, 'capacity' => 2]);
        $g = AccessGroup::create(['workspace_id' => $w->id, 'name' => 'Nhóm dùng thử', 'scope' => 'workspace', 'permissions' => ['iot.view']]);
        Membership::create(['workspace_id' => $w->id, 'user_id' => $target->id, 'access_group_id' => $g->id, 'status' => 'active']);
        GroupAssignment::create(['user_id' => $target->id, 'access_group_id' => $g->id, 'workspace_id' => $w->id, 'property_id' => $p->id, 'room_id' => $room->id, 'scope_key' => 'trial-room']);
        app(WorkspaceService::class)->grant($owner, $w, ['user_id' => $target->id, 'permission' => 'iot.view', 'property_id' => $p->id, 'room_id' => $room->id, 'valid_from' => now()->subMinute()->toDateTimeString(), 'valid_until' => now()->addDay()->toDateTimeString(), 'reason' => 'Quyền dùng thử']);
        $w->update(['expires_at' => now()->subDays(10), 'retention_until' => now()->subDay(), 'status' => 'expired']);
        $this->assertSame(0, app(TrialPurge::class)->run());
        $this->assertNull($w->fresh()->purged_at);
        $this->assertDatabaseHas('group_assignments', ['workspace_id' => $w->id]);
        $this->assertDatabaseHas('direct_grants', ['workspace_id' => $w->id]);
        $this->assertDatabaseHas('access_groups', ['id' => $g->id]);
        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_locking_does_not_allow_lower_admin_to_unlock_privileged_account(): void
    {
        app(AccountGroups::class)->assign($this->u('supreme'), $this->u('user'), [AccessGroup::where('key', 'supreme')->value('id')]);
        $this->actingAs($this->u('supreme'))->post('/actions/account.status', ['context' => 'platform-admin', 'id' => $this->u('user')->id, 'status' => 'locked', 'reason' => 'Tạm ngừng'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->u('platform'))->post('/actions/account.status', ['context' => 'platform-admin', 'id' => $this->u('user')->id, 'status' => 'active', 'reason' => 'Vượt cấp'])->assertForbidden();
        $this->assertSame('locked', $this->u('user')->fresh()->status);
    }

    public function test_chat_read_and_send_are_separate_from_lead_management(): void
    {
        $listing = Listing::whereIn('room_id', $this->rooms()->where('status', 'vacant')->pluck('id'))->where('status', 'published')->firstOrFail();
        $connection = app(ConversationService::class)->open($this->u('tenant'), $listing->id, 'chat', null, null);
        $thread = app(ConversationRepository::class)->thread($connection->id);
        $user = $this->u('user');
        $this->customMember($user, ['chat.view']);
        $this->actingAs($user)->getJson('/tro-chuyen/'.$thread->id.'/tin-nhan')->assertOk()->assertJsonPath('can_send', false);
        $this->postJson('/tro-chuyen/'.$thread->id.'/tin-nhan', ['body' => 'Không được gửi', 'client_id' => (string) Str::uuid()])->assertForbidden();
        $this->get('/ui/conversations?context=workspace&workspace_id='.$this->w()->id.'&id='.$thread->id)->assertOk();
        $this->get('/ui/workspace-leads?context=workspace&workspace_id='.$this->w()->id)->assertForbidden();
    }

    public function test_each_additional_group_has_independent_room_and_time_limits(): void
    {
        $target = $this->u('user');
        $this->customMember($target);
        $rooms = $this->rooms();
        $first = $rooms[0];
        $second = $rooms[1];
        $this->actingAs($this->u('owner'));
        $this->post('/actions/group.assignment.save', $this->context() + ['user_id' => $target->id, 'access_group_id' => AccessGroup::where('key', 'accountant')->value('id'), 'room_id' => $first->id, 'valid_from' => now()->subMinute()->toDateTimeString(), 'valid_until' => now()->addHour()->toDateTimeString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/actions/group.assignment.save', $this->context() + ['user_id' => $target->id, 'access_group_id' => AccessGroup::where('key', 'technician')->value('id'), 'room_id' => $second->id, 'valid_from' => now()->subMinute()->toDateTimeString(), 'valid_until' => now()->addHours(2)->toDateTimeString()])->assertRedirect()->assertSessionHasNoErrors();
        $a = app(AccessService::class);
        $w = $this->w();
        $this->assertTrue($a->allowed($target, 'payment.record', $w, $first->property_id, $first->id));
        $this->assertFalse($a->allowed($target, 'payment.record', $w, $second->property_id, $second->id));
        $this->assertTrue($a->allowed($target, 'iot.configure', $w, $second->property_id, $second->id));
        $this->travel(1)->hours();
        $this->assertFalse($a->allowed($target, 'payment.record', $w, $first->property_id, $first->id));
        $this->assertTrue($a->allowed($target, 'iot.configure', $w, $second->property_id, $second->id));
        $assignment = GroupAssignment::where('user_id', $target->id)->where('room_id', $second->id)->firstOrFail();
        $this->post('/actions/group.assignment.revoke', $this->context() + ['id' => $assignment->id, 'target_workspace_id' => $w->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($a->allowed($target, 'iot.configure', $w, $second->property_id, $second->id));
    }

    public function test_member_scope_and_grantor_expiry_bound_additional_groups(): void
    {
        $actor = $this->u('staff');
        $m = Membership::where('user_id', $actor->id)->firstOrFail();
        $room = $this->rooms()[0];
        $m->update(['permissions' => ['member.delegate', 'invoice.view'], 'valid_until' => now()->addHour()]);
        $target = $this->u('user');
        $this->customMember($target);
        $g = AccessGroup::create(['name' => 'Tra cứu hóa đơn', 'scope' => 'workspace', 'permissions' => ['invoice.view'], 'workspace_id' => $this->w()->id]);
        $this->actingAs($actor);
        $data = $this->context() + ['target_workspace_id' => $this->w()->id, 'user_id' => $target->id, 'access_group_id' => $g->id, 'property_id' => $room->property_id, 'valid_from' => now()->subMinute()->toDateTimeString(), 'valid_until' => now()->addHours(2)->toDateTimeString()];
        $this->post('/actions/group.assignment.save', $data)->assertForbidden();
        $data['valid_until'] = now()->addMinutes(30)->toDateTimeString();
        $this->post('/actions/group.assignment.save', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('group_assignments', ['user_id' => $target->id, 'access_group_id' => $g->id, 'property_id' => $room->property_id]);
    }

    public function test_disabling_a_primary_group_does_not_revoke_independent_direct_rights(): void
    {
        $u = $this->u('accountant');
        $m = Membership::where('user_id', $u->id)->firstOrFail();
        $m->update(['permissions_mode' => 'additive', 'permissions' => []]);
        DirectGrant::create(['user_id' => $u->id, 'workspace_id' => $this->w()->id, 'permission' => 'iot.view', 'valid_from' => now()->subMinute(), 'valid_until' => now()->addDay(), 'grantor_id' => $this->u('owner')->id, 'reason' => 'Kiểm tra độc lập']);
        $m->group->update(['status' => 'disabled']);
        $r = $this->rooms()[0];
        $a = app(AccessService::class);
        $this->assertFalse($a->allowed($u, 'payment.record', $this->w(), $r->property_id, $r->id));
        $this->assertTrue($a->allowed($u, 'iot.view', $this->w(), $r->property_id, $r->id));
    }

    public function test_workspace_group_creation_edit_and_invitation_recheck_use_real_permissions(): void
    {
        $owner = $this->u('owner');
        $target = User::factory()->create();
        $this->actingAs($owner);
        $this->post('/actions/workspace.group.save', $this->context() + ['name' => 'Tiếp nhận tại khu', 'permissions' => ['connection.view']])->assertRedirect()->assertSessionHasNoErrors();
        $g = AccessGroup::where('name', 'Tiếp nhận tại khu')->firstOrFail();
        $this->post('/actions/member.invite', $this->context() + ['email' => $target->email, 'access_group_ids' => [$g->id, AccessGroup::where('key', 'accountant')->value('id')], 'permissions' => [], 'property_ids' => [(string) $this->rooms()[0]->property_id]])->assertRedirect()->assertSessionHasNoErrors();
        $invite = Invitation::where('email', $target->email)->firstOrFail();
        $this->actingAs($target)->post('/loi-moi-nhan/'.$invite->id, ['decision' => 'accept'])->assertRedirect()->assertSessionHasNoErrors();
        $r = $this->rooms()[0];
        $a = app(AccessService::class);
        $this->assertTrue($a->allowed($target, 'connection.view', $this->w(), $r->property_id, $r->id));
        $this->assertTrue($a->allowed($target, 'invoice.issue', $this->w(), $r->property_id, $r->id));
        $this->actingAs($owner)->post('/actions/workspace.group.save', $this->context() + ['id' => $g->id, 'name' => $g->name, 'permissions' => [], 'active' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($a->allowed($target, 'connection.view', $this->w(), $r->property_id, $r->id));
        $change = \App\Modules\AccessControl\Models\GroupChange::where('access_group_id', $g->id)->latest('id')->firstOrFail();
        $this->post('/actions/group.change.apply', $this->context() + ['id' => $change->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($a->allowed($target, 'connection.view', $this->w(), $r->property_id, $r->id));
        $this->assertTrue($a->allowed($target, 'invoice.issue', $this->w(), $r->property_id, $r->id));
    }

    public function test_view_only_personnel_can_read_directory_but_no_write_controls_or_actions(): void
    {
        $u = $this->u('user');
        $this->customMember($u, ['member.view']);
        $this->actingAs($u)->get('/ui/team-permissions?context=workspace&workspace_id='.$this->w()->id)->assertOk()->assertSee('staff@trohub.test')->assertDontSee('Mời nhân viên')->assertDontSee('Chỉnh sửa quyền và phạm vi');
        $this->post('/actions/member.end', $this->context() + ['id' => Membership::where('user_id', $this->u('staff')->id)->value('id')])->assertForbidden();
    }

    public function test_publish_permission_does_not_allow_content_changes(): void
    {
        $u = $this->u('user');
        $this->customMember($u, ['listing.view', 'listing.publish']);
        $l = Listing::whereIn('room_id', $this->rooms()->where('status', 'vacant')->pluck('id'))->firstOrFail();
        $l->update(['status' => 'paused']);
        $this->actingAs($u)->get('/ui/workspace-listings?context=workspace&workspace_id='.$this->w()->id)->assertOk()->assertSee('Đăng tin')->assertDontSee('Cập nhật tin');
        $this->post('/actions/listing.status', $this->context() + ['id' => $l->id, 'status' => 'published'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/actions/listing.save', $this->context() + ['id' => $l->id, 'room_id' => $l->room_id, 'title' => 'Không được sửa', 'description' => 'Nội dung trái quyền', 'status' => 'published'])->assertForbidden();
        $this->assertNotSame('Không được sửa', $l->fresh()->title);
    }

    public function test_tenant_personal_device_grant_never_becomes_platform_authority(): void
    {
        $u = $this->u('tenant');
        $d = Device::whereHas('assignments', fn ($q) => $q->where('room_id', app(ScopedRepository::class)->currentRoomIds($u)[0])->whereNull('effective_to'))->firstOrFail();
        $this->actingAs($this->u('supreme'))->post('/actions/global.grant', ['context' => 'system-admin', 'user_id' => $u->id, 'permission' => 'iot.configure', 'scope_type' => 'personal', 'valid_from' => now()->subMinute()->toDateTimeString(), 'valid_until' => now()->addDay()->toDateTimeString(), 'reason' => 'Cho phép cài chu kỳ phòng thuê'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(app(AccessService::class)->global($u, 'iot.configure'));
        $this->actingAs($u)->post('/actions/iot.configure', ['context' => 'personal', 'id' => $d->id, 'heartbeat' => 60])->assertRedirect()->assertSessionHasNoErrors();
        $otherRoom = $this->rooms()->first(fn ($room) => $room->id !== $d->assignments()->whereNull('effective_to')->first()->room_id);
        app(IoTService::class)->register($this->u('owner'), $otherRoom->id, 'Thiết bị phòng khác', 'TEST-OTHER-DEVICE');
        $other = Device::where('serial', 'TEST-OTHER-DEVICE')->firstOrFail();
        $this->post('/actions/iot.configure', ['context' => 'personal', 'id' => $other->id, 'heartbeat' => 60])->assertForbidden();
        $d->update(['kind' => 'door_lock']);
        $this->post('/actions/iot.configure', ['context' => 'personal', 'id' => $d->id, 'heartbeat' => 60])->assertSessionHasErrors('heartbeat');
        $this->get('/ui/room-safety?context=personal')->assertOk()->assertDontSee($d->name);
    }

    public function test_exports_are_scoped_and_require_export_permission(): void
    {
        $tenant = $this->u('tenant');
        $own = Invoice::whereHas('rental', fn ($r) => $r->where('tenant_id', $tenant->id))->whereNotNull('issued_at')->firstOrFail();
        $this->actingAs($tenant)->get('/xuat-du-lieu/invoice?context=personal')->assertOk()->assertDownload();
        $content = $this->get('/xuat-du-lieu/invoice?context=personal')->streamedContent();
        $this->assertStringContainsString($own->rental->room->name, $content);
        $this->actingAs($this->u('other'))->get('/xuat-du-lieu/invoice?context=workspace&workspace_id='.$this->w()->id)->assertForbidden();
        $u = $this->u('grant');
        $this->actingAs($u)->get('/xuat-du-lieu/invoice?context=workspace&workspace_id='.$this->w()->id)->assertForbidden();
        $this->actingAs($this->u('owner'))->get('/xuat-du-lieu/payment?context=workspace&workspace_id='.$this->w()->id)->assertOk()->assertDownload();
    }

    public function test_settings_accept_only_configured_values_and_technical_admin_cannot_delegate(): void
    {
        $data = ['context' => 'system-admin', 'page_size' => 15, 'chat_interval' => 2500, 'device_heartbeat' => 60, 'security_policy' => 'allow_all'];
        $this->actingAs($this->u('system'))->post('/actions/system.settings', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('system_settings', ['key' => 'page_size', 'value' => '15']);
        $this->assertDatabaseMissing('system_settings', ['key' => 'security_policy']);
        $this->post('/actions/system.settings', array_replace($data, ['device_heartbeat' => 0]))->assertSessionHasErrors('device_heartbeat');
        $this->post('/actions/account.group', ['context' => 'system-admin', 'user_id' => $this->u('user')->id, 'access_group_ids' => [AccessGroup::where('key', 'supreme')->value('id')]])->assertForbidden();
    }

    public function test_paid_expiration_keeps_history_but_stops_money_writes(): void
    {
        $w = $this->w();
        $w->update(['expires_at' => now()->subMinute(), 'status' => 'expired']);
        $count = Invoice::count();
        $this->actingAs($this->u('owner'))->get('/ui/billing?context=workspace&workspace_id='.$w->id)->assertForbidden();
        $invoice = Invoice::firstOrFail();
        $this->post('/actions/payment.create', $this->context() + ['id' => $invoice->id, 'amount' => 1, 'method' => 'cash', 'reference' => 'EXPIRED-REF'])->assertForbidden();
        $this->assertDatabaseMissing('payments', ['reference' => 'EXPIRED-REF']);
        $this->assertSame($count, Invoice::count());
        $this->assertNull($w->fresh()->purged_at);
    }

    public function test_last_root_group_and_critical_permission_cannot_be_disabled(): void
    {
        $g = AccessGroup::where('key', 'supreme')->firstOrFail();
        $this->actingAs($this->u('supreme'));
        $this->post('/actions/group.save', ['context' => 'system-admin', 'id' => $g->id, 'name' => $g->name, 'scope' => 'global', 'permissions' => $g->permissions, 'active' => 0])->assertSessionHasErrors('permissions');
        $this->post('/actions/permission.save', ['context' => 'system-admin', 'code' => 'access.bootstrap', 'name' => 'Quản trị cấp cao', 'category' => 'Phân quyền', 'delegable' => 0, 'active' => 0])->assertSessionHasErrors('active');
        $this->assertTrue($g->fresh()->active);
    }
}
