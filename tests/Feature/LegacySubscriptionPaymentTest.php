<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Subscription\Models\SubscriptionOrder;
use App\Modules\Workspace\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacySubscriptionPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpriced_legacy_order_cannot_activate_or_extend_paid_entitlement(): void
    {
        $this->seed(DatabaseSeeder::class);
        $workspace = Workspace::whereHas('owner', fn ($q) => $q->where('email', 'owner@trohub.test'))->firstOrFail();
        $before = $workspace->only(['plan', 'status', 'expires_at', 'cloud_epoch']);
        $order = SubscriptionOrder::create(['workspace_id' => $workspace->id, 'plan' => 'monthly', 'reference' => 'UNPRICED-LEGACY-ORDER']);
        $this->actingAs(User::where('email', 'platform@trohub.test')->firstOrFail())->post('/actions/subscription.confirm', ['context' => 'platform-admin', 'id' => $order->id])->assertConflict();
        $this->assertEquals($before, $workspace->fresh()->only(['plan', 'status', 'expires_at', 'cloud_epoch']));
        $this->assertSame('pending', $order->fresh()->status);
    }
}
