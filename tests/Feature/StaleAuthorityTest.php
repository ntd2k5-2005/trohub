<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Billing\BillingService;
use App\Modules\Billing\Models\ServiceRate;
use App\Modules\Listing\ListingService;
use App\Modules\Listing\Models\Listing;
use App\Modules\Property\Models\Property;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StaleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $permission): array
    {
        $this->seed(DatabaseSeeder::class);
        $workspace = Workspace::whereHas('owner', fn ($q) => $q->where('email', 'owner@trohub.test'))->firstOrFail();
        $property = Property::where('workspace_id', $workspace->id)->firstOrFail();
        $user = User::factory()->create();
        $member = Membership::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'status' => 'active', 'permissions' => [$permission]]);

        return [$user, $member, $workspace, $property];
    }

    public function test_rate_write_rolls_back_when_authority_changes_before_insert(): void
    {
        [$user, $member, $workspace, $property] = $this->fixture('utility.rate');
        ServiceRate::creating(fn () => DB::table('memberships')->where('id', $member->id)->update(['status' => 'ended']));
        $rejected = false;
        try {
            app(BillingService::class)->createRate($user, ['property_id' => $property->id, 'name' => 'PHASE2_RATE', 'kind' => 'fixed', 'rate' => 12345, 'effective_from' => today()->toDateString()]);
        } catch (HttpException $e) {
            $rejected = $e->getStatusCode() === 403;
        } finally {
            Event::forget('eloquent.creating: '.ServiceRate::class);
        }
        $this->assertTrue($rejected, 'Revoked authority must reject the write');
        $this->assertDatabaseMissing('service_rates', ['name' => 'PHASE2_RATE']);
        $this->assertDatabaseMissing('audit_entries', ['action' => 'service.created', 'actor_id' => $user->id]);
    }

    public function test_listing_write_rechecks_authority_after_workspace_lock(): void
    {
        [$user, $member, $workspace, $property] = $this->fixture('listing.create');
        $room = $property->rooms()->firstOrFail();
        $changed = false;
        $initial = DB::transactionLevel();
        DB::connection()->beforeExecuting(function ($query) use ($member, &$changed, $initial) {
            if (! $changed && DB::transactionLevel() > $initial && str_contains(strtolower($query), 'from "workspaces"')) {
                $changed = true;
                DB::table('memberships')->where('id', $member->id)->update(['status' => 'ended']);
            }
        });
        $rejected = false;
        try {
            app(ListingService::class)->save($user, $room->id, ['title' => 'PHASE2_LISTING', 'description' => 'Kiểm thử quyền', 'status' => 'draft']);
        } catch (HttpException $e) {
            $rejected = $e->getStatusCode() === 403;
        }
        $this->assertTrue($changed, 'Revocation must occur after initial authorization');
        $this->assertTrue($rejected, 'Revoked authority must reject the draft');
        $this->assertDatabaseMissing('listings', ['title' => 'PHASE2_LISTING']);
    }

    public function test_publish_permission_is_rechecked_even_when_update_permission_remains(): void
    {
        [$user, $member, $workspace, $property] = $this->fixture('listing.update');
        $member->update(['permissions' => ['listing.update', 'listing.publish']]);
        $room = $property->rooms()->where('status', 'vacant')->firstOrFail();
        $room->listings()->update(['status' => 'paused']);
        $listing = $room->listings()->create(['title' => 'Bản nháp kiểm thử', 'description' => 'Nội dung kiểm thử', 'status' => 'draft']);
        Listing::saving(fn () => DB::table('memberships')->where('id', $member->id)->update(['permissions' => json_encode(['listing.update'])]));
        $rejected = false;
        try {
            app(ListingService::class)->save($user, $room->id, ['status' => 'published'], $listing->id);
        } catch (HttpException $e) {
            $rejected = $e->getStatusCode() === 403;
        } finally {
            Event::forget('eloquent.saving: '.Listing::class);
        }
        $this->assertTrue($rejected);
        $this->assertSame('draft', $listing->fresh()->status);
        $this->assertDatabaseMissing('listing_versions', ['listing_id' => $listing->id]);
    }
}
