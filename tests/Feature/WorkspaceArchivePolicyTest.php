<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessControl\GroupAssignments;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\Property\Models\Property;
use App\Modules\Property\Models\Room;
use App\Modules\Subscription\Models\SubscriptionOrder;
use App\Modules\Subscription\SubscriptionService;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\WorkspaceArchive;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceArchivePolicyTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->freezeTime(); $this->seed(DatabaseSeeder::class); }
    private function original(): Workspace { return Workspace::whereHas('owner', fn ($q) => $q->where('email', 'owner@trohub.test'))->firstOrFail(); }
    private function emptyWorkspace(): array
    {
        $w = Workspace::create(['owner_id' => $this->original()->owner_id, 'name' => 'Khu lưu trữ kiểm thử', 'status' => 'active', 'plan' => 'yearly', 'expires_at' => now()->addYear()]);
        app(GroupAssignments::class)->owner($w);
        $m = Membership::create(['workspace_id' => $w->id, 'user_id' => User::factory()->create()->id, 'status' => 'active', 'access_group_id' => AccessGroup::where('key', 'custom')->value('id'), 'permissions' => ['property.view']]);
        return [$w, $m];
    }

    public function test_archive_suspends_members_without_retiring_assets_or_deleting_authority_history(): void
    {
        [$w,$m] = $this->emptyWorkspace();
        $p = Property::create(['workspace_id' => $w->id, 'name' => 'Nhà vẫn được tính quản lý', 'address' => 'Địa chỉ kiểm thử', 'district' => 'Thủ Đức']);
        $r = Room::create(['property_id' => $p->id, 'name' => '101', 'area' => 20, 'rent' => 2000000]);
        $this->actingAs($w->owner)->post('/actions/workspace.archive', ['context' => 'workspace', 'workspace_id' => $w->id, 'reason' => 'Ngừng vận hành khu này', 'confirm' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('archived', $w->fresh()->status);
        $this->assertSame('suspended', $m->fresh()->status);
        $this->assertStringContainsString('lưu trữ', $m->fresh()->suspension_reason);
        $this->assertSame('active', $p->fresh()->status);
        $this->assertSame('vacant', $r->fresh()->status);
        $this->assertSame(['property.view'], $m->fresh()->permissions);
    }

    public function test_archive_with_existing_obligations_is_rejected_and_money_is_unchanged(): void
    {
        $w = $this->original(); $before = \Illuminate\Support\Facades\DB::table('invoices')->get()->toJson();
        $this->actingAs($w->owner)->post('/actions/workspace.archive', ['context' => 'workspace', 'workspace_id' => $w->id, 'reason' => 'Không được bỏ nghĩa vụ', 'confirm' => 1])->assertConflict();
        $this->assertSame('active', $w->fresh()->status);
        $this->assertSame($before, \Illuminate\Support\Facades\DB::table('invoices')->get()->toJson());
    }

    public function test_expiry_and_verified_account_renewal_do_not_restore_archived_membership_or_workspace(): void
    {
        [$w,$m] = $this->emptyWorkspace(); app(WorkspaceArchive::class)->archive($w->owner, $w, 'Lưu trữ có kiểm chứng');
        $w->update(['expires_at' => now()->subMinute()]);
        app(SubscriptionService::class)->expire();
        $this->assertSame('archived', $w->fresh()->status);
        \Tests\Support\SubscriptionFixture::renew($w, User::where('email', 'platform@trohub.test')->firstOrFail(), 'yearly');
        $this->assertSame('archived', $w->fresh()->status);
        $this->assertSame('suspended', $m->fresh()->status);
        $this->assertFalse(app(\App\Modules\Subscription\PropertyCloud::class)->active($w));
        $this->assertSame('archived', $w->fresh()->effective_status);
    }

    public function test_scoped_staff_cannot_archive_entire_workspace(): void
    {
        $w = $this->original(); $staff = User::where('email', 'staff@trohub.test')->firstOrFail();
        $this->actingAs($staff)->post('/actions/workspace.archive', ['context' => 'workspace', 'workspace_id' => $w->id, 'reason' => 'Không đủ quyền toàn khu', 'confirm' => 1])->assertForbidden();
        $this->assertSame('active', $w->fresh()->status);
    }
}
