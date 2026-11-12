<?php

namespace Tests\Feature;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\GroupAssignments;
use App\Modules\Listing\ListingService;
use App\Modules\Property\{AssetLifecycle, PropertyService};
use App\Modules\Property\Models\{Property, Room};
use App\Modules\Subscription\PropertyCloud;
use App\Modules\Workspace\{OwnerQuota, WorkspaceArchive, WorkspaceLifecycle};
use App\Modules\Workspace\Models\{Membership, Workspace};
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceLifecycleAndQuotaTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->freezeTime(); $this->seed(DatabaseSeeder::class); }
    private function w(): Workspace { return Workspace::whereHas('owner',fn($q)=>$q->where('email','owner@trohub.test'))->firstOrFail(); }
    private function emptyW(): Workspace
    {
        $w=Workspace::create(['owner_id'=>$this->w()->owner_id,'name'=>'Khu thứ hai','status'=>'active','plan'=>'yearly','expires_at'=>now()->addYear()]);app(GroupAssignments::class)->owner($w);return $w;
    }
    private function denied(callable $fn,int $status=409): void {try{$fn();$this->fail('Phải bị chặn.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame($status,$e->getStatusCode());}}
    public function test_legacy_creation_cannot_bypass_unspecified_owner_workspace_quota():void
    {
        $user=User::where('email','user@trohub.test')->firstOrFail();$count=Workspace::count();config(['business.limits.trial.workspaces'=>null,'business.limits.monthly.workspaces'=>null]);$service=app(\App\Modules\Subscription\SubscriptionService::class);
        $this->denied(fn()=>$service->trial($user,'Không được bỏ qua cấu hình'));
        $this->denied(fn()=>$service->startPaid($user,['name'=>'Không được dùng gói chờ để lách hạn mức','plan'=>'monthly','reference'=>'QUOTA-BYPASS']));$this->assertSame($count,Workspace::count());
    }
    public function test_pause_blocks_admission_but_keeps_existing_obligations_and_restore_does_not_publish(): void
    {
        $w=$this->w();$s=app(WorkspaceLifecycle::class);$financial=DB::table('payments')->get()->toJson();
        $s->transition($w->owner,$w,'paused',$w->version,'Tạm dừng tiếp nhận khách mới');
        $this->assertTrue(app(Authorization::class)->allowed($w->owner,'invoice.view',$w));
        $this->assertTrue(app(Authorization::class)->allowed($w->owner,'payment.record',$w));
        $this->assertFalse(app(Authorization::class)->allowed($w->owner,'listing.publish',$w));
        $this->assertFalse(app(Authorization::class)->allowed($w->owner,'rental.create',$w));
        $this->assertSame(0,app(ListingService::class)->available()->whereHas('room.property',fn($q)=>$q->where('workspace_id',$w->id))->count());
        $this->denied(fn()=> $s->transition($w->owner,$w->fresh(),'active',$w->fresh()->version,'Mở tiếp nhận sau kiểm tra'));
        config(['business.limits.yearly.workspaces'=>10]);
        $this->actingAs($w->owner)->post('/actions/workspace.lifecycle',['context'=>'personal','id'=>$w->id,'state'=>'active','version'=>$w->fresh()->version,'reason'=>'Mở tiếp nhận sau kiểm tra','confirm'=>1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active',$w->fresh()->lifecycle);$this->assertSame($financial,DB::table('payments')->get()->toJson());
        $this->assertDatabaseMissing('listings',['status'=>'published','room_id'=>$w->properties->first()->rooms->first()->id]);
        $this->denied(fn()=> $s->transition($w->owner,$w,'paused',1,'Gửi lại yêu cầu cũ'));
    }
    public function test_archived_restore_checks_quota_and_never_reactivates_staff(): void
    {
        $w=$this->emptyW();$m=Membership::create(['workspace_id'=>$w->id,'user_id'=>User::factory()->create()->id,'status'=>'active','permissions'=>['property.view']]);
        app(WorkspaceArchive::class)->archive($w->owner,$w,'Ngừng vận hành khu trống');
        $current=app(OwnerQuota::class)->usage($w->owner_id,'workspaces');
        config(['business.limits.yearly.workspaces'=>$current]);$s=app(WorkspaceLifecycle::class);
        $this->denied(fn()=> $s->transition($w->owner,$w->fresh(),'active',$w->fresh()->version,'Khôi phục khu quản lý'));
        config(['business.limits.yearly.workspaces'=>$current+1]);$s->transition($w->owner,$w->fresh(),'active',$w->fresh()->version,'Khôi phục khu quản lý');
        $this->assertSame('suspended',$m->fresh()->status);$this->assertSame($current+1,app(OwnerQuota::class)->usage($w->owner_id,'workspaces'));
    }
    public function test_restrictions_are_independent_overlays_and_release_does_not_clear_other_causes(): void
    {
        $w=$this->w();$admin=User::where('email','supreme@trohub.test')->firstOrFail();$s=app(WorkspaceLifecycle::class);
        $a=$s->restrict($admin,$w,'review-one','Cần xác minh hồ sơ thứ nhất');$b=$s->restrict($admin,$w,'review-two','Cần xác minh hồ sơ thứ hai');
        $this->assertSame($a->id,$s->restrict($admin,$w,'review-one','Yêu cầu gửi lặp')->id);
        $this->assertFalse(app(PropertyCloud::class)->active($w));$this->assertSame('active',$w->fresh()->lifecycle);
        $this->denied(fn()=> $s->release($w->owner,$a->id,'Chủ trọ tự gỡ hạn chế'),403);
        $s->release($admin,$a->id,'Đã xử lý hồ sơ thứ nhất');$this->assertFalse(app(PropertyCloud::class)->active($w));
        $s->release($admin,$b->id,'Đã xử lý hồ sơ thứ hai');$this->assertTrue(app(PropertyCloud::class)->active($w));
        $this->assertFalse($w->properties->flatMap->rooms->flatMap->listings->contains('status','published'));
    }
    public function test_owner_room_quota_spans_workspaces_and_archive_does_not_free_asset_usage(): void
    {
        $owner=$this->w()->owner;$other=$this->emptyW();$p=Property::create(['workspace_id'=>$other->id,'name'=>'Nhà khác','address'=>'Địa chỉ thử','district'=>'Thủ Đức']);
        $usage=app(OwnerQuota::class)->usage($owner->id,'rooms');config(['business.limits.yearly.rooms'=>$usage]);
        $this->denied(fn()=>app(PropertyService::class)->saveRoom($owner,$p,['name'=>'Phòng mới','area'=>20,'rent'=>2000000]));
        Room::create(['property_id'=>$p->id,'name'=>'Phòng tính quota','rent'=>2000000,'area'=>20]);
        app(WorkspaceArchive::class)->archive($owner,$other,'Lưu trữ khu không có nghĩa vụ');
        $this->assertSame($usage+1,app(OwnerQuota::class)->usage($owner->id,'rooms'));
        $this->assertNull($p->fresh()->retired_at);
    }
    public function test_retirement_releases_only_settled_assets_and_restore_requires_capacity(): void
    {
        $w=$this->emptyW();$p=Property::create(['workspace_id'=>$w->id,'name'=>'Nhà nghỉ khai thác','address'=>'Địa chỉ thử','district'=>'Thủ Đức']);$room=Room::create(['property_id'=>$p->id,'name'=>'Phòng nghỉ','rent'=>1000000,'area'=>15]);
        $q=app(OwnerQuota::class);$before=$q->usage($w->owner_id,'rooms');$s=app(AssetLifecycle::class);
        $s->change($w->owner,$w,'property',$p->id,$p->fresh()->version,false,'Ngừng nhà và toàn bộ phòng');
        $this->assertSame($before-1,$q->usage($w->owner_id,'rooms'));$this->assertNotNull($room->fresh()->retired_at);
        $this->denied(fn()=> $s->change($w->owner,$w,'room',$room->id,$room->fresh()->version,true,'Mở lại phòng'));
        config(['business.limits.yearly.properties'=>10]);$s->change($w->owner,$w,'property',$p->id,$p->fresh()->version,true,'Mở lại nhà');
        $this->assertNotNull($room->fresh()->retired_at);
        config(['business.limits.yearly.rooms'=>$q->usage($w->owner_id,'rooms')]);$this->denied(fn()=> $s->change($w->owner,$w,'room',$room->id,$room->fresh()->version,true,'Mở lại phòng'));
        config(['business.limits.yearly.rooms'=>$before]);$s->change($w->owner,$w,'room',$room->id,$room->fresh()->version,true,'Mở lại phòng');
        $this->assertNull($room->fresh()->retired_at);
        $active=\App\Modules\Rental\Models\Rental::where('status','active')->firstOrFail()->room;$money=DB::table('payments')->get()->toJson();
        $this->denied(fn()=> $s->change($this->w()->owner,$this->w(),'room',$active->id,$active->version,false,'Không được bỏ nghĩa vụ thuê'));
        $this->assertSame($money,DB::table('payments')->get()->toJson());
    }
}
