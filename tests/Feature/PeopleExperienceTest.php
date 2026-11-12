<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\DataPlatform\Models\AuditEntry;
use App\Modules\Workspace\Models\Invitation;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\WorkspaceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PeopleExperienceTest extends TestCase
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

    private function workspace(): Workspace
    {
        return Workspace::where('owner_id', $this->user('owner')->id)->orderBy('id')->firstOrFail();
    }

    private function inviteData(string $email): array
    {
        return ['context' => 'workspace', 'workspace_id' => $this->workspace()->id, 'email' => $email, 'access_group_id' => AccessGroup::where('name', 'Tùy chỉnh')->value('id'), 'permissions' => ['invoice.view'], 'scope_mode' => 'all'];
    }

    public function test_all_people_tabs_render_and_invalid_tab_is_validated(): void
    {
        foreach (['owner' => ['profile-security' => 'personal', 'team-permissions' => 'workspace'], 'platform' => ['admin-accounts' => 'platform-admin'], 'supreme' => ['access-control' => 'system-admin']] as $account => $screens) {
            $this->actingAs($this->user($account));
            foreach ($screens as $slug => $context) {
                foreach (array_keys(config('people.tabs.'.$slug)) as $section) {
                    $this->get('/ui/'.$slug.'?context='.$context.'&section='.$section)->assertOk()->assertSee('people-tabs');
                }
            }
        }
        $this->get('/ui/access-control?context=system-admin&section=invalid')->assertSessionHasErrors('section');
        $this->actingAs($this->user('owner'))->get('/ui/team-permissions?context=workspace&q=no-person-matches')->assertOk()->assertSee('Chưa có kết quả phù hợp');
    }

    public function test_profile_only_updates_the_authenticated_users_name(): void
    {
        $user = $this->user('tenant');
        $hash = $user->password;
        $this->actingAs($user)->post('/ho-so', ['context' => 'personal', 'name' => 'Tên đã cập nhật', 'id' => $this->user('owner')->id, 'email' => 'attacker@example.test', 'access_group_id' => AccessGroup::where('name', 'Quản trị hệ thống')->value('id'), 'password' => 'NotAllowed1234'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Tên đã cập nhật', $user->fresh()->name);
        $this->assertSame('tenant@trohub.test', $user->fresh()->email);
        $this->assertSame($hash, $user->fresh()->password);
        $this->assertNotSame('Tên đã cập nhật', $this->user('owner')->name);
    }

    public function test_password_change_checks_current_password_and_keeps_credentials_out_of_audit(): void
    {
        $user = $this->user('tenant');
        $this->actingAs($user);
        $data = ['context' => 'personal', 'current_password' => 'WrongPassword', 'password' => 'NewPassword2026', 'password_confirmation' => 'NewPassword2026'];
        $this->post('/actions/profile.password', $data)->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check(config('demo.password'), $user->fresh()->password));
        $data['current_password'] = config('demo.password');
        $this->post('/actions/profile.password', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewPassword2026', $user->fresh()->password));
        $audit = AuditEntry::where('action', 'password.changed')->firstOrFail();
        $this->assertStringNotContainsString('NewPassword2026', $audit->toJson());
    }

    public function test_invitation_renewal_invalidates_old_link_and_cancellation_prevents_acceptance(): void
    {
        $recipient = User::factory()->create();
        $this->actingAs($this->user('owner'));
        $this->post('/actions/member.invite', $this->inviteData($recipient->email))->assertRedirect()->assertSessionHasNoErrors();
        $oldUrl = session('invitation_url');
        $invite = Invitation::where('email', $recipient->email)->firstOrFail();
        $this->post('/actions/member.invite', $this->inviteData($recipient->email))->assertSessionHasErrors('email');
        $this->post('/actions/invitation.renew', ['context' => 'workspace', 'workspace_id' => $this->workspace()->id, 'id' => $invite->id])->assertRedirect()->assertSessionHasNoErrors();
        $newUrl = session('invitation_url');
        $this->assertNotSame($oldUrl, $newUrl);
        $this->actingAs($recipient)->get($oldUrl)->assertNotFound();
        $this->get($newUrl)->assertOk()->assertSee($recipient->email);
        $this->actingAs($this->user('other'))->get($newUrl)->assertForbidden();
        $this->post('/actions/invitation.cancel', ['context' => 'workspace', 'workspace_id' => Workspace::where('owner_id', $this->user('other')->id)->value('id'), 'id' => $invite->id])->assertNotFound();
        $this->actingAs($this->user('owner'))->post('/actions/invitation.cancel', ['context' => 'workspace', 'workspace_id' => $this->workspace()->id, 'id' => $invite->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($recipient)->post($newUrl)->assertNotFound();
        $this->assertDatabaseMissing('memberships', ['user_id' => $recipient->id]);
    }

    public function test_invite_cannot_target_owner_or_an_active_member_and_explicit_scope_cannot_be_empty(): void
    {
        $this->actingAs($this->user('owner'));
        foreach (['owner', 'staff'] as $name) {
            $this->post('/actions/member.invite', $this->inviteData($this->user($name)->email))->assertSessionHasErrors('email');
        }
        $this->post('/actions/member.invite', array_replace($this->inviteData('new@example.test'), ['scope_mode' => 'selected', 'property_ids' => []]))->assertSessionHasErrors('property_ids');
        $this->assertDatabaseMissing('invitations', ['email' => 'new@example.test']);
    }

    public function test_ending_then_rejoining_does_not_resurrect_old_grants(): void
    {
        $staff = $this->user('staff');
        $workspace = $this->workspace();
        $member = Membership::where('user_id', $staff->id)->firstOrFail();
        $service = app(WorkspaceService::class);
        $service->grant($this->user('owner'), $workspace, ['user_id' => $staff->id, 'permission' => 'invoice.create', 'property_id' => $member->property_ids[0], 'valid_from' => now()->toDateTimeString(), 'valid_until' => now()->addDay()->toDateTimeString(), 'reason' => 'Bổ sung']);
        $service->endMembership($this->user('owner'), $workspace, $member->id);
        $this->assertNotNull(DirectGrant::where('user_id', $staff->id)->first()->revoked_at);
        $token = $service->invite($this->user('owner'), $workspace, $this->inviteData($staff->email));
        $service->accept($staff, $token);
        $joined = Membership::where('user_id', $staff->id)->where('workspace_id', $workspace->id)->where('status', 'active')->firstOrFail();
        $this->assertNotSame($member->id, $joined->id);
        $this->assertSame('ended', $member->fresh()->status);
        $this->assertSame(['invoice.view'], $joined->permissions);
        $this->assertFalse(app(AccessService::class)->allowed($staff, 'invoice.manage', $workspace));
    }

    public function test_account_search_includes_email_and_protected_actions_are_hidden_and_denied(): void
    {
        $this->actingAs($this->user('platform'));
        $response = $this->get('/ui/admin-accounts?context=platform-admin&q=tenant%40trohub.test')->assertOk();
        $this->assertCount(1, $response->viewData('screen')['records']);
        $this->get('/ui/admin-accounts?context=platform-admin&q=system%40trohub.test')->assertOk()->assertDontSee('Khóa tài khoản');
        $this->post('/actions/account.status', ['context' => 'platform-admin', 'id' => $this->user('system')->id, 'status' => 'locked', 'reason' => 'Không được phép'])->assertForbidden();
        $this->post('/actions/account.status', ['context' => 'platform-admin', 'id' => $this->user('tenant')->id, 'status' => 'locked', 'reason' => 'Kiểm tra khóa'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->user('tenant'))->get('/ui/profile-security?context=personal')->assertForbidden();
        $this->actingAs($this->user('platform'))->post('/actions/account.status', ['context' => 'platform-admin', 'id' => $this->user('tenant')->id, 'status' => 'active', 'reason' => 'Kiểm tra mở khóa'])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_group_changes_are_audited_and_cannot_remove_own_management_access(): void
    {
        $system = $this->user('supreme');
        $this->actingAs($system);
        $this->post('/actions/group.save', ['context' => 'system-admin', 'name' => 'Nhóm xem dữ liệu', 'scope' => 'global', 'permissions' => ['data.manage']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_entries', ['actor_id' => $system->id, 'action' => 'group.save']);
        $this->get('/ui/access-control?context=system-admin&section=history')->assertOk()->assertSee('Nhóm xem dữ liệu');
        $this->post('/actions/group.save', ['context' => 'system-admin', 'id' => AccessGroup::where('key','supreme')->value('id'), 'name' => 'Quản trị tối cao', 'scope' => 'global', 'permissions' => []])->assertSessionHasErrors('permissions');
        $this->get('/ui/access-control?context=system-admin&section=catalog')->assertOk()->assertDontSee('Mã quyền');
    }

    public function test_disabled_permission_does_not_hide_staff_from_owner_or_prevent_ending_access(): void
    {
        Permission::where('code', 'property.view')->update(['active' => false]);
        $this->actingAs($this->user('owner'))->get('/ui/team-permissions?context=workspace')->assertOk()->assertSee('staff@trohub.test');
        $member = Membership::where('user_id', $this->user('staff')->id)->firstOrFail();
        $this->post('/actions/member.end', ['context' => 'workspace', 'workspace_id' => $this->workspace()->id, 'id' => $member->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ended', $member->fresh()->status);
    }

    public function test_invitation_link_never_outlives_access_expiration(): void
    {
        $recipient = User::factory()->create();
        $until = now()->addHour()->startOfSecond();
        $this->actingAs($this->user('owner'))->post('/actions/member.invite', $this->inviteData($recipient->email) + ['valid_until' => $until->toDateTimeString()])->assertRedirect()->assertSessionHasNoErrors();
        $invite = Invitation::where('email', $recipient->email)->firstOrFail();
        $this->assertTrue($invite->expires_at->equalTo($until));
        $this->travel(2)->hours();
        $this->actingAs($recipient)->get(session('invitation_url'))->assertNotFound();
    }
}
