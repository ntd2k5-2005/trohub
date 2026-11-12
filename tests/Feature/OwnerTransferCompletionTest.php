<?php

namespace Tests\Feature;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Subscription\Models\AccountSubscription;
use App\Modules\Subscription\Models\PlanVersion;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\OwnerTransferService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OwnerTransferCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->freezeTime();
        $this->seed(DatabaseSeeder::class);
        $workspace = Workspace::whereHas('owner', fn ($q) => $q->where('email', 'owner@trohub.test'))->firstOrFail();
        $recipient = User::factory()->create(['password' => Hash::make('TransferFixture123!')]);
        $snapshot = ['plan'=>'monthly','label'=>'Gói kiểm thử bàn giao','price'=>75000,'currency'=>'VND','billing_cycle'=>'monthly','duration_count'=>1,'duration_unit'=>'months','features'=>['workspace','property','room','listing','billing','maintenance','iot'],'limits'=>['workspaces'=>3,'properties'=>20,'rooms'=>100,'staff'=>20]];
        $plan = PlanVersion::create(['plan'=>'monthly','snapshot'=>$snapshot,'fingerprint'=>hash('sha256',json_encode($snapshot)),'published_at'=>now()]);
        AccountSubscription::create(['owner_id'=>$recipient->id,'plan_version_id'=>$plan->id,'status'=>'active','expires_at'=>now()->addMonth(),'authority_enabled_at'=>now()]);
        return [$workspace, $recipient, app(OwnerTransferService::class)];
    }

    public function test_recipient_consent_completes_internal_handover_with_active_rentals_and_history_intact(): void
    {
        [$workspace, $recipient, $service] = $this->fixture();
        $oldOwner = $workspace->owner;
        $history = [];
        foreach (['rentals','invoices','payments','deposits','contract_versions'] as $table) $history[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        $case = $service->submit($oldOwner, $workspace, $recipient->email, 'INTERNAL-TRANSFER', 'Bàn giao vận hành nội bộ');
        $service->consent($recipient, $case->id, $case->version, 'TransferFixture123!');
        $this->assertSame($recipient->id, $workspace->fresh()->owner_id);
        $this->assertSame('completed', $case->fresh()->status);
        $this->assertNotNull($case->fresh()->completed_at);
        $this->assertTrue(app(Authorization::class)->allowed($recipient, 'property.view', $workspace->fresh()));
        $this->assertFalse(app(Authorization::class)->allowed($oldOwner, 'property.view', $workspace->fresh()));
        foreach ($history as $table => $before) $this->assertSame($before, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        $this->actingAs($recipient)->get('/ui/properties?context=workspace&workspace_id='.$workspace->id)->assertOk();
        $this->actingAs($oldOwner)->get('/ui/properties?context=workspace&workspace_id='.$workspace->id)->assertForbidden();
        $this->actingAs($oldOwner)->get('/ui/profile-security?context=personal&section=access')->assertOk()->assertSee('Đã hoàn tất');
    }

    public function test_competing_handover_cannot_change_owner_twice_and_unrelated_user_is_denied(): void
    {
        [$workspace, $recipient, $service] = $this->fixture();
        $first = $service->submit($workspace->owner, $workspace, $recipient->email, 'TRANSFER-A', 'Đề nghị thứ nhất');
        $other = $service->submit($workspace->owner, $workspace, $recipient->email, 'TRANSFER-B', 'Đề nghị thứ hai');
        $service->consent($recipient, $first->id, $first->version, 'TransferFixture123!');
        $this->assertSame('cancelled', $other->fresh()->status);
        try { $service->consent($recipient, $other->id, $other->version, 'TransferFixture123!'); $this->fail('Stale handover must be rejected'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(409, $error->getStatusCode()); }
        $foreign = User::where('email','user@trohub.test')->firstOrFail();
        $this->assertSame(0, $service->visible($foreign)->count());
        $this->assertSame($recipient->id, $workspace->fresh()->owner_id);
    }

    public function test_independent_staff_authority_is_preserved_but_owner_authority_is_removed(): void
    {
        [$workspace, $recipient, $service] = $this->fixture();
        $owner = $workspace->owner;
        $member = \App\Modules\Workspace\Models\Membership::create(['workspace_id'=>$workspace->id,'user_id'=>$owner->id,'status'=>'active','permissions'=>['property.view']]);
        $case = $service->submit($owner,$workspace,$recipient->email,'PRESERVE-INDEPENDENT','Bàn giao với quyền đọc độc lập');
        $foreign = User::where('email','user@trohub.test')->firstOrFail();
        $this->actingAs($foreign)->post('/actions/transfer.consent',['context'=>'personal','id'=>$case->id,'version'=>$case->version,'password'=>'TransferFixture123!','confirm'=>true])->assertForbidden();
        $service->consent($recipient,$case->id,$case->version,'TransferFixture123!');
        $this->assertSame('active',$member->fresh()->status);
        $this->assertTrue(app(Authorization::class)->allowed($owner,'property.view',$workspace->fresh()));
        $this->assertFalse(app(Authorization::class)->allowed($owner,'property.create',$workspace->fresh()));
    }
}
