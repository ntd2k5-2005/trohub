<?php

namespace Tests\Feature;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\DirectGrantService;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\Workspace\MembershipLifecycle;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\WorkspaceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DelegationGraphTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        Permission::whereIn('code', ['invoice.view', 'member.delegate'])->get()->each(fn ($p) => $p->update(['max_depth' => 3]));
        $this->assertSame(3, \Illuminate\Support\Facades\DB::table('permissions')->where('code', 'invoice.view')->value('max_depth'));
    }

    private function w(): Workspace
    {
        return Workspace::whereHas('owner', fn ($q) => $q->where('email', 'owner@trohub.test'))->firstOrFail();
    }

    private function member(array $permissions = []): Membership
    {
        $user = User::factory()->create();
        return Membership::create(['workspace_id' => $this->w()->id, 'user_id' => $user->id, 'access_group_id' => AccessGroup::where('key', 'custom')->value('id'), 'permissions' => $permissions, 'status' => 'active']);
    }

    private function grant(User $actor, Membership $target, string $permission, array $extra = []): DirectGrant
    {
        return app(DirectGrantService::class)->issue($actor, $this->w(), $extra + ['user_id' => $target->user_id, 'permission' => $permission, 'valid_from' => now()->subMinute()->toDateTimeString(), 'valid_until' => now()->addDay()->toDateTimeString(), 'reason' => 'Ủy quyền kiểm thử có nguồn xác định']);
    }

    public function test_source_disabled_during_issue_cannot_hide_behind_independent_owner_authority(): void
    {
        $actor = $this->w()->owner;
        $group = AccessGroup::create(['scope' => 'global', 'name' => 'Nguồn được chọn để cấp quyền', 'permissions' => ['invoice.view', 'member.delegate'], 'active' => true]);
        $actor->forceFill(['access_group_id' => $group->id])->save();
        $target = $this->member();
        \Illuminate\Support\Facades\Event::listen('eloquent.created: '.DirectGrant::class, function ($grant) use ($group) {
            if ($grant->source_kind === 'platform' && $grant->source_id === $group->id) {
                // Simulate a source change after selection without replacing domain business logic.
                \Illuminate\Support\Facades\DB::table('access_groups')->where('id', $group->id)->update(['active' => false, 'status' => 'disabled']);
            }
        });
        try { $this->grant($actor, $target, 'invoice.view'); $this->fail('Nguồn đã ngừng không được tạo grant chờ hồi sinh.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertSame(0, DirectGrant::where('membership_id', $target->id)->count());
        $this->assertTrue($group->fresh()->active); // Entire failed issue rolled back.
        $this->assertTrue(app(Authorization::class)->allowed($actor, 'invoice.view', $this->w()));
    }

    private function chain(): array
    {
        $this->assertSame(3, app(\App\Modules\AccessControl\GrantGraph::class)->depthLimit('invoice.view'));
        $this->assertSame(3, app(\App\Modules\AccessControl\GrantGraph::class)->depthLimit('member.delegate'));
        $a = $this->member(); $b = $this->member(); $c = $this->member();
        $root = $this->grant($this->w()->owner, $a, 'invoice.view');
        $this->grant($this->w()->owner, $a, 'member.delegate');
        $middle = $this->grant($a->user, $b, 'invoice.view');
        $this->grant($a->user, $b, 'member.delegate');
        $leaf = $this->grant($b->user, $c, 'invoice.view');
        return [$a, $b, $c, $root, $middle, $leaf];
    }

    public function test_three_level_provenance_and_revocation_terminate_only_dependent_branch(): void
    {
        [$a,$b,$c,$root,$middle,$leaf] = $this->chain();
        $this->assertSame('organization', $root->authority_kind);
        $this->assertSame('personal', $middle->authority_kind);
        $this->assertSame($root->id, $middle->source_id);
        $this->assertSame($a->id, $middle->issuer_membership_id);
        $this->assertSame(3, $leaf->depth);
        $this->assertTrue(app(Authorization::class)->allowed($c->user, 'invoice.view', $this->w()));
        // Independent base authority does not silently become the child's source.
        $b->update(['permissions' => ['invoice.view']]);
        app(WorkspaceService::class)->revokeGrant($this->w()->owner, $root->id);
        $this->assertNotNull($middle->fresh()->revoked_at);
        $this->assertNotNull($leaf->fresh()->revoked_at);
        $this->assertTrue(app(Authorization::class)->allowed($b->user, 'invoice.view', $this->w()));
        $this->assertFalse(app(Authorization::class)->allowed($c->user, 'invoice.view', $this->w()));
    }

    public function test_temporary_membership_block_does_not_revoke_valid_graph_and_end_does(): void
    {
        [$a,$b,$c,$root,$middle,$leaf] = $this->chain();
        $a->update(['status' => 'suspended']);
        $this->assertNull($middle->fresh()->revoked_at);
        $this->assertFalse(app(Authorization::class)->allowed($c->user, 'invoice.view', $this->w()));
        $a->update(['status' => 'active']);
        $this->assertTrue(app(Authorization::class)->allowed($c->user, 'invoice.view', $this->w()));
        app(MembershipLifecycle::class)->end($this->w()->owner, $this->w(), $a->id);
        $this->assertNotNull($middle->fresh()->revoked_at);
        $this->assertNotNull($leaf->fresh()->revoked_at);
    }

    public function test_tightening_depth_is_permanent_even_when_policy_is_later_relaxed(): void
    {
        [$a,$b,$c,$root,$middle,$leaf] = $this->chain();
        $policy = Permission::where('code', 'invoice.view')->firstOrFail();
        $policy->update(['max_depth' => 1]);
        $this->assertNull($root->fresh()->revoked_at);
        $this->assertNotNull($middle->fresh()->revoked_at);
        $policy->update(['max_depth' => 3]);
        $this->assertFalse(app(Authorization::class)->allowed($c->user, 'invoice.view', $this->w()));
        $this->assertSame('source_revoked', $leaf->fresh()->status);
    }

    public function test_cannot_create_cycle_or_expand_source_scope_and_time(): void
    {
        [$a,$b,$c,$root,$middle,$leaf] = $this->chain();
        try { $this->grant($b->user, $a, 'invoice.view'); $this->fail('Không được tạo vòng.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        try { $this->grant($a->user, $c, 'invoice.view', ['valid_from' => now()->subDays(2)->toDateTimeString()]); $this->fail('Không được lùi hiệu lực trước nguồn.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->grant($a->user, $c, 'invoice.view', ['valid_until' => now()->addDays(2)->toDateTimeString()]);
    }

    public function test_retired_source_keeps_use_but_cannot_create_new_delegation(): void
    {
        $group = AccessGroup::create(['workspace_id' => $this->w()->id, 'scope' => 'workspace', 'name' => 'Nhóm có thể ngừng mở rộng', 'permissions' => ['invoice.view', 'member.delegate'], 'active' => true]);
        $a = $this->member(); $a->update(['access_group_id' => $group->id, 'permissions' => null]);
        $b = $this->member();
        $grant = $this->grant($a->user, $b, 'invoice.view');
        $group->update(['status' => 'retired']);
        $this->assertTrue(app(Authorization::class)->allowed($b->user, 'invoice.view', $this->w()));
        $this->assertNull($grant->fresh()->revoked_at);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->grant($a->user, $this->member(), 'invoice.view');
    }

    public function test_permission_removal_revokes_children_and_readding_does_not_restore(): void
    {
        $a = $this->member(['invoice.view', 'member.delegate']);
        $b = $this->member();
        $grant = $this->grant($a->user, $b, 'invoice.view');
        $a->update(['permissions' => ['member.delegate']]);
        $this->assertNotNull($grant->fresh()->revoked_at);
        $a->update(['permissions' => ['invoice.view', 'member.delegate']]);
        $this->assertFalse(app(Authorization::class)->allowed($b->user, 'invoice.view', $this->w()));
    }

    public function test_group_source_never_switches_to_equal_manual_authority(): void
    {
        $g = AccessGroup::create(['workspace_id' => $this->w()->id, 'scope' => 'workspace', 'name' => 'Nguồn nhóm ban đầu', 'permissions' => ['invoice.view', 'member.delegate'], 'active' => true]);
        $a = $this->member(); $a->update(['access_group_id' => $g->id, 'permissions' => null]); $b = $this->member();
        $grant = $this->grant($a->user, $b, 'invoice.view');
        $this->assertSame($g->id, $grant->source_snapshot['group_id']);
        $a->update(['permissions' => ['invoice.view', 'member.delegate'], 'permissions_mode' => 'replace']);
        $this->assertTrue(app(Authorization::class)->allowed($a->user, 'invoice.view', $this->w()));
        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertFalse(app(Authorization::class)->allowed($b->user, 'invoice.view', $this->w()));
    }

    public function test_independent_manual_source_survives_group_disable_and_grant_terms_are_immutable(): void
    {
        $g = AccessGroup::create(['workspace_id' => $this->w()->id, 'scope' => 'workspace', 'name' => 'Nhóm không cấp quyền riêng', 'permissions' => [], 'active' => true]);
        $a = $this->member(['invoice.view', 'member.delegate']); $a->update(['access_group_id' => $g->id]); $b = $this->member();
        $grant = $this->grant($a->user, $b, 'invoice.view');
        $this->assertNull($grant->source_snapshot['group_id']);
        $g->update(['status' => 'disabled']);
        $this->assertTrue(app(Authorization::class)->allowed($b->user, 'invoice.view', $this->w()));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $grant->update(['valid_until' => now()->addDays(2)]);
    }

    public function test_platform_use_with_membership_delegate_keeps_issuer_scope_and_cannot_revive_after_scope_restore(): void
    {
        $a = $this->member(['member.delegate']); $b = $this->member(); $p = $this->w()->properties()->firstOrFail();
        $group = AccessGroup::create(['scope' => 'global', 'name' => 'Quyền đọc nền tảng độc lập', 'permissions' => ['invoice.view'], 'active' => true]);
        $a->user->forceFill(['access_group_id' => $group->id])->save(); $a->update(['property_ids' => [$p->id]]);
        $grant = $this->grant($a->user, $b, 'invoice.view', ['property_id' => $p->id]);
        $this->assertSame($a->id, $grant->issuer_membership_id);
        $this->assertSame('platform', $grant->source_kind);
        $this->assertSame('membership', $grant->delegation_source_kind);
        $a->update(['property_ids' => []]);
        $this->assertNotNull($grant->fresh()->revoked_at);
        $a->update(['property_ids' => [$p->id]]);
        $this->assertFalse(app(Authorization::class)->allowed($b->user, 'invoice.view', $this->w(), $p->id));
    }

    public function test_reassigning_global_group_creates_new_source_without_reviving_old_children(): void
    {
        $actor = User::factory()->create(); $root = User::where('email', 'supreme@trohub.test')->firstOrFail();
        $group = AccessGroup::create(['scope' => 'global', 'name' => 'Nguồn toàn nền tảng có giới hạn', 'permissions' => ['invoice.view', 'member.delegate'], 'active' => true]);
        $assign = app(\App\Modules\AccessControl\AccountGroups::class); $assign->assign($root, $actor, [$group->id]);
        $target = $this->member(); $grant = $this->grant($actor, $target, 'invoice.view', ['valid_from' => now()->toDateTimeString()]);
        $old = $grant->source_id; $assign->assign($root, $actor, [$group->id]);
        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertDatabaseHas('group_assignments', ['id' => $old, 'access_group_id' => $group->id]);
        $this->assertNotSame($old, \App\Modules\AccessControl\Models\GroupAssignment::where('user_id', $actor->id)->where('access_group_id', $group->id)->whereNull('revoked_at')->value('id'));
        $group->update(['status' => 'retired']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->grant($actor, $this->member(), 'invoice.view');
    }

    public function test_disabling_delegate_catalog_permission_terminates_dependent_roots(): void
    {
        $a=$this->member(['invoice.view','member.delegate']);$b=$this->member();$grant=$this->grant($a->user,$b,'invoice.view');
        $policy=Permission::where('code','member.delegate')->firstOrFail();$policy->update(['active'=>false]);
        $this->assertNotNull($grant->fresh()->revoked_at);$policy->update(['active'=>true]);
        $this->assertFalse(app(Authorization::class)->allowed($b->user,'invoice.view',$this->w()));
    }
}
