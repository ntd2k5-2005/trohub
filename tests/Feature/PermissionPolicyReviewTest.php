<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessControl\Models\{AccessGroup, Permission, PermissionChange};
use App\Modules\AccessControl\PermissionPolicyChanges;
use App\Modules\Workspace\Models\Membership;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionPolicyReviewTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->freezeTime(); $this->seed(DatabaseSeeder::class); }
    private function root(): User { return User::where('email', 'supreme@trohub.test')->firstOrFail(); }
    private function reviewer(): User
    {
        $g = AccessGroup::firstOrCreate(['name' => 'Duyệt chính sách quyền', 'scope' => 'global'], ['permissions' => ['access.view', 'access.catalog.edit'], 'active' => true]);
        $u = User::factory()->create(); $u->forceFill(['access_group_id' => $g->id])->save(); return $u;
    }
    private function proposal(): PermissionChange
    {
        return app(PermissionPolicyChanges::class)->propose($this->root(), Permission::where('code', 'invoice.view')->firstOrFail(), ['max_depth' => 3, 'reason' => 'Bổ sung cấp ủy quyền qua quản lý']);
    }
    private function denied(callable $fn, int $status): void
    {
        try { $fn(); $this->fail('Phải bị chặn.'); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame($status, $e->getStatusCode()); }
    }
    public function test_proposal_approval_apply_roundtrip_and_repeated_application(): void
    {
        $s=app(PermissionPolicyChanges::class);$c=$this->proposal();$a=$this->reviewer();$b=$this->reviewer();
        $this->assertNull($c->permission->max_depth);
        $this->denied(fn()=> $s->approve($this->root(),$c->id),403);
        $s->approve($a,$c->id);$s->approve($a,$c->id);
        $this->denied(fn()=> $s->apply($this->root(),$c->id),409);
        $this->assertCount(1,$c->fresh()->approvals);
        $this->actingAs($b)->post('/actions/permission.change.approve',['context'=>'system-admin','id'=>$c->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->root())->post('/actions/permission.change.apply',['context'=>'system-admin','id'=>$c->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3,$c->permission->fresh()->max_depth);$this->assertSame('applied',$c->fresh()->status);
        $this->denied(fn()=> $s->apply($this->root(),$c->id),409);
    }
    public function test_member_scope_drift_and_policy_version_invalidate_old_preview(): void
    {
        $s=app(PermissionPolicyChanges::class);$c=$this->proposal();
        $this->assertNotEmpty($c->impact['members']);
        Membership::findOrFail($c->impact['members'][0]['id'])->update(['property_ids'=>[99999]]);
        $this->denied(fn()=> $s->approve($this->reviewer(),$c->id),409);
        $new=$this->proposal();$p=$new->permission;$p->update(['max_depth'=>1]);
        $this->denied(fn()=> $s->approve($this->reviewer(),$new->id),409);
    }
    public function test_revoked_reviewer_authority_is_checked_at_apply(): void
    {
        $s=app(PermissionPolicyChanges::class);$c=$this->proposal();$a=$this->reviewer();$b=$this->reviewer();$s->approve($a,$c->id);$s->approve($b,$c->id);
        $a->forceFill(['status'=>'locked'])->save();
        $this->denied(fn()=> $s->apply($this->root(),$c->id),409);
        $this->assertNull($c->permission->fresh()->max_depth);
    }
    public function test_beneficiary_cannot_approve_and_null_depth_reset_cannot_bypass_review():void
    {
        $beneficiary=$this->reviewer();$group=$beneficiary->accessGroup??\App\Modules\AccessControl\Models\AccessGroup::findOrFail($beneficiary->access_group_id);
        $group->update(['permissions'=>['access.view','access.catalog.edit','invoice.view']]);
        $c=$this->proposal();$this->denied(fn()=>app(PermissionPolicyChanges::class)->approve($beneficiary,$c->id),403);
        $p=Permission::where('code','invoice.view')->firstOrFail();$p->update(['max_depth'=>0]);$this->assertTrue(app(PermissionPolicyChanges::class)->expanding($p,['max_depth'=>null]));
    }
    public function test_audit_failure_rolls_back_policy_and_review_status(): void
    {
        $s=app(PermissionPolicyChanges::class);$c=$this->proposal();$s->approve($this->reviewer(),$c->id);$s->approve($this->reviewer(),$c->id);
        \App\Modules\DataPlatform\Models\AuditEntry::creating(function($row){if($row->action==='permission.change.applied')throw new \RuntimeException('Audit unavailable');});
        try{$s->apply($this->root(),$c->id);$this->fail('Audit phải rollback.');}catch(\RuntimeException $e){$this->assertSame('Audit unavailable',$e->getMessage());}
        $this->assertNull($c->permission->fresh()->max_depth);$this->assertSame('pending_review',$c->fresh()->status);
    }
    public function test_catalog_ui_shows_pending_change_without_leaking_to_unprivileged_account(): void
    {
        $this->proposal();$this->actingAs($this->root())->get('/ui/access-control?context=system-admin&section=catalog')->assertOk()->assertSee('Chờ duyệt:')->assertSee('Phê duyệt độc lập');
        $this->actingAs(User::where('email','user@trohub.test')->firstOrFail())->post('/actions/permission.change.apply',['context'=>'personal','id'=>1])->assertForbidden();
    }
}
