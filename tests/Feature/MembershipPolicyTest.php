<?php

namespace Tests\Feature;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupAssignment;
use App\Modules\Workspace\MembershipLifecycle;
use App\Modules\Workspace\Models\Invitation;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\MembershipHandover;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\StaffCapacity;
use App\Modules\Workspace\WorkspaceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipPolicyTest extends TestCase
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

    private function invite(User $target, ?Workspace $workspace = null): Membership
    {
        $workspace ??= $this->workspace();
        $token = app(WorkspaceService::class)->invite($workspace->owner, $workspace, ['email' => $target->email, 'access_group_id' => AccessGroup::where('key', 'custom')->value('id'), 'permissions' => ['invoice.view']]);
        app(WorkspaceService::class)->accept($target, $token);
        return Membership::where('workspace_id', $workspace->id)->where('user_id', $target->id)->latest('id')->firstOrFail();
    }

    public function test_quota_full_accepts_invitation_without_granting_rights_and_requires_explicit_activation(): void
    {
        $w = $this->workspace();
        $used = count(app(StaffCapacity::class)->activeAccounts($w->owner_id));
        config(['business.limits.yearly.staff' => $used]);
        $target = User::factory()->create();
        $m = $this->invite($target);
        $this->assertSame('pending', $m->status);
        $this->assertNotNull(Invitation::find($m->invitation_id)->accepted_at);
        $this->assertFalse(app(Authorization::class)->allowed($target, 'invoice.view', $w));
        $this->assertSame(0, GroupAssignment::where('membership_id', $m->id)->count());
        config(['business.limits.yearly.staff' => $used + 1]);
        $this->assertSame('pending', $m->fresh()->status);
        $this->actingAs($w->owner)->post('/actions/member.activate', ['context' => 'workspace', 'workspace_id' => $w->id, 'id' => $m->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $m->fresh()->status);
        $this->assertTrue(app(Authorization::class)->allowed($target, 'invoice.view', $w));
    }

    public function test_distinct_account_occupies_one_seat_across_workspaces_of_same_owner(): void
    {
        $w = $this->workspace();
        $used = count(app(StaffCapacity::class)->activeAccounts($w->owner_id));
        config(['business.limits.yearly.staff' => $used]);
        $other = Workspace::create(['owner_id' => $w->owner_id, 'name' => 'Cùng chủ trọ', 'status' => 'active', 'plan' => 'yearly', 'expires_at' => now()->addYear()]);
        $m = $this->invite($this->user('staff'), $other);
        $this->assertSame('active', $m->status);
        $this->assertCount($used, app(StaffCapacity::class)->activeAccounts($w->owner_id));
        $this->assertNotContains($w->owner_id, app(StaffCapacity::class)->activeAccounts($w->owner_id));
        $third = Workspace::create(['owner_id' => $w->owner_id, 'name' => 'Khu thứ ba cùng chủ', 'status' => 'active', 'plan' => 'yearly', 'expires_at' => now()->addYear()]);
        $this->assertSame('active', $this->invite($this->user('staff'), $third)->status);
        $this->assertCount($used, app(StaffCapacity::class)->activeAccounts($w->owner_id));
        $independent = Workspace::where('owner_id', $this->user('other')->id)->firstOrFail();
        $otherUsed = count(app(StaffCapacity::class)->activeAccounts($independent->owner_id));
        $this->assertSame('active', $this->invite($this->user('staff'), $independent)->status);
        $this->assertCount($otherUsed + 1, app(StaffCapacity::class)->activeAccounts($independent->owner_id));
        app(MembershipLifecycle::class)->end($w->owner, $third, Membership::where('workspace_id', $third->id)->where('user_id', $this->user('staff')->id)->value('id'));
        $this->assertContains($this->user('staff')->id, app(StaffCapacity::class)->activeAccounts($independent->owner_id));
    }

    public function test_mixed_legacy_plans_do_not_guess_shared_capacity(): void
    {
        $w = $this->workspace();
        Workspace::create(['owner_id' => $w->owner_id, 'name' => 'Chờ đối chiếu kỳ gói', 'status' => 'active', 'plan' => 'monthly', 'expires_at' => now()->addMonth()]);
        $this->assertNull(app(StaffCapacity::class)->limit($w->owner_id));
        $m = $this->invite(User::factory()->create());
        $this->assertSame('pending', $m->status);
        $this->actingAs($w->owner)->post('/actions/member.activate', ['context' => 'workspace', 'workspace_id' => $w->id, 'id' => $m->id])->assertConflict();
    }

    public function test_ended_rejoin_creates_new_membership_without_old_grants_or_groups(): void
    {
        $target = User::factory()->create();
        $w = $this->workspace();
        $old = $this->invite($target);
        $grant = DirectGrant::create(['workspace_id' => $w->id, 'user_id' => $target->id, 'grantor_id' => $w->owner_id, 'permission' => 'iot.view', 'valid_from' => now()->subMinute(), 'valid_until' => now()->addDay(), 'reason' => 'Quyền của lần tham gia cũ']);
        $this->assertSame($old->id, $grant->membership_id);
        app(MembershipLifecycle::class)->end($w->owner, $w, $old->id);
        $new = $this->invite($target);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('ended', $old->fresh()->status);
        $this->assertNull($old->fresh()->current_key);
        // Even stale SQL/cache state cannot bind an old grant to the new epoch.
        $grant->update(['revoked_at' => null]);
        $this->assertFalse(app(Authorization::class)->allowed($target, 'iot.view', $w));
        $this->assertTrue(app(Authorization::class)->allowed($target, 'invoice.view', $w));
        $this->assertSame(2, Membership::where('workspace_id', $w->id)->where('user_id', $target->id)->count());
    }

    public function test_suspended_member_needs_quota_and_explicit_resume_but_expiry_does_not_suspend(): void
    {
        $w = $this->workspace();
        $m = Membership::where('workspace_id', $w->id)->where('user_id', $this->user('staff')->id)->firstOrFail();
        $lifecycle = app(MembershipLifecycle::class);
        $lifecycle->suspend($w->owner, $w, $m->id, 'Tạm ngừng phân công');
        $this->assertSame('suspended', $m->fresh()->status);
        $this->assertFalse(app(Authorization::class)->allowed($m->user, 'property.view', $w, $w->properties()->first()->id));
        $used = count(app(StaffCapacity::class)->activeAccounts($w->owner_id));
        config(['business.limits.yearly.staff' => $used]);
        $this->actingAs($w->owner)->post('/actions/member.activate', ['context' => 'workspace', 'workspace_id' => $w->id, 'id' => $m->id])->assertConflict();
        config(['business.limits.yearly.staff' => $used + 1]);
        $lifecycle->activate($w->owner, $w, $m->id);
        $w->update(['expires_at' => now()]);
        app(\App\Modules\Subscription\SubscriptionService::class)->expire();
        $this->assertSame('active', $m->fresh()->status);
    }

    public function test_last_seat_is_taken_by_only_one_acceptance_without_consuming_pending_invites(): void
    {
        $w = $this->workspace();
        $used = count(app(StaffCapacity::class)->activeAccounts($w->owner_id));
        config(['business.limits.yearly.staff' => $used + 1]);
        $one = $this->invite(User::factory()->create());
        $two = $this->invite(User::factory()->create());
        $this->assertSame('active', $one->status);
        $this->assertSame('pending', $two->status);
        $this->assertCount($used + 1, app(StaffCapacity::class)->activeAccounts($w->owner_id));
    }

    public function test_ending_staff_keeps_maintenance_work_and_queues_handover(): void
    {
        $w = $this->workspace();
        $staff = $this->user('staff');
        $m = Membership::where('workspace_id', $w->id)->where('user_id', $staff->id)->firstOrFail();
        $service = app(\App\Modules\Maintenance\MaintenanceService::class);
        $issue = $service->create($this->user('tenant'), ['room_id' => \App\Modules\Rental\Models\Rental::first()->room_id, 'title' => 'Công việc cần bàn giao', 'description' => 'Kiểm tra đường nước']);
        $service->transition($staff, $issue, 'acknowledged', 'Đã tiếp nhận');
        $assignment = \App\Modules\Workspace\Models\WorkAssignment::where('resource_id', $issue->id)->firstOrFail();
        app(MembershipLifecycle::class)->end($w->owner, $w, $m->id);
        $this->assertSame('acknowledged', $issue->fresh()->status);
        $this->assertSame('handover_pending', $assignment->fresh()->status);
        $this->assertNull($assignment->fresh()->current_key);
        $this->assertSame('pending', MembershipHandover::where('membership_id', $m->id)->firstOrFail()->status);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Modules\Workspace\WorkAssignmentService::class)->requireCurrent($staff, $assignment->id);
    }

    public function test_permission_deadline_does_not_release_seat_while_membership_status_is_active(): void
    {
        $w = $this->workspace(); $capacity = app(StaffCapacity::class);
        $used = count($capacity->activeAccounts($w->owner_id));
        $m = Membership::where('workspace_id', $w->id)->where('user_id', $this->user('staff')->id)->firstOrFail();
        $m->update(['valid_until' => now()->subMinute()]);
        $this->assertCount($used, $capacity->activeAccounts($w->owner_id));
        $this->assertSame('active', $m->fresh()->status);
        config(['business.limits.yearly.staff' => $used]);
        $this->assertSame('pending', $this->invite(User::factory()->create())->status);
    }

    public function test_demo_seeding_keeps_private_groups_and_never_reuses_ended_membership(): void
    {
        $w = $this->workspace(); $staff = $this->user('staff');
        $old = Membership::where('workspace_id', $w->id)->where('user_id', $staff->id)->firstOrFail();
        $this->assertSame($w->id, $old->group->workspace_id);
        $this->assertNotNull($old->group->source_template_id);
        app(MembershipLifecycle::class)->end($w->owner, $w, $old->id);
        $this->seed(\Database\Seeders\DemoAccountsSeeder::class);
        $joined = Membership::where('workspace_id', $w->id)->where('user_id', $staff->id)->where('status', 'active')->firstOrFail();
        $this->assertNotSame($old->id, $joined->id);
        $this->assertSame('ended', $old->fresh()->status);
        $this->assertSame($w->id, $joined->group->workspace_id);
    }
}
