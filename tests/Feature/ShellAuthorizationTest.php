<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\Notification\Models\UserNotification;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShellAuthorizationTest extends TestCase
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

    public function test_accountant_navigation_and_direct_requests_enforce_permissions(): void
    {
        $this->actingAs($this->user('accountant'));
        $response = $this->get('/ui/workspace-dashboard?context=workspace')->assertOk();
        $items = $response->viewData('shell')['items']->pluck('slug');
        $this->assertTrue($items->contains('billing'));
        $this->assertFalse($items->contains('properties'));
        $this->assertFalse($items->contains('team-permissions'));
        $this->assertArrayNotHasKey('Phòng trong phạm vi', $response->viewData('screen')['stats']);
        $this->get('/ui/properties?context=workspace')->assertForbidden();
        $this->post('/actions/property.save', ['context' => 'workspace', 'name' => 'Trái phép', 'address' => 'Địa chỉ', 'district' => 'Khu vực'])->assertForbidden();
    }

    public function test_tenant_cannot_switch_into_management_or_admin_contexts(): void
    {
        $this->actingAs($this->user('tenant'));
        $response = $this->get('/ui/personal-dashboard?context=personal')->assertOk();
        $this->assertSame(['public', 'personal'], array_keys($response->viewData('contexts')));
        foreach (['workspace', 'platform-admin', 'system-admin'] as $context) {
            $this->get('/khong-gian?context='.$context)->assertForbidden();
        }
    }

    public function test_partial_admin_switch_chooses_an_authorized_home(): void
    {
        $group = AccessGroup::create(['name' => 'Chỉ vận hành dữ liệu', 'scope' => 'global', 'permissions' => ['data.manage']]);
        $user = User::factory()->create(['access_group_id' => $group->id]);
        $this->actingAs($user)->get('/khong-gian?context=system-admin')->assertRedirect('/ui/data-platform?context=system-admin');
        $this->get('/ui/access-control?context=system-admin')->assertForbidden();
    }

    public function test_subscription_reviewer_has_home_without_unrelated_admin_statistics(): void
    {
        $group = AccessGroup::create(['name' => 'Đối soát riêng', 'scope' => 'global', 'permissions' => ['subscription.approve']]);
        $this->actingAs(User::factory()->create(['access_group_id' => $group->id]));
        $this->get('/khong-gian?context=platform-admin')->assertRedirect('/ui/admin-dashboard?context=platform-admin');
        $response = $this->get('/ui/admin-dashboard?context=platform-admin')->assertOk();
        $stats = $response->viewData('screen')['stats'];
        $this->assertSame(['Yêu cầu gói chưa hoàn tất' => 0, 'Chờ đối soát chuyển khoản' => 0, 'Hồ sơ gói cũ chờ xem' => 0], $stats);
        $this->assertArrayNotHasKey('Tài khoản', $stats);
        $this->assertArrayNotHasKey('Phản ánh chưa xử lý xong', $stats);
        $this->assertArrayNotHasKey('Tin đăng chờ kiểm duyệt', $stats);
        $this->get('/ui/admin-accounts?context=platform-admin')->assertForbidden();
    }

    public function test_stale_workspace_session_recovers_but_explicit_unauthorized_id_is_rejected(): void
    {
        $this->actingAs($this->user('staff'))->withSession(['workspace_id' => 999999]);
        $this->get('/ui/personal-dashboard?context=personal')->assertOk();
        $response = $this->get('/ui/workspace-dashboard?context=workspace')->assertOk();
        $this->assertStringContainsString('workspace_id=', $response->viewData('shell')['home_url']);
        $this->get('/khong-gian?context=workspace&workspace_id=999999')->assertForbidden();
        $this->get('/khong-gian?context=workspace&workspace_id[]=1')->assertForbidden();
    }

    public function test_permission_revocation_updates_navigation_and_blocks_bookmarked_routes(): void
    {
        $user = $this->user('staff');
        $this->actingAs($user)->get('/ui/properties?context=workspace')->assertOk();
        $member = Membership::where('user_id', $user->id)->firstOrFail();
        $member->group->update(['permissions' => []]);
        $this->get('/ui/workspace-dashboard?context=workspace')->assertForbidden();
        $response = $this->get('/ui/personal-dashboard?context=personal')->assertOk();
        $this->assertFalse($response->viewData('shell')['items']->pluck('slug')->contains('properties'));
        $this->get('/ui/properties?context=workspace')->assertForbidden();
        $member->update(['valid_until' => now()->subMinute()]);
        $this->get('/khong-gian?context=workspace')->assertForbidden();
        $this->get('/ui/personal-dashboard?context=personal')->assertOk();
    }

    public function test_navigation_keeps_explicit_workspace_across_tabs_and_switches_home(): void
    {
        $user = $this->user('owner');
        $first = Workspace::where('owner_id', $user->id)->firstOrFail();
        $second = Workspace::where('owner_id', $this->user('other')->id)->firstOrFail();
        Membership::create(['workspace_id' => $second->id, 'user_id' => $user->id, 'access_group_id' => AccessGroup::where('name', 'Kế toán')->value('id'), 'status' => 'active', 'property_ids' => null]);
        $this->actingAs($user)->withSession(['workspace_id' => $second->id]);
        $response = $this->get('/ui/properties?context=workspace&workspace_id='.$first->id)->assertOk();
        foreach ($response->viewData('shell')['items'] as $item) {
            $this->assertStringContainsString('workspace_id='.$first->id, $item['url']);
        }
        $this->get('/khong-gian?context=workspace&workspace_id='.$second->id)->assertRedirect('/ui/workspace-dashboard?context=workspace&workspace_id='.$second->id);
        $this->get('/ui/properties?context=workspace&workspace_id='.$second->id)->assertForbidden();
    }

    public function test_notification_badge_is_personal_and_disabled_modules_are_not_linked(): void
    {
        UserNotification::create(['user_id' => $this->user('other')->id, 'title' => 'Riêng tư', 'body' => 'Không được lộ']);
        config(['features.iot' => false]);
        $this->actingAs($this->user('tenant'));
        $response = $this->get('/ui/personal-dashboard?context=personal')->assertOk();
        $this->assertSame(0, $response->viewData('shell')['unread']);
        $this->assertFalse($response->viewData('shell')['items']->pluck('slug')->contains('room-safety'));
        $this->get('/ui/room-safety?context=personal')->assertNotFound();
    }
}
