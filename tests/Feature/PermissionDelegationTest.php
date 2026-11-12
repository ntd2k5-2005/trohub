<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\Property\Models\Property;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\WorkspaceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PermissionDelegationTest extends TestCase
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
        return Workspace::where('owner_id', $this->user('owner')->id)->orderBy('id')->firstOrFail();
    }

    public function test_picker_uses_categories_and_saves_only_selected_leaf_permissions(): void
    {
        $member = Membership::where('user_id', $this->user('accountant')->id)->firstOrFail();
        $this->actingAs($this->user('owner'));
        $this->get('/ui/team-permissions?context=workspace')->assertOk()->assertSee('permission-picker')->assertSee('Kế toán')->assertDontSee('Thông tin trong phạm vi của bạn');
        $payload = ['context'=>'workspace', 'workspace_id'=>$this->workspace()->id, 'id'=>$member->id, 'access_group_id'=>$member->access_group_id, '_permissions_present'=>1, 'permissions'=>['invoice.view'], 'permission_categories'=>['Kế toán']];
        $this->post('/actions/member.update', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['invoice.view'], $member->fresh()->permissions);
        $access = app(AccessService::class);
        $this->assertTrue($access->allowed($member->user, 'invoice.view', $this->workspace()));
        $this->assertFalse($access->allowed($member->user, 'payment.record', $this->workspace()));
        unset($payload['permissions'], $payload['_permissions_present']);
        $this->post('/actions/member.update', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([], $member->fresh()->permissions);
        $this->assertFalse($access->allowed($member->user, 'invoice.view', $this->workspace()));
        $this->assertSame(0, Permission::whereNull('category_id')->count());
        $this->assertSame('Kế toán', Permission::where('code', 'invoice.view')->first()->categoryRecord->name);
    }

    public function test_delegated_staff_only_see_owned_permissions_and_cannot_expand_scope_or_duration(): void
    {
        $staff = $this->user('staff');
        $member = Membership::where('user_id', $staff->id)->firstOrFail();
        $member->update(['permissions'=>['member.manage','invoice.view'], 'valid_until'=>now()->addDays(2)]);
        $this->actingAs($staff);
        $response = $this->get('/ui/team-permissions?context=workspace')->assertOk();
        $options = collect($response->viewData('screen')['forms'][0]['fields'])->firstWhere('name', 'permissions')['options'];
        $this->assertSame(['member.view','member.invite','member.update','member.end','member.delegate','member.group.create','member.group.update','invoice.view'], array_keys($options));
        $this->assertArrayNotHasKey('invoice.create',$options);
        $payload = ['context'=>'workspace', 'workspace_id'=>$this->workspace()->id, 'email'=>'new@example.test', 'access_group_id'=>AccessGroup::where('name','Tùy chỉnh')->value('id'), 'permissions'=>['invoice.view'], 'property_ids'=>$member->property_ids, 'valid_until'=>now()->addDay()->toDateTimeString()];
        $this->post('/actions/member.invite', array_replace($payload, ['permissions'=>['invoice.manage']]))->assertForbidden();
        $this->post('/actions/member.invite', array_replace($payload, ['property_ids'=>[]]))->assertForbidden();
        $this->post('/actions/member.invite', array_replace($payload, ['valid_until'=>now()->addDays(3)->toDateTimeString()]))->assertForbidden();
        $this->post('/actions/member.invite', $payload)->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_invitation_rechecks_grantor_on_acceptance(): void
    {
        $staff = $this->user('staff');
        $member = Membership::where('user_id', $staff->id)->firstOrFail();
        $member->update(['permissions'=>['member.manage','invoice.view']]);
        $newUser = User::factory()->create();
        $service = app(WorkspaceService::class);
        $token = $service->invite($staff, $this->workspace(), ['email'=>$newUser->email, 'access_group_id'=>AccessGroup::where('name','Tùy chỉnh')->value('id'), 'permissions'=>['invoice.view'], 'property_ids'=>$member->property_ids]);
        $member->update(['permissions'=>['member.manage']]);
        try {
            $service->accept($newUser, $token);
            $this->fail('A revoked permission was delegated.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseMissing('memberships', ['user_id'=>$newUser->id]);
        $member->update(['permissions'=>['member.manage','invoice.view']]);
        $service->accept($newUser, $token);
        $this->assertSame(['invoice.view'], Membership::where('user_id',$newUser->id)->first()->permissions);
    }

    public function test_multiple_grants_are_atomic_and_reject_unowned_rights(): void
    {
        $this->actingAs($this->user('owner'));
        $payload = ['context'=>'workspace', 'workspace_id'=>$this->workspace()->id, 'user_id'=>$this->user('staff')->id, 'permissions'=>['invoice.view','access.manage'], 'valid_from'=>now()->toDateTimeString(), 'valid_until'=>now()->addDay()->toDateTimeString(), 'reason'=>'Kiểm tra giao dịch'];
        $this->post('/actions/grant.create', $payload)->assertForbidden();
        $this->assertDatabaseMissing('direct_grants', ['reason'=>$payload['reason']]);
        $payload['permissions'] = ['invoice.view','payment.record'];
        $payload['property_id'] = Membership::where('workspace_id', $this->workspace()->id)->where('user_id', $this->user('staff')->id)->firstOrFail()->property_ids[0];
        $this->post('/actions/grant.create', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, DirectGrant::where('reason',$payload['reason'])->count());
    }

    public function test_system_admin_cannot_assign_rights_they_do_not_own(): void
    {
        $this->actingAs($this->user('system'));
        $this->post('/actions/group.save', ['context'=>'system-admin', 'name'=>'Vượt quyền', 'scope'=>'global', 'permissions'=>['account.manage']])->assertForbidden();
        $this->post('/actions/account.group', ['context'=>'system-admin', 'user_id'=>$this->user('tenant')->id, 'access_group_id'=>AccessGroup::where('name','Quản trị nền tảng')->value('id')])->assertForbidden();
        $this->post('/actions/global.grant', ['context'=>'system-admin', 'user_id'=>$this->user('tenant')->id, 'permissions'=>['data.manage','account.manage'], 'valid_from'=>now()->toDateTimeString(), 'valid_until'=>now()->addDay()->toDateTimeString(), 'reason'=>'Không hợp lệ'])->assertForbidden();
        $this->assertDatabaseMissing('direct_grants', ['reason'=>'Không hợp lệ']);
    }

    public function test_owner_can_switch_two_workspaces_but_staff_cannot_enter_second(): void
    {
        $second = Workspace::where('owner_id',$this->user('owner')->id)->where('name','Nhà trọ An Phú')->firstOrFail();
        $this->actingAs($this->user('owner'))->get('/ui/properties?context=workspace&workspace_id='.$second->id)
            ->assertOk()->assertSee('workspace-switch')->assertSee('Phòng AP 101')->assertDontSee('Phòng 101');
        $this->get('/ui/properties?context=workspace&workspace_id='.$this->workspace()->id)->assertOk()->assertSee('Phòng 101')->assertDontSee('Phòng AP 101');
        $this->actingAs($this->user('staff'))->get('/ui/properties?context=workspace&workspace_id='.$second->id)->assertForbidden();
    }
}
