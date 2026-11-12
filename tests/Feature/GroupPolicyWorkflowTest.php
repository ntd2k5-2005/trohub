<?php

namespace Tests\Feature;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\GroupChangeService;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\DirectGrant;
use App\Modules\AccessControl\Models\GroupChange;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\AccessControl\WorkspaceGroupCopies;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\WorkspaceGroups;
use App\Modules\Workspace\WorkspaceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupPolicyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->freezeTime(); $this->seed(DatabaseSeeder::class);
    }

    private function w(): Workspace { return Workspace::whereHas('owner', fn ($q) => $q->where('email', 'owner@trohub.test'))->firstOrFail(); }
    private function group(): AccessGroup { return AccessGroup::create(['workspace_id' => $this->w()->id, 'scope' => 'workspace', 'name' => 'Nhóm kiểm thử độc lập', 'permissions' => ['invoice.view'], 'active' => true]); }
    private function member(AccessGroup $g): Membership { return Membership::create(['workspace_id' => $this->w()->id, 'user_id' => User::factory()->create()->id, 'access_group_id' => $g->id, 'permissions' => null, 'status' => 'active']); }
    private function reviewer(): User { return User::factory()->create(['access_group_id' => AccessGroup::where('key', 'supreme')->value('id')]); }

    private function denied(callable $call, int $status): void
    {
        try { $call(); $this->fail('Phải từ chối thao tác.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame($status, $e->getStatusCode()); }
    }

    public function test_workspace_template_copies_do_not_live_sync_or_cross_workspaces(): void
    {
        $template = AccessGroup::where('key', 'accountant')->firstOrFail();
        $w = $this->w();
        $other = Workspace::create(['owner_id' => $w->owner_id, 'name' => 'Khu riêng', 'status' => 'active', 'plan' => $w->plan, 'expires_at' => $w->expires_at]);
        $a = app(WorkspaceGroupCopies::class)->resolve($w, $template->id);
        $b = app(WorkspaceGroupCopies::class)->resolve($other, $template->id);
        $this->assertNotSame($a->id, $b->id);
        $before = $a->permissions;
        $template->update(['permissions' => [], 'status' => 'disabled']);
        $this->assertSame($before, $a->fresh()->permissions);
        $this->assertTrue($a->fresh()->active);
        $this->denied(fn () => app(WorkspaceGroupCopies::class)->resolve($other, $a->id), 403);
    }

    public function test_edit_is_preview_only_then_apply_and_retirement_blocks_new_assignment(): void
    {
        $g = $this->group(); $m = $this->member($g);
        app(WorkspaceGroups::class)->save($this->w()->owner, $this->w(), $g->id, ['name' => $g->name, 'permissions' => $g->permissions, 'status' => 'retired']);
        $this->assertSame('active', $g->fresh()->status);
        $c = GroupChange::where('access_group_id', $g->id)->firstOrFail();
        app(GroupChangeService::class)->apply($this->w()->owner, $c->id);
        $this->assertSame('retired', $g->fresh()->status);
        $this->assertTrue(app(Authorization::class)->allowed($m->user, 'invoice.view', $this->w()));
        $this->denied(fn () => app(WorkspaceGroupCopies::class)->resolve($this->w(), $g->id), 403);
        $this->denied(fn () => app(GroupChangeService::class)->apply($this->w()->owner, $c->id), 409);
    }

    public function test_disabled_group_restore_needs_two_independent_approvers_and_never_revives_ended_member(): void
    {
        $g = $this->group(); $m = $this->member($g); $ended = $this->member($g); $ended->update(['status' => 'ended']);
        $s = app(GroupChangeService::class); $s->disable($this->w()->owner, $g->id, 'Kiểm tra khẩn cấp');
        $this->assertFalse(app(Authorization::class)->allowed($m->user, 'invoice.view', $this->w()));
        $c = $s->propose($this->w()->owner, $g->fresh(), ['name' => $g->name, 'permissions' => $g->permissions, 'status' => 'active']);
        $this->assertSame(2, $c->required_approvals);
        $this->denied(fn () => $s->approve($this->w()->owner, $c->id), 403);
        $a = $this->reviewer(); $b = $this->reviewer();
        $s->approve($a, $c->id); $s->approve($a, $c->id);
        $this->assertSame(1, DB::table('group_change_approvals')->where('group_change_id', $c->id)->count());
        $this->denied(fn () => $s->apply($this->w()->owner, $c->id), 409);
        $s->approve($b, $c->id); $s->apply($this->w()->owner, $c->id);
        $this->assertTrue(app(Authorization::class)->allowed($m->user, 'invoice.view', $this->w()));
        $this->assertFalse(app(Authorization::class)->allowed($ended->user, 'invoice.view', $this->w()));
    }

    public function test_impact_drift_and_revoked_reviewer_block_previously_approved_change(): void
    {
        $g = $this->group(); $s = app(GroupChangeService::class);
        $c = $s->propose($this->w()->owner, $g, ['name' => $g->name, 'permissions' => ['invoice.view', 'payment.verify']]);
        $a = $this->reviewer(); $b = $this->reviewer(); $s->approve($a, $c->id); $s->approve($b, $c->id);
        $a->forceFill(['status' => 'locked'])->save();
        $this->denied(fn () => $s->apply($this->w()->owner, $c->id), 409);
        $a->forceFill(['status' => 'active'])->save(); $this->member($g);
        $this->denied(fn () => $s->apply($this->w()->owner, $c->id), 409);
        $this->assertSame(['invoice.view'], $g->fresh()->permissions);
    }

    public function test_invitation_permissions_keep_personal_provenance_and_cascade_without_switching_source(): void
    {
        foreach (Permission::whereIn('code', ['invoice.view', 'member.invite', 'member.delegate'])->get() as $p) $p->update(['max_depth' => 3]);
        $g = $this->group(); $issuer = $this->member($g);
        $issuer->update(['permissions' => ['invoice.view', 'member.invite', 'member.delegate']]);
        $recipient = User::factory()->create();
        $service = app(WorkspaceService::class);
        $token = $service->invite($issuer->user, $this->w(), ['email' => $recipient->email, 'permissions' => ['invoice.view'], 'valid_until' => now()->addDay()->toDateTimeString()]);
        $service->accept($recipient, $token);
        $m = Membership::where('user_id', $recipient->id)->firstOrFail();
        $this->assertSame('personal', $m->permission_origin);
        $grant = DirectGrant::where('membership_id', $m->id)->where('origin_kind', 'membership')->firstOrFail();
        $this->assertSame($issuer->id, $grant->issuer_membership_id);
        $this->assertTrue(app(Authorization::class)->allowed($recipient, 'invoice.view', $this->w()));
        $issuer->update(['permissions' => ['member.invite', 'member.delegate']]);
        $this->assertNotNull($grant->fresh()->revoked_at);
        $issuer->update(['permissions' => ['invoice.view', 'member.invite', 'member.delegate']]);
        $this->assertFalse(app(Authorization::class)->allowed($recipient, 'invoice.view', $this->w()));
    }

    public function test_self_leave_works_when_cloud_expired_and_cannot_end_other_membership(): void
    {
        $m = $this->member($this->group()); $other = $this->member($this->group());
        $this->w()->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($m->user)->post('/actions/member.leave', ['context' => 'personal', 'id' => $other->id, 'reason' => 'Rời khu', 'confirm' => 1])->assertForbidden();
        $this->post('/actions/member.leave', ['context' => 'personal', 'id' => $m->id, 'reason' => 'Kết thúc làm việc', 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ended', $m->fresh()->status);
        $this->assertSame('active', $other->fresh()->status);
    }

    public function test_group_expansion_creates_explicit_new_source_and_preserves_unchanged_provenance(): void
    {
        $g = $this->group(); $issuer = $this->member($this->group());
        $issuer->update(['permissions' => ['invoice.view', 'member.invite', 'member.delegate']]);
        $recipient = User::factory()->create();
        $service = app(WorkspaceService::class);
        $token = $service->invite($issuer->user, $this->w(), ['email' => $recipient->email, 'permissions' => [], 'access_group_ids' => [$g->id]]);
        $service->accept($recipient, $token);
        $member = Membership::where('user_id', $recipient->id)->firstOrFail();
        $old = DirectGrant::where('membership_id', $member->id)->where('permission', 'invoice.view')->firstOrFail();
        $s = app(GroupChangeService::class);
        $change = $s->propose($this->w()->owner, $g, ['name' => $g->name, 'permissions' => ['invoice.view', 'invoice.export']]);
        $s->apply($this->w()->owner, $change->id);
        $this->assertSame($old->source_id, $old->fresh()->source_id);
        $this->assertTrue(app(Authorization::class)->allowed($recipient, 'invoice.export', $this->w()));
        $new = DirectGrant::where('membership_id', $member->id)->where('permission', 'invoice.export')->firstOrFail();
        $this->assertSame('organization', $new->authority_kind);
        $issuer->update(['permissions' => ['member.invite', 'member.delegate']]);
        $this->assertFalse(app(Authorization::class)->allowed($recipient, 'invoice.view', $this->w()));
        $this->assertTrue(app(Authorization::class)->allowed($recipient, 'invoice.export', $this->w()));
    }

    public function test_retired_existing_assignment_is_preserved_on_membership_edit_without_expansion(): void
    {
        $g = $this->group(); $g->update(['permissions' => ['invoice.view', 'member.delegate']]);
        $recipient = User::factory()->create(); $service = app(WorkspaceService::class);
        $p = $this->w()->properties()->firstOrFail();
        $token = $service->invite($this->w()->owner, $this->w(), ['email' => $recipient->email, 'permissions' => [], 'access_group_ids' => [$g->id], 'property_ids' => [$p->id]]); $service->accept($recipient, $token);
        $m = Membership::where('user_id', $recipient->id)->firstOrFail();
        $a = \App\Modules\AccessControl\Models\GroupAssignment::where('membership_id', $m->id)->firstOrFail();
        $child = $this->member($this->group());
        $grant = app(\App\Modules\AccessControl\DirectGrantService::class)->issue($recipient, $this->w(), ['user_id' => $child->user_id, 'permission' => 'invoice.view', 'property_id' => $p->id, 'valid_from' => now()->toDateTimeString(), 'valid_until' => now()->addDay()->toDateTimeString(), 'reason' => 'Giữ nguồn nhóm đã cấp']);
        $change = app(GroupChangeService::class)->propose($this->w()->owner, $g, ['name' => $g->name, 'permissions' => $g->permissions, 'status' => 'retired']); app(GroupChangeService::class)->apply($this->w()->owner, $change->id);
        $data = ['access_group_ids' => [$g->id], 'permissions' => [], 'property_ids' => [$p->id], 'valid_until' => null];
        $service->updateMembership($this->w()->owner, $this->w(), $m->id, $data);
        $this->assertNull($a->fresh()->revoked_at);
        $this->assertSame($a->id, $grant->fresh()->source_id);
        $this->assertNull($grant->fresh()->revoked_at);
        $this->assertTrue(app(Authorization::class)->allowed($child->user, 'invoice.view', $this->w(), $p->id));
        $this->denied(fn () => $service->updateMembership($this->w()->owner, $this->w(), $m->id, array_replace($data, ['property_ids' => null])), 403);
    }

    public function test_policy_expansion_is_only_a_proposal_until_independent_policy_approval(): void
    {
        $this->actingAs(User::where('email', 'supreme@trohub.test')->firstOrFail())->post('/actions/permission.save', ['context' => 'system-admin', 'code' => 'invoice.view', 'name' => 'Xem hóa đơn', 'category' => 'Kế toán', 'delegable' => 1, 'active' => 1, 'max_depth' => 3, 'reason' => 'Cần thêm cấp quản lý có kiểm soát'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull(Permission::where('code', 'invoice.view')->value('max_depth'));
        $this->assertDatabaseHas('permission_changes', ['status' => 'pending_review']);
    }

    public function test_explicit_reassignment_after_expiry_uses_new_assignment_id(): void
    {
        $g = $this->group(); $recipient = User::factory()->create(); $service = app(WorkspaceService::class);
        $token = $service->invite($this->w()->owner, $this->w(), ['email' => $recipient->email, 'access_group_ids' => [$g->id], 'permissions' => []]); $service->accept($recipient, $token);
        $m = Membership::where('user_id', $recipient->id)->firstOrFail();
        $old = \App\Modules\AccessControl\Models\GroupAssignment::where('membership_id', $m->id)->firstOrFail(); $old->update(['valid_until' => now()->subMinute()]);
        $service->updateMembership($this->w()->owner, $this->w(), $m->id, ['access_group_ids' => [$g->id], 'permissions' => [], 'property_ids' => null]);
        $new = \App\Modules\AccessControl\Models\GroupAssignment::where('membership_id', $m->id)->where('access_group_id', $g->id)->whereNull('valid_until')->whereNull('revoked_at')->firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertTrue($old->fresh()->valid_until->isPast());
    }

    public function test_used_group_cannot_be_hard_deleted(): void
    {
        $g = $this->group(); $member = $this->member($g);
        $this->denied(fn () => $g->delete(), 409);
        $this->assertDatabaseHas('access_groups', ['id' => $g->id]);
        $this->assertSame($g->id, $member->fresh()->access_group_id);
    }
}
