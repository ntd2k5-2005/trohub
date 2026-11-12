<?php

namespace Tests\Feature;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\AccountGrantService;
use App\Modules\AccessControl\AccountGroups;
use App\Modules\AccessControl\DirectGrantService;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\AccessControl\Models\Permission;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\WorkspaceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountGrantProvenanceTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->freezeTime(); $this->seed(DatabaseSeeder::class); Permission::where('code','invoice.view')->firstOrFail()->update(['max_depth'=>3]); }
    private function root(): User { return User::where('email','supreme@trohub.test')->firstOrFail(); }
    private function grant(User $actor, User $target, array $extra=[])
    {
        return app(AccountGrantService::class)->issue($actor,$extra+['user_id'=>$target->id,'permission'=>'invoice.view','scope_type'=>'platform','valid_from'=>now()->toDateTimeString(),'valid_until'=>now()->addDay()->toDateTimeString(),'reason'=>'Nguồn cấp toàn cục có kiểm chứng']);
    }
    private function delegate(User $actor): void
    {
        $g=AccessGroup::firstOrCreate(['name'=>'Thẩm quyền cấp quyền toàn cục kiểm thử','scope'=>'global'],['permissions'=>['access.delegate'],'active'=>true]);
        app(AccountGroups::class)->assign($this->root(),$actor,[$g->id]);
    }

    public function test_global_chain_pins_sources_and_cascades_without_removing_independent_rights(): void
    {
        $a=User::factory()->create();$b=User::factory()->create();$c=User::factory()->create();$this->delegate($a);$this->delegate($b);
        $one=$this->grant($this->root(),$a);$two=$this->grant($a,$b);$three=$this->grant($b,$c);
        try { $this->grant($b,$a); $this->fail('Không cho vòng cấp quyền toàn cục.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        $independent=AccessGroup::create(['scope'=>'global','name'=>'Nguồn độc lập của người nhận','permissions'=>['invoice.view'],'active'=>true]);
        app(\App\Modules\AccessControl\AssignmentAdministration::class)->save($this->root(),['user_id'=>$b->id,'access_group_id'=>$independent->id,'valid_from'=>now()->toDateTimeString()]);
        $this->assertSame('platform',$one->authority_kind);$this->assertNull($one->membership_id);
        $this->assertSame($one->id,$two->source_id);$this->assertSame(3,$three->depth);
        app(WorkspaceService::class)->revokeGrant($this->root(),$one->id);
        $this->assertNotNull($two->fresh()->revoked_at);$this->assertNotNull($three->fresh()->revoked_at);
        $this->assertTrue(app(Authorization::class)->global($b,'invoice.view'));
        $this->assertFalse(app(Authorization::class)->global($c,'invoice.view'));
    }

    public function test_personal_source_cannot_be_used_to_create_platform_grant(): void
    {
        $a=User::factory()->create();$this->delegate($a);$this->grant($this->root(),$a,['scope_type'=>'personal']);
        $this->assertTrue(app(Authorization::class)->personal($a,'invoice.view'));
        $this->assertFalse(app(Authorization::class)->global($a,'invoice.view'));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);$this->grant($a,User::factory()->create());
    }

    public function test_platform_source_can_attenuate_into_workspace_but_revocation_does_not_switch_to_owner_source(): void
    {
        $w=Workspace::whereHas('owner',fn($q)=>$q->where('email','owner@trohub.test'))->firstOrFail();
        $parent=$this->grant($this->root(),$w->owner);
        $recipient=User::factory()->create();$m=Membership::create(['workspace_id'=>$w->id,'user_id'=>$recipient->id,'access_group_id'=>AccessGroup::where('key','custom')->value('id'),'permissions'=>[],'status'=>'active']);
        $child=app(DirectGrantService::class)->issue($w->owner,$w,['user_id'=>$recipient->id,'permission'=>'invoice.view','source_grant_id'=>$parent->id,'property_id'=>$w->properties()->firstOrFail()->id,'valid_from'=>now()->toDateTimeString(),'valid_until'=>now()->addHour()->toDateTimeString(),'reason'=>'Thu hẹp quyền vào nhà được chỉ định']);
        $this->assertSame($parent->id,$child->source_id);$this->assertSame(2,$child->depth);
        app(WorkspaceService::class)->revokeGrant($this->root(),$parent->id);
        $this->assertNotNull($child->fresh()->revoked_at);
        $this->assertFalse(app(Authorization::class)->allowed($recipient,'invoice.view',$w,$child->property_id));
        $this->assertTrue(app(Authorization::class)->allowed($w->owner,'invoice.view',$w,$child->property_id));
    }

    public function test_disabled_and_retired_account_sources_block_new_branches_and_restore_only_valid_grants(): void
    {
        $a=User::factory()->create();$this->delegate($a);
        $group=AccessGroup::create(['scope'=>'global','name'=>'Nhóm nguồn của tài khoản cấp','permissions'=>['invoice.view'],'active'=>true]);
        $a->forceFill(['access_group_id'=>$group->id])->save();$b=User::factory()->create();$this->delegate($b);$grant=$this->grant($a,$b);
        $group->update(['status'=>'disabled']);$this->assertFalse(app(Authorization::class)->global($b,'invoice.view'));$this->assertNull($grant->fresh()->revoked_at);
        // Fixture changes model state; the real restore UI requires GroupChange approval.
        $group->update(['status'=>'retired']);$this->assertTrue(app(Authorization::class)->global($b,'invoice.view'));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);$this->grant($b,User::factory()->create());
    }

    public function test_non_delegable_permission_and_forged_authority_are_enforced_even_for_root(): void
    {
        $target=User::factory()->create();$g=$this->grant($this->root(),$target,['authority_kind'=>'organization','source_kind'=>'ownership']);
        $this->assertSame('platform',$g->authority_kind);$this->assertContains($g->source_kind,['platform','assignment']);$this->assertNotSame('ownership',$g->source_kind);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->grant($this->root(),$target,['permission'=>'account.view']);
    }

    public function test_global_depth_tightening_and_exact_expiry_do_not_restore_terminated_sources(): void
    {
        $a=User::factory()->create();$b=User::factory()->create();$this->delegate($a);
        $one=$this->grant($this->root(),$a);$two=$this->grant($a,$b);
        $p=Permission::where('code','invoice.view')->firstOrFail();$p->update(['max_depth'=>1]);
        $this->assertNotNull($two->fresh()->revoked_at);$p->update(['max_depth'=>3]);
        $this->assertFalse(app(Authorization::class)->global($b,'invoice.view'));
        $this->travelTo($one->valid_until);
        $this->assertFalse(app(Authorization::class)->global($a,'invoice.view'));
    }

    public function test_global_authority_does_not_bypass_cloud_expiry_or_lock_unrelated_workspace(): void
    {
        $target=User::factory()->create();$this->grant($this->root(),$target);
        $w=Workspace::whereHas('owner',fn($q)=>$q->where('email','owner@trohub.test'))->firstOrFail();
        $other=Workspace::whereHas('owner',fn($q)=>$q->where('email','other@trohub.test'))->firstOrFail();
        $access=app(Authorization::class);$property=$w->properties()->firstOrFail()->id;
        $this->assertTrue($access->allowed($target,'invoice.view',$w,$property));
        $w->update(['expires_at'=>now()]);
        $this->assertFalse($access->allowed($target,'invoice.view',$w,$property));
        $this->assertTrue($access->global($target,'invoice.view'));
        $this->assertTrue($access->allowed($target,'invoice.view',$other,$other->properties()->firstOrFail()->id));
    }
}
