<?php
namespace Tests\Feature;
use App\Models\User;
use App\Modules\Workspace\OwnerTransferService;
use App\Modules\Workspace\Models\{Workspace,OwnerTransferEvidence};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Hash,Storage};
use Tests\TestCase;
class OwnershipCaseWorkflowTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->freezeTime();$this->seed(DatabaseSeeder::class);Storage::fake('local');}
    private function fixture():array{$w=Workspace::whereHas('owner',fn($q)=>$q->where('email','owner@trohub.test'))->firstOrFail();$u=User::factory()->create();$w->owner->forceFill(['password'=>Hash::make('FixturePassword123!')])->save();$u->forceFill(['password'=>Hash::make('FixturePassword123!')])->save();$s=app(OwnerTransferService::class);$case=$s->submit($w->owner,$w,$u->email,'TRANSFER-ONE','Đề nghị bàn giao cần thẩm tra');return[$s,$case,$w,$u];}
    private function denied(callable$f,int$status):void{try{$f();$this->fail('Phải bị chặn.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException$e){$this->assertSame($status,$e->getStatusCode());}}
    public function test_consents_and_optional_evidence_are_retained_when_recipient_has_no_valid_subscription():void
    {
        [$s,$c,$w,$u]=$this->fixture();$owner=$w->owner;$money=DB::table('payments')->get()->toJson();
        $s->evidence($owner,$c->id,$c->version,UploadedFile::fake()->create('chung-cu.pdf',10,'application/pdf'));$c->refresh();
        $s->consent($owner,$c->id,$c->version,'FixturePassword123!');$c->refresh();$s->consent($u,$c->id,$c->version,'FixturePassword123!');$c->refresh();
        $this->assertSame('blocked',$c->status);$reviewer=User::where('email','supreme@trohub.test')->firstOrFail();
        $s->review($reviewer,$c->id,$c->version,'review','Người nhận chưa có gói và hạn mức phù hợp');
        $this->assertSame('blocked',$c->fresh()->status);$this->assertSame($owner->id,$w->fresh()->owner_id);$this->assertSame($money,DB::table('payments')->get()->toJson());
        $this->actingAs($u)->get('/ui/profile-security?context=personal&section=access')->assertOk()->assertSee('Cần xử lý điều kiện');
    }
    public function test_evidence_is_private_versioned_and_cannot_reuse_old_consent_or_tampered_file():void
    {
        [$s,$c,$w,$u]=$this->fixture();$s->consent($w->owner,$c->id,$c->version,'FixturePassword123!');$c->refresh();$old=$c->version;
        $s->evidence($u,$c->id,$c->version,UploadedFile::fake()->create('ho-so.pdf',10,'application/pdf'));$c->refresh();$this->assertEmpty($c->consents);
        $this->denied(fn()=>$s->consent($u,$c->id,$old,'FixturePassword123!'),409);
        $e=OwnerTransferEvidence::where('owner_transfer_case_id',$c->id)->firstOrFail();
        $this->actingAs(User::where('email','user@trohub.test')->firstOrFail())->get('/ban-giao/'.$c->id.'/tai-lieu/'.$e->id)->assertNotFound();
        $this->actingAs($u)->get('/ban-giao/'.$c->id.'/tai-lieu/'.$e->id)->assertOk();Storage::disk('local')->put($e->path,'altered bytes');
        $this->denied(fn()=>$s->file($u,$c->id,$e->id),409);
    }
    public function test_self_review_wrong_password_expiry_and_recovery_do_not_transfer_owner():void
    {
        [$s,$c,$w,$u]=$this->fixture();$this->denied(fn()=>$s->consent($u,$c->id,$c->version,'WrongPassword123'),422);
        $s->consent($w->owner,$c->id,$c->version,'FixturePassword123!');$c->refresh();$w->owner->forceFill(['password'=>Hash::make('NewRecoveredPassword!')])->save();
        $this->assertContains('Một xác nhận không còn hợp lệ sau thay đổi bảo mật tài khoản.',$s->blockers($c));
        $s->cancel($w->owner,$c->id,$c->version,'Hủy để hoàn thiện hồ sơ');$this->assertSame('cancelled',$c->fresh()->status);$this->assertSame($w->owner_id,$w->fresh()->owner_id);
    }
    public function test_reviewer_must_be_independent_even_with_permission_and_expired_case_cannot_be_consented():void
    {
        [$s,$c,$w,$u]=$this->fixture();$root=User::where('email','supreme@trohub.test')->firstOrFail();
        $w->owner->forceFill(['access_group_id'=>$root->access_group_id])->save();
        $this->denied(fn()=>$s->review($w->owner,$c->id,$c->version,'review','Tự thẩm tra dù có quyền toàn cục'),403);
        $this->travelTo($c->expires_at);$this->denied(fn()=>$s->consent($u,$c->id,$c->version,'FixturePassword123!'),409);$this->assertSame($w->owner_id,$w->fresh()->owner_id);
    }
}
