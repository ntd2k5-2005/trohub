<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\AuthEmail;
use App\Modules\Identity\EmailActivation;
use App\Modules\Identity\Models\AuthMailDelivery;
use App\Modules\Identity\Models\EmailChallenge;
use App\Modules\Identity\SendAuthMail;
use Database\Seeders\AccessSeeder;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmailAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        // Some existing policy migrations deliberately prohibit down(). Rebuild only
        // this verified in-memory connection, never roll policy schema back.
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(AccessSeeder::class);
        config(['email_auth.queue' => false, 'email_auth.show_social_buttons' => false]);
        Mail::fake();
    }

    private function register(string $email = 'activation@example.test'): User
    {
        $this->post('/dang-ky', ['name' => 'Người dùng mới', 'email' => $email, 'password' => 'SecurePass2026', 'password_confirmation' => 'SecurePass2026', 'access_group_id' => 999])
            ->assertRedirect('/xac-minh-email')->assertSessionHasNoErrors();

        return User::where('email', mb_strtolower(trim($email)))->firstOrFail();
    }

    private function email(): AuthEmail
    {
        $mail = Mail::sent(AuthEmail::class)->last();
        $this->assertInstanceOf(AuthEmail::class, $mail);

        return $mail;
    }

    private function link(AuthEmail $mail)
    {
        $this->get($mail->contentData['url'])->assertOk()->assertSee('Xác nhận email của tôi');
        $parts = explode('/', parse_url($mail->contentData['url'], PHP_URL_PATH));

        return $this->post('/xac-minh-email/link', ['challenge' => $parts[2], 'token' => $parts[3]]);
    }

    public function test_registration_sends_one_email_with_both_link_and_otp_and_no_session_or_privilege(): void
    {
        $user = $this->register(' ACTIVATION@EXAMPLE.TEST ');
        $this->assertGuest();
        $this->assertTrue($user->needsEmailActivation());
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('SecurePass2026', $user->password));
        $this->assertNotEquals(999, $user->access_group_id);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('memberships', 0);
        Mail::assertSentCount(1);
        $mail = $this->email();
        $html = $mail->render();
        $this->assertStringContainsString($mail->contentData['otp'], $html);
        $this->assertStringContainsString($mail->contentData['url'], $html);
        $challenge = EmailChallenge::firstOrFail();
        $this->assertNotSame($mail->contentData['otp'], $challenge->otp_hash);
        $delivery = AuthMailDelivery::firstOrFail();
        $this->assertSame('accepted', $delivery->status);
        $this->assertSame('MOCK_TEST_PASSED', $delivery->acceptance);
        $this->assertNull($delivery->payload);
        $this->get('/ui/personal-dashboard?context=personal')->assertForbidden();
    }

    public function test_otp_activates_once_then_link_and_same_otp_are_unusable(): void
    {
        $user = $this->register();
        $mail = $this->email();
        $this->post('/xac-minh-email/otp', ['otp' => $mail->contentData['otp']])->assertRedirect('/dang-nhap');
        $this->assertFalse($user->fresh()->needsEmailActivation());
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest();
        $this->link($mail)->assertSessionHasErrors('verification');
        $this->assertNotNull(EmailChallenge::first()->consumed_at);
        $this->post('/dang-nhap', ['email' => $user->email, 'password' => 'SecurePass2026'])->assertRedirect('/ui/personal-dashboard?context=personal');
        $this->assertAuthenticatedAs($user);
        $this->get('/ui/personal-dashboard?context=personal')->assertOk();
        $this->get('/ui/admin-dashboard?context=platform-admin')->assertForbidden();
        $this->get('/ui/properties?context=workspace')->assertForbidden();
    }

    public function test_link_activates_without_original_browser_and_get_does_not_consume(): void
    {
        $user = $this->register();
        $mail = $this->email();
        $this->flushSession();
        $this->get($mail->contentData['url'])->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->post('/xac-minh-email/link', ['challenge' => EmailChallenge::first()->id, 'token' => str_repeat('x', 64)])->assertSessionHasErrors('verification');
        $this->link($mail)->assertRedirect('/dang-nhap');
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertGuest();
        $id = EmailChallenge::first()->id;
        $this->expectException(ValidationException::class);
        app(EmailActivation::class)->consume($id, $mail->contentData['otp'], 'otp');
    }

    public function test_otp_and_link_expire_at_the_shared_deadline(): void
    {
        $user = $this->register();
        $mail = $this->email();
        $this->travelTo(EmailChallenge::first()->expires_at);
        $this->post('/xac-minh-email/otp', ['otp' => $mail->contentData['otp']])->assertSessionHasErrors('verification');
        $this->link($mail)->assertSessionHasErrors('verification');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_wrong_otp_attempts_commit_and_exhaust_both_methods(): void
    {
        $this->register();
        $mail = $this->email();
        for ($i = 0; $i < config('email_auth.verification_attempts'); $i++) {
            $this->post('/xac-minh-email/otp', ['otp' => '000000'])->assertSessionHasErrors('verification');
        }
        $this->assertSame(5, (int) EmailChallenge::first()->attempts);
        $this->post('/xac-minh-email/otp', ['otp' => $mail->contentData['otp']])->assertSessionHasErrors('verification');
        $this->post('/xac-minh-email/otp', ['otp' => $mail->contentData['otp']])->assertStatus(429);
        $this->link($mail)->assertSessionHasErrors('verification');
    }

    public function test_resend_is_throttled_and_revokes_both_old_credentials(): void
    {
        $user = $this->register();
        $old = $this->email();
        $this->post('/xac-minh-email/gui-lai')->assertSessionHasErrors('email');
        Mail::assertSentCount(1);
        $this->travel(config('email_auth.resend_seconds') + 1)->seconds();
        $this->post('/xac-minh-email/gui-lai')->assertRedirect('/xac-minh-email');
        Mail::assertSentCount(2);
        $new = $this->email();
        $this->assertDatabaseCount('email_verification_challenges', 2);
        $this->assertSame(1, EmailChallenge::whereNotNull('active_user_id')->count());
        $this->link($old)->assertSessionHasErrors('verification');
        // Ensure old OTP is checked against its old challenge, without a probabilistic random-code comparison.
        try {
            app(EmailActivation::class)->consume(EmailChallenge::whereNotNull('revoked_at')->first()->id, $old->contentData['otp'], 'otp');
            $this->fail('Old OTP must fail');
        } catch (ValidationException) {
        }
        $this->link($new)->assertRedirect('/dang-nhap');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_unactivated_correct_password_only_opens_verification_and_wrong_password_does_not(): void
    {
        $user = $this->register();
        $this->flushSession();
        $this->post('/dang-nhap', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/dang-nhap', ['email' => $user->email, 'password' => 'SecurePass2026'])->assertRedirect('/xac-minh-email');
        $this->assertGuest();
        $this->actingAs($user)->post('/actions/profile', ['name' => 'Changed'])->assertRedirect('/xac-minh-email');
        $this->assertGuest();
        $this->assertNotSame('Changed', $user->fresh()->name);
    }

    public function test_locked_user_and_changed_email_cannot_consume_old_challenge(): void
    {
        $user = $this->register();
        $mail = $this->email();
        $user->forceFill(['status' => 'locked'])->save();
        $this->link($mail)->assertSessionHasErrors('verification');
        $user->forceFill(['status' => 'active', 'email' => 'changed@example.test'])->save();
        $this->link($mail)->assertSessionHasErrors('verification');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_legacy_unverified_user_is_preserved_and_social_buttons_are_hidden(): void
    {
        $user = User::factory()->create(['email_verified_at' => null, 'password' => 'SecurePass2026']);
        $this->post('/dang-nhap', ['email' => $user->email, 'password' => 'SecurePass2026'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->post('/dang-xuat')->assertRedirect('/');
        foreach (['login', 'register'] as $screen) {
            $this->get('/ui/'.$screen.'?context=public')->assertOk()->assertDontSee('Tiếp tục với Google')->assertDontSee('Tiếp tục với Facebook');
        }
    }

    public function test_failed_smtp_is_safe_keeps_activation_pending_and_resend_can_recover(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp-secret-must-not-leak'));
        $user = $this->register();
        $this->get('/xac-minh-email')->assertOk()->assertSee('Chưa gửi được email')->assertDontSee('smtp-secret');
        $this->assertNull($user->email_verified_at);
        $this->assertSame('failed', AuthMailDelivery::first()->status);
        $cipher = DB::table('auth_mail_deliveries')->value('payload');
        $this->assertNotEmpty($cipher);
        $this->assertStringNotContainsString('/xac-minh-email/', $cipher);
        Mail::swap(new MailFake(new MailManager($this->app)));
        $this->travel(61)->seconds();
        $this->post('/xac-minh-email/gui-lai')->assertRedirect();
        Mail::assertSentCount(1);
        $this->link($this->email())->assertRedirect('/dang-nhap');
    }

    public function test_queued_mail_is_dispatched_after_commit_and_old_or_expired_jobs_do_not_send(): void
    {
        config(['email_auth.queue' => true]);
        Queue::fake();
        $user = $this->register();
        Queue::assertPushed(SendAuthMail::class);
        Mail::assertNothingSent();
        $old = AuthMailDelivery::first();
        $this->travel(61)->seconds();
        app(EmailActivation::class)->issue($user);
        (new SendAuthMail($old->id))->handle();
        Mail::assertNothingSent();
        $current = AuthMailDelivery::where('status', 'pending')->first();
        $this->travelTo($current->expires_at);
        (new SendAuthMail($current->id))->handle();
        Mail::assertNothingSent();
        $this->assertSame('cancelled', $current->fresh()->status);
    }

    public function test_job_retries_are_bounded_and_accepted_delivery_is_not_sent_twice(): void
    {
        config(['email_auth.queue' => true]);
        Queue::fake();
        $this->register();
        $row = AuthMailDelivery::first();
        $job = new SendAuthMail($row->id);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('raw-transport-secret'));
        try {
            $job->handle();
            $this->fail('Expected sanitized failure');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('raw-transport-secret', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame(1, (int) $row->fresh()->attempts);
        Mail::swap(new MailFake(new MailManager($this->app)));
        $job->handle();
        $job->handle();
        Mail::assertSentCount(1);
        $this->assertSame(2, (int) $row->fresh()->attempts);
        $this->assertSame([30, 120, 300], $job->backoff());
        $this->assertSame(3, $job->tries);
    }

    public function test_database_queue_worker_processes_real_job_using_mail_fake(): void
    {
        config(['email_auth.queue' => true, 'queue.default' => 'database']);
        $this->register();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('jobs', 1);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'auth-mail', '--once' => true, '--sleep' => 0]);
        Mail::assertSentCount(1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('MOCK_TEST_PASSED', AuthMailDelivery::first()->acceptance);
    }

    public function test_rolled_back_registration_does_not_enqueue_or_send_mail(): void
    {
        config(['email_auth.queue' => true]);
        Queue::fake();
        DB::beginTransaction();
        $user = User::factory()->create(['email_verified_at' => null, 'email_activation_required' => true]);
        app(EmailActivation::class)->issue($user);
        DB::rollBack();
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('auth_mail_deliveries', 0);
    }

    public function test_terminal_send_failure_does_not_retry_forever(): void
    {
        config(['email_auth.queue' => true]);
        Queue::fake();
        $this->register();
        $row = AuthMailDelivery::first();
        $job = new SendAuthMail($row->id);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('private-transport-error'));
        for ($i = 0; $i < 3; $i++) {
            try {
                $job->handle();
            } catch (\RuntimeException) {
            }
        }
        $this->assertSame(3, (int) $row->fresh()->attempts);
        $this->assertSame('failed', $row->fresh()->status);
        Mail::swap(new MailFake(new MailManager($this->app)));
        $job->handle();
        Mail::assertNothingSent();
    }

    public function test_recovery_known_and_unknown_email_have_same_response_and_mail_has_safe_reset_link(): void
    {
        $user = User::factory()->create();
        $url = '/ui/recover-access?context=public';
        $this->from($url)->post('/quen-mat-khau', ['email' => $user->email])->assertRedirect($url);
        $known = session('status');
        $this->from($url)->post('/quen-mat-khau', ['email' => 'unknown@example.test'])->assertRedirect($url);
        $this->assertSame($known, session('status'));
        Mail::assertSentCount(1);
        $mail = $this->email();
        $this->assertSame('recovery', $mail->contentData['purpose']);
        $this->assertStringContainsString('/dat-lai-mat-khau/', $mail->contentData['url']);
        $this->assertSame('MOCK_TEST_PASSED', AuthMailDelivery::first()->acceptance);
    }

    public function test_reset_is_single_use_revokes_sessions_and_does_not_activate_email_or_escalate_permissions(): void
    {
        $user = $this->register();
        $this->post('/quen-mat-khau', ['email' => $user->email]);
        $mail = $this->email();
        $path = parse_url($mail->contentData['url'], PHP_URL_PATH);
        $token = basename($path);
        $data = ['token' => $token, 'email' => $user->email, 'password' => 'NewSecurePass2026', 'password_confirmation' => 'NewSecurePass2026'];
        $this->post('/dat-lai-mat-khau', $data)->assertRedirect('/dang-nhap');
        $this->assertTrue(Hash::check($data['password'], $user->fresh()->password));
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertTrue($user->fresh()->needsEmailActivation());
        $this->assertSame(1, (int) $user->fresh()->auth_version);
        $this->post('/dat-lai-mat-khau', $data)->assertSessionHasErrors('email');
        $this->post('/dang-nhap', ['email' => $user->email, 'password' => $data['password']])->assertRedirect('/xac-minh-email');
        $this->assertGuest();
        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_reset_expiry_resend_and_wrong_token(): void
    {
        $user = User::factory()->create();
        $first = Password::createToken($user);
        $second = Password::createToken($user);
        $data = ['email' => $user->email, 'password' => 'NewSecurePass2026', 'password_confirmation' => 'NewSecurePass2026'];
        $this->post('/dat-lai-mat-khau', $data + ['token' => $first])->assertSessionHasErrors('email');
        $this->travel(config('auth.passwords.users.expire') + 1)->minutes();
        $this->post('/dat-lai-mat-khau', $data + ['token' => $second])->assertSessionHasErrors('email');
        $user->forceFill(['status' => 'locked'])->save();
        $lockedToken = Password::createToken($user);
        $this->post('/dat-lai-mat-khau', $data + ['token' => $lockedToken])->assertRedirect('/dang-nhap');
        $this->assertSame('locked', $user->fresh()->status);
        $this->post('/dang-nhap', ['email' => $user->email, 'password' => $data['password']])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_reset_invalidates_password_session_even_on_non_ui_routes(): void
    {
        $user = User::factory()->create();
        $version = (int) $user->auth_version;
        $token = Password::createToken($user);
        $this->post('/dat-lai-mat-khau', ['email' => $user->email, 'token' => $token, 'password' => 'NewSecurePass2026', 'password_confirmation' => 'NewSecurePass2026'])->assertRedirect();
        $this->actingAs($user->fresh())->withSession(['auth_version' => $version])->post('/auth/google/unlink', ['current_password' => 'NewSecurePass2026'])->assertRedirect('/dang-nhap');
        $this->assertGuest();
    }
}
