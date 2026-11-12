<?php

namespace Tests\Feature;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\DirectGrantService;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\DataPlatform\Models\AuditEntry;
use App\Modules\Maintenance\MaintenanceService;
use App\Modules\Workspace\MembershipLifecycle;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\WorkAssignment;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\WorkAssignmentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResourceScopeBoundaryTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->freezeTime(); $this->seed(DatabaseSeeder::class); }
    private function w(): Workspace { return Workspace::whereHas('owner', fn ($q) => $q->where('email', 'owner@trohub.test'))->firstOrFail(); }
    private function target(): Membership { return Membership::create(['workspace_id' => $this->w()->id, 'user_id' => User::factory()->create()->id, 'access_group_id' => AccessGroup::where('key', 'custom')->value('id'), 'permissions' => [], 'status' => 'active']); }
    private function grant(Membership $target, ?int $property, ?int $room = null) {
        return app(DirectGrantService::class)->issue($this->w()->owner, $this->w(), ['user_id' => $target->user_id, 'permission' => 'invoice.view', 'property_id' => $property, 'room_id' => $room, 'valid_from' => now()->toDateTimeString(), 'valid_until' => now()->addDay()->toDateTimeString(), 'reason' => 'Kiểm tra phạm vi thực tế']);
    }

    public function test_recipient_scope_narrowing_ends_old_branch_and_expansion_does_not_revive_it(): void
    {
        $m = $this->target(); $p = $this->w()->properties()->firstOrFail(); $g = $this->grant($m, null);
        $m->update(['property_ids' => [$p->id]]);
        $this->assertNotNull($g->fresh()->revoked_at);
        $m->update(['property_ids' => null]);
        $this->assertFalse(app(Authorization::class)->allowed($m->user, 'invoice.view', $this->w()));
    }

    public function test_resource_relocation_invalidates_native_source_and_return_does_not_revive_it(): void
    {
        // This tests scope re-evaluation only, not a business Property Move workflow.
        $m = $this->target(); $p = $this->w()->properties()->firstOrFail(); $g = $this->grant($m, $p->id);
        $destination = Workspace::create(['owner_id' => $this->w()->owner_id, 'name' => 'Khu kiểm tra phạm vi mới', 'plan' => 'yearly', 'status' => 'active', 'expires_at' => now()->addYear()]);
        $before = AuditEntry::count();
        $p->update(['workspace_id' => $destination->id]);
        $this->assertNotNull($g->fresh()->revoked_at);
        $this->assertFalse(app(Authorization::class)->allowed($m->user, 'invoice.view', $destination, $p->id));
        $p->update(['workspace_id' => $this->w()->id]);
        $this->assertFalse(app(Authorization::class)->allowed($m->user, 'invoice.view', $this->w(), $p->id));
        $this->assertGreaterThan($before, AuditEntry::count());
    }

    public function test_permission_scope_and_delegate_scope_cannot_be_mixed(): void
    {
        Permission::whereIn('code', ['invoice.view', 'member.delegate'])->get()->each(fn ($p) => $p->update(['max_depth' => 3]));
        $p = $this->w()->properties()->firstOrFail();
        $other = \App\Modules\Property\Models\Property::create(['workspace_id' => $this->w()->id, 'name' => 'Nhà ngoài phạm vi cấp', 'address' => 'Địa chỉ kiểm thử', 'city' => 'Hồ Chí Minh', 'district' => 'Thủ Đức']);
        $a = $this->target(); $b = $this->target(); $this->grant($a, $p->id);
        app(DirectGrantService::class)->issue($this->w()->owner, $this->w(), ['user_id' => $a->user_id, 'permission' => 'member.delegate', 'property_id' => $other->id, 'valid_from' => now()->toDateTimeString(), 'valid_until' => now()->addDay()->toDateTimeString(), 'reason' => 'Phạm vi ủy quyền độc lập']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(DirectGrantService::class)->issue($a->user, $this->w(), ['user_id' => $b->user_id, 'permission' => 'invoice.view', 'property_id' => $other->id, 'valid_from' => now()->toDateTimeString(), 'valid_until' => now()->addHour()->toDateTimeString(), 'reason' => 'Không được ghép nguồn']);
    }

    private function work(): array
    {
        $staff = User::where('email', 'staff@trohub.test')->firstOrFail();
        $tenant = User::where('email', 'tenant@trohub.test')->firstOrFail();
        $issue = app(MaintenanceService::class)->create($tenant, ['room_id' => \App\Modules\Rental\Models\Rental::firstOrFail()->room_id, 'title' => 'Công việc bàn giao kiểm thử', 'description' => 'Kiểm tra đường nước']);
        app(MaintenanceService::class)->transition($staff, $issue, 'acknowledged', 'Đã nhận');
        return [$staff, $issue, WorkAssignment::where('resource_id', $issue->id)->firstOrFail()];
    }

    public function test_owner_can_take_over_handover_from_ui_without_mutating_work_or_finance(): void
    {
        [$staff,$issue,$a] = $this->work();
        app(MembershipLifecycle::class)->end($this->w()->owner, $this->w(), Membership::where('user_id', $staff->id)->firstOrFail()->id);
        $this->actingAs($this->w()->owner)->get('/ui/team-permissions?context=workspace&section=handover')->assertOk()->assertSee($issue->title)->assertSee('Tiếp nhận công việc');
        $this->post('/actions/work.takeover', ['context' => 'workspace', 'workspace_id' => $this->w()->id, 'id' => $a->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('reassigned', $a->fresh()->status);
        $this->assertSame('acknowledged', $issue->fresh()->status);
        $this->assertSame($staff->id, $a->fresh()->actor_id);
        $this->post('/actions/work.takeover', ['context' => 'workspace', 'workspace_id' => $this->w()->id, 'id' => $a->id])->assertForbidden();
    }

    public function test_lost_permission_during_work_processing_prevents_commit(): void
    {
        [$staff,$issue,$assignment] = $this->work();
        $member = Membership::where('user_id', $staff->id)->firstOrFail(); $triggered = false;
        AuditEntry::created(function ($entry) use ($member, &$triggered) {
            if ($entry->action === 'maintenance.updated' && ! $triggered) { $triggered = true; $member->update(['permissions' => []]); }
        });
        try { app(MaintenanceService::class)->transition($staff, $issue, 'in_progress', 'Không được ghi khi quyền đã mất'); $this->fail('Phải chặn commit.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertTrue($triggered);
        $this->assertSame('acknowledged', $issue->fresh()->status);
        $this->assertDatabaseMissing('audit_entries', ['action' => 'maintenance.updated', 'changes' => json_encode(['status' => 'in_progress', 'note' => 'Không được ghi khi quyền đã mất'])]);
    }

    public function test_permission_directory_snapshot_is_bounded_and_rechecks_after_revocation(): void
    {
        $m = $this->target(); $p = $this->w()->properties()->firstOrFail(); $g = $this->grant($m, $p->id);
        $codes = Permission::where('active', true)->pluck('code')->all();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $map = app(Authorization::class)->permissionPropertyMap($m->user, $this->w(), $codes);
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();
        $this->assertSame([$p->id], $map['invoice.view']);
        $this->assertSame([], $map['payment.record']);
        $this->assertLessThan(100, $queries, 'Không truy vấn lại toàn bộ nguồn quyền cho từng ô trong danh sách.');
        $g->update(['revoked_at' => now()]);
        $this->assertSame([], app(Authorization::class)->permissionPropertyMap($m->user, $this->w(), $codes)['invoice.view']);
    }

    public function test_scoped_directory_does_not_expose_properties_from_targets_global_authority(): void
    {
        $p = $this->w()->properties()->firstOrFail();
        \App\Modules\Property\Models\Property::create(['workspace_id' => $this->w()->id, 'name' => 'Nhà bí mật ngoài phạm vi nhân sự', 'address' => 'Địa chỉ không được hiển thị', 'district' => 'Thủ Đức']);
        $viewer = $this->target(); $viewer->update(['property_ids' => [$p->id], 'permissions' => ['member.view']]);
        $target = $this->target(); $target->update(['property_ids' => [$p->id]]);
        $target->user->forceFill(['access_group_id' => AccessGroup::where('key', 'supreme')->value('id')])->save();
        $this->actingAs($viewer->user)->get('/ui/team-permissions?context=workspace&workspace_id='.$this->w()->id)->assertOk()->assertSee($target->user->name)->assertDontSee('Nhà bí mật ngoài phạm vi nhân sự');
    }
}
