<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\Notification\Models\UserNotification;
use App\Modules\Workspace\InvitationService;
use App\Modules\Workspace\Models\Invitation;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\WorkspaceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvitationInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function owner(): User
    {
        return User::where('email', 'owner@trohub.test')->firstOrFail();
    }

    private function workspace(): Workspace
    {
        return Workspace::where('owner_id', $this->owner()->id)->firstOrFail();
    }

    private function invite(string $email): string
    {
        return app(WorkspaceService::class)->invite($this->owner(), $this->workspace(), ['email' => $email, 'access_group_id' => AccessGroup::where('name', 'Tùy chỉnh')->value('id'), 'permissions' => ['invoice.view']]);
    }

    public function test_existing_recipient_gets_one_actionable_notification_and_can_accept(): void
    {
        $recipient = User::factory()->create(['email_verified_at' => null]);
        $this->invite($recipient->email);
        $invite = Invitation::where('email', $recipient->email)->firstOrFail();
        $notification = UserNotification::where('user_id', $recipient->id)->firstOrFail();
        $this->assertNull($notification->read_at);
        $this->assertSame('/loi-moi-nhan/'.$invite->id, $notification->action_url);
        $this->assertStringNotContainsString($invite->token_hash, $notification->toJson());
        $this->actingAs($recipient)->get('/ui/notifications?context=personal')->assertOk()->assertSee('Xem lời mời');
        $this->get($notification->action_url)->assertOk()->assertSee('Chấp nhận lời mời')->assertSee('Từ chối lời mời');
        $this->assertNotNull($notification->fresh()->read_at);
        $this->get('/ui/notifications?context=personal')->assertOk();
        $this->assertSame(1, UserNotification::where('user_id', $recipient->id)->count());
        $this->assertNotNull($notification->fresh()->read_at);
        $this->post($notification->action_url, ['decision' => 'accept'])->assertRedirect();
        $this->assertDatabaseHas('memberships', ['user_id' => $recipient->id, 'workspace_id' => $this->workspace()->id, 'status' => 'active']);
        $this->get($notification->action_url)->assertOk()->assertDontSee('Chấp nhận lời mời')->assertSee('Bạn đã chấp nhận');
        $this->post($notification->action_url, ['decision' => 'accept'])->assertNotFound();
    }

    public function test_decline_updates_sender_list_releases_invitation_and_never_grants_access(): void
    {
        $recipient = User::factory()->create();
        $this->invite($recipient->email);
        $invite = Invitation::where('email', $recipient->email)->firstOrFail();
        $this->actingAs($recipient)->post('/loi-moi-nhan/'.$invite->id, ['decision' => 'decline'])->assertRedirect();
        $this->assertNotNull($invite->fresh()->declined_at);
        $this->assertDatabaseMissing('memberships', ['user_id' => $recipient->id]);
        $this->post('/loi-moi-nhan/'.$invite->id, ['decision' => 'accept'])->assertNotFound();
        $this->actingAs($this->owner())->get('/ui/team-permissions?context=workspace&section=invitations&status=declined')->assertOk()->assertSee($recipient->email)->assertSee('Đã từ chối');
        $this->invite($recipient->email);
        $this->assertSame(2, Invitation::where('email', $recipient->email)->count());
    }

    public function test_other_accounts_cannot_read_or_respond_and_locked_account_cannot_respond(): void
    {
        $recipient = User::factory()->create();
        $this->invite($recipient->email);
        $id = Invitation::where('email', $recipient->email)->value('id');
        $this->actingAs(User::factory()->create())->get('/loi-moi-nhan/'.$id)->assertNotFound();
        foreach (['accept', 'decline'] as $decision) {
            $this->post('/loi-moi-nhan/'.$id, ['decision' => $decision])->assertNotFound();
        }
        $recipient->forceFill(['status' => 'locked'])->save();
        $this->actingAs($recipient)->post('/loi-moi-nhan/'.$id, ['decision' => 'accept'])->assertForbidden();
        $this->assertDatabaseMissing('memberships', ['user_id' => $recipient->id]);
    }

    public function test_cancel_renew_expiry_and_revoked_grantor_are_checked_from_current_state(): void
    {
        $recipient = User::factory()->create();
        $token = $this->invite($recipient->email);
        $invite = Invitation::where('email', $recipient->email)->firstOrFail();
        app(InvitationService::class)->change($this->owner(), $this->workspace(), $invite->id, true);
        $this->assertSame(1, UserNotification::where('user_id', $recipient->id)->count());
        $this->actingAs($recipient)->get('/loi-moi/'.$token)->assertNotFound();
        $this->get('/loi-moi-nhan/'.$invite->id)->assertOk();
        app(InvitationService::class)->change($this->owner(), $this->workspace(), $invite->id, false);
        $this->get('/loi-moi-nhan/'.$invite->id)->assertOk()->assertSee('đã hủy')->assertDontSee('Chấp nhận lời mời');
        $this->post('/loi-moi-nhan/'.$invite->id, ['decision' => 'accept'])->assertNotFound();
        $this->invite($recipient->email);
        $latest = Invitation::where('email', $recipient->email)->latest('id')->first();
        $latest->update(['expires_at' => now()->subSecond()]);
        $this->get('/loi-moi-nhan/'.$latest->id)->assertOk()->assertSee('đã hết hạn');
        $this->post('/loi-moi-nhan/'.$latest->id, ['decision' => 'decline'])->assertSessionHasErrors('invitation');
        $latest->update(['expires_at' => now()->addDay()]);
        $this->owner()->forceFill(['status' => 'locked'])->save();
        $this->post('/loi-moi-nhan/'.$latest->id, ['decision' => 'accept'])->assertForbidden();
    }

    public function test_newly_claimed_unverified_email_cannot_accept_by_guessing_invitation_id(): void
    {
        $token = $this->invite('future@example.test');
        $invite = Invitation::where('email', 'future@example.test')->firstOrFail();
        $recipient = User::factory()->create(['email' => 'future@example.test', 'email_verified_at' => null]);
        $this->actingAs($recipient)->get('/ui/notifications?context=personal')->assertOk()->assertDontSee('Xem lời mời');
        $this->post('/loi-moi-nhan/'.$invite->id, ['decision' => 'accept'])->assertForbidden();
        // The original secret link still proves possession without requiring SMTP.
        $this->post('/loi-moi/'.$token, ['decision' => 'accept'])->assertRedirect();
        $this->assertSame($recipient->id, $invite->fresh()->recipient_id);
        $this->assertDatabaseHas('memberships', ['user_id' => $recipient->id]);
    }

    public function test_verified_later_recipient_gets_pending_invitation_on_login_without_duplicates(): void
    {
        $this->invite('verified@example.test');
        $recipient = User::factory()->create(['email' => 'verified@example.test', 'password' => 'SecurePass2026']);
        $this->post('/dang-nhap', ['email' => $recipient->email, 'password' => 'SecurePass2026'])->assertRedirect();
        $this->assertSame(1, UserNotification::where('user_id', $recipient->id)->count());
        $this->get('/ui/notifications?context=personal')->assertOk();
        $this->get('/ui/notifications?context=personal')->assertOk();
        $this->assertSame(1,UserNotification::where('user_id',$recipient->id)->count());
    }
}
