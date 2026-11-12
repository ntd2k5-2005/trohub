<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\Identity\Models\SocialAccount;
use App\Modules\Identity\SocialEmailCode;
use App\Modules\Identity\SocialLogin;
use Database\Seeders\AccessSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    private array $history = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        config(['social_login.providers.google.enabled' => false, 'social_login.providers.facebook.enabled' => false]);
        Notification::fake();
    }

    private function provider(string $provider = 'google', array $raw = []): void
    {
        config(["social_login.providers.$provider.enabled" => true, "services.$provider.client_id" => 'test-client',
            "services.$provider.client_secret" => 'test-secret', "services.$provider.redirect" => "http://localhost/auth/$provider/callback"]);
        $raw = array_replace($provider === 'google' ? ['sub' => 'google-1', 'name' => 'Người dùng Google', 'email' => 'social@example.test', 'email_verified' => true]
            : ['id' => 'facebook-1', 'name' => 'Người dùng Facebook', 'email' => 'facebook@example.test'], $raw);
        $handler = new MockHandler([new Response(200, [], json_encode(['access_token' => 'boundary-token', 'token_type' => 'Bearer'])), new Response(200, [], json_encode($raw))]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));
        $client = new Client(['handler' => $stack]);
        Socialite::extend($provider, function ($app) use ($provider, $client) {
            $class = $provider === 'google' ? GoogleProvider::class : FacebookProvider::class;

            return (new $class($app['request'], 'test-client', 'test-secret', config("services.$provider.redirect")))->setHttpClient($client);
        });
    }

    private function oauthCallback(string $provider = 'google', array $query = [])
    {
        $this->get("/auth/$provider/redirect")->assertRedirect();
        $state = session('state');
        $this->assertNotEmpty($state);

        return $this->get("/auth/$provider/callback?".http_build_query(array_replace(['state' => $state, 'code' => 'boundary-code'], $query)));
    }

    public function test_google_creates_only_an_ordinary_user_and_regenerates_session(): void
    {
        $this->provider();
        $this->withSession(['url.intended' => 'https://evil.example/redirect']);
        $old = session()->getId();
        $this->oauthCallback()->assertRedirect('/ui/personal-dashboard?context=personal')->assertSessionHas('auth_method', 'social')->assertSessionMissing('url.intended');
        $user = Auth::user();
        $this->assertNotSame($old, session()->getId());
        $this->assertSame('social@example.test', $user->email);
        $this->assertFalse($user->password_login_enabled);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('ordinary', AccessGroup::find($user->access_group_id)->key);
        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('social_accounts', 1);
        $this->assertSame([], app(AccessService::class)->platformAuthorityCodes($user));
        $this->assertFalse(Schema::hasColumn('social_accounts', 'access_token'));
        $this->assertFalse(Schema::hasColumn('social_accounts', 'refresh_token'));
        $this->assertCount(2, $this->history);
        parse_str((string) $this->history[0]['request']->getBody(), $token);
        $this->assertNotEmpty($token['code_verifier']);
    }

    public function test_redirect_requests_only_identity_scopes_and_google_pkce(): void
    {
        $this->provider();
        $response = $this->get('/auth/google/redirect');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(['openid', 'email', 'profile'], explode(' ', $query['scope']));
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_linked_identity_is_used_even_when_provider_email_changes(): void
    {
        $user = User::factory()->create(['email' => 'original@example.test']);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-1']);
        $this->provider('google', ['email' => 'changed@example.test']);
        $this->oauthCallback()->assertRedirect('/ui/personal-dashboard?context=personal');
        $this->assertAuthenticatedAs($user);
        $this->assertSame('original@example.test', $user->fresh()->email);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_duplicate_email_requires_password_before_linking(): void
    {
        $user = User::factory()->create(['email' => 'social@example.test', 'password' => 'SecurePass2026']);
        $this->provider();
        $this->oauthCallback()->assertRedirect('/auth/complete');
        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
        $this->get('/auth/complete')->assertOk()->assertSee('Xác nhận mật khẩu');
        $this->post('/auth/authenticate', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->assertGuest();
        $this->post('/auth/authenticate', ['email' => $user->email, 'password' => 'SecurePass2026'])->assertRedirect('/ui/personal-dashboard?context=personal');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'provider_user_id' => 'google-1']);
    }

    public function test_facebook_email_is_verified_by_trohub_before_creation(): void
    {
        $this->provider('facebook', ['verified' => true]);
        $this->oauthCallback('facebook')->assertRedirect('/auth/complete');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->post('/auth/email', ['email' => 'facebook@example.test'])->assertRedirect('/auth/complete');
        $code = null;
        Notification::assertSentOnDemand(SocialEmailCode::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });
        $this->assertNotSame($code, session('social_pending.code_hash'));
        $this->post('/auth/verify', ['code' => $code])->assertRedirect('/ui/personal-dashboard?context=personal');
        $this->assertAuthenticated();
        $this->assertNotNull(Auth::user()->email_verified_at);
        $this->assertDatabaseHas('social_accounts', ['provider' => 'facebook', 'provider_user_id' => 'facebook-1']);
        parse_str($this->history[1]['request']->getUri()->getQuery(), $fields);
        $this->assertSame('id,name,email', $fields['fields']);
        $this->assertArrayHasKey('appsecret_proof', $fields);
    }

    public function test_facebook_without_email_can_supply_and_verify_email(): void
    {
        $this->provider('facebook', ['email' => null]);
        $this->oauthCallback('facebook')->assertRedirect('/auth/complete');
        $this->get('/auth/complete')->assertOk()->assertSee('Nhập email bạn đang sử dụng');
        $this->post('/auth/email', ['email' => 'supplied@example.test'])->assertRedirect('/auth/complete');
        $code = null;
        Notification::assertSentOnDemand(SocialEmailCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $this->post('/auth/verify', ['code' => $code])->assertRedirect();
        $this->assertSame('supplied@example.test', Auth::user()->email);
    }

    public function test_google_unverified_email_does_not_create_account_automatically(): void
    {
        $this->provider('google', ['email_verified' => false]);
        $this->oauthCallback()->assertRedirect('/auth/complete');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_email_code_attempt_limit_and_expiry_are_enforced(): void
    {
        $this->provider('facebook');
        $this->oauthCallback('facebook');
        $this->post('/auth/email', ['email' => 'facebook@example.test']);
        $this->post('/auth/verify', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->travel(config('social_login.email_code_minutes') + 1)->minutes();
        $code = null;
        Notification::assertSentOnDemand(SocialEmailCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $this->post('/auth/verify', ['code' => $code])->assertSessionHasErrors('code');
        $pending = session('social_pending');
        $pending['attempts'] = config('social_login.email_code_attempts');
        $this->withSession(['social_pending' => $pending])->post('/auth/verify', ['code' => $code])->assertStatus(419);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_verified_email_that_becomes_registered_requires_existing_account_authentication(): void
    {
        $this->provider('facebook');
        $this->oauthCallback('facebook');
        $this->post('/auth/email', ['email' => 'facebook@example.test']);
        $code = null;
        Notification::assertSentOnDemand(SocialEmailCode::class, function ($n) use (&$code) {
            $code = $n->code;

            return true;
        });
        User::factory()->create(['email' => 'facebook@example.test']);
        $this->post('/auth/verify', ['code' => $code])->assertRedirect('/auth/complete');
        $this->assertSame('link', session('social_pending.phase'));
        $this->assertGuest();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_disabled_or_unconfigured_provider_never_contacts_provider(): void
    {
        $this->get('/auth/google/redirect')->assertSessionHasErrors('social');
        config(['social_login.providers.google.enabled' => true, 'services.google.client_id' => '', 'services.google.client_secret' => '']);
        $this->get('/auth/google/redirect')->assertSessionHasErrors('social');
        $this->provider();
        foreach (['https://user@localhost/auth/google/callback', 'https://localhost/auth/google/callback?next=external', 'https://localhost/auth/google/callback#fragment'] as $uri) {
            config(['services.google.redirect' => $uri]);
            $this->assertFalse(app(SocialLogin::class)->configured('google'));
            $this->get('/auth/google/redirect')->assertSessionHasErrors('social');
        }
        $this->assertCount(0, $this->history);
        $this->get('/auth/unknown/redirect')->assertNotFound();
        $this->assertGuest();
    }

    public function test_missing_migration_fails_closed_without_disabling_password_login(): void
    {
        $this->provider();
        Schema::drop('social_accounts');
        $this->get('/auth/google/redirect')->assertSessionHasErrors('social');
        $user = User::factory()->create(['email' => 'password@example.test', 'password' => 'SecurePass2026']);
        $this->post('/dang-nhap', ['email' => $user->email, 'password' => 'SecurePass2026'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_state_mismatch_and_missing_state_fail_before_token_exchange(): void
    {
        $this->provider();
        $this->get('/auth/google/redirect');
        $this->get('/auth/google/callback?state=wrong&code=test')->assertSessionHasErrors('social');
        $this->get('/auth/google/callback?code=test')->assertSessionHasErrors('social');
        $this->assertCount(0, $this->history);
        $this->assertGuest();
    }

    public function test_callback_provider_mixup_and_replay_are_rejected(): void
    {
        $this->provider();
        $this->provider('facebook');
        $this->get('/auth/google/redirect');
        $state = session('state');
        $this->get('/auth/facebook/callback?state='.$state.'&code=test')->assertSessionHasErrors('social');
        $this->get('/auth/google/callback?state='.$state.'&code=test')->assertSessionHasErrors('social');
        $this->assertCount(0, $this->history);
    }

    public function test_cancel_and_missing_code_return_safely_without_provider_requests(): void
    {
        $this->provider();
        $this->oauthCallback(query: ['error' => 'access_denied', 'error_description' => '<script>bad</script>'])->assertSessionHasErrors('social');
        $this->assertCount(0, $this->history);
        $this->get('/auth/google/redirect');
        $this->get('/auth/google/callback?state='.session('state'))->assertSessionHasErrors('social');
        $this->assertCount(0, $this->history);
    }

    public function test_provider_failure_is_handled_without_echoing_token_or_error_details(): void
    {
        $this->provider('google', ['sub' => '']);
        $this->oauthCallback()->assertSessionHasErrors('social');
        $this->get('/ui/login?context=public')->assertOk()->assertDontSee('boundary-token');
        $this->assertGuest();
    }

    public function test_locked_and_platform_accounts_cannot_use_social_login(): void
    {
        $user = User::factory()->create(['status' => 'locked']);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'google-1']);
        $this->provider();
        $this->oauthCallback()->assertSessionHasErrors('social');
        $this->assertGuest();
        $user->forceFill(['status' => 'active', 'access_group_id' => AccessGroup::where('key', 'platform')->value('id')])->save();
        $this->provider();
        $this->oauthCallback()->assertSessionHasErrors('social');
        $this->assertGuest();
    }

    public function test_identity_cannot_be_attached_to_a_different_user(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $identity = ['provider' => 'google', 'provider_user_id' => 'immutable-1', 'name' => 'Test', 'email' => null];
        app(SocialLogin::class)->attach($identity, $a);
        $this->expectException(ValidationException::class);
        app(SocialLogin::class)->attach($identity, $b);
    }

    public function test_repeated_identity_is_idempotent_and_unique_constraint_is_present(): void
    {
        $identity = ['provider' => 'google', 'provider_user_id' => 'immutable-1', 'name' => 'Test', 'email' => 'new@example.test'];
        $first = app(SocialLogin::class)->attach($identity, verifiedEmail: 'new@example.test');
        $second = app(SocialLogin::class)->attach($identity, verifiedEmail: 'new@example.test');
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('social_accounts', 1);
        $this->assertDatabaseCount('users', 1);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('social_accounts')->insert(['user_id' => $first->id, 'provider' => 'google', 'provider_user_id' => 'immutable-1']);
    }

    public function test_one_account_can_link_both_providers_with_fresh_password_proof(): void
    {
        $user = User::factory()->create(['password' => 'SecurePass2026']);
        $this->actingAs($user)->withSession(['auth_version' => (int) $user->auth_version, 'auth_method' => 'password']);
        $this->provider();
        $this->post('/auth/google/link', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->post('/auth/google/link', ['current_password' => 'SecurePass2026'])->assertRedirect();
        $this->get('/auth/google/callback?state='.session('state').'&code=test')->assertRedirect();
        $this->provider('facebook');
        $this->post('/auth/facebook/link', ['current_password' => 'SecurePass2026'])->assertRedirect();
        $this->get('/auth/facebook/callback?state='.session('state').'&code=test')->assertRedirect();
        $this->assertDatabaseCount('social_accounts', 2);
        $this->assertSame(1, SocialAccount::distinct()->count('user_id'));
    }

    public function test_password_change_invalidates_pending_link_proof(): void
    {
        $user = User::factory()->create(['password' => 'SecurePass2026']);
        $this->actingAs($user)->withSession(['auth_version' => (int) $user->auth_version]);
        $this->provider();
        $this->post('/auth/google/link', ['current_password' => 'SecurePass2026']);
        $state = session('state');
        $user->forceFill(['auth_version' => (int) $user->auth_version + 1])->save();
        $this->get('/auth/google/callback?state='.$state.'&code=test')->assertRedirect('/dang-nhap');
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_unlink_requires_password_and_prevents_oauth_only_lockout(): void
    {
        $user = User::factory()->create(['password' => 'SecurePass2026']);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => '1']);
        $this->actingAs($user)->withSession(['auth_version' => (int) $user->auth_version]);
        $this->post('/auth/google/unlink', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $user->forceFill(['password_login_enabled' => false])->save();
        $this->post('/auth/google/unlink', ['current_password' => 'SecurePass2026'])->assertSessionHasErrors('current_password');
        $this->assertDatabaseCount('social_accounts', 1);
        $user->forceFill(['password_login_enabled' => true])->save();
        $this->post('/auth/google/unlink', ['current_password' => 'SecurePass2026'])->assertRedirect();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_password_reset_enables_password_for_oauth_only_account(): void
    {
        $user = User::factory()->create(['password_login_enabled' => false]);
        $token = Password::createToken($user);
        $this->post('/dat-lai-mat-khau', ['email' => $user->email, 'token' => $token, 'password' => 'SecurePass2026', 'password_confirmation' => 'SecurePass2026'])->assertRedirect('/dang-nhap');
        $this->assertTrue($user->fresh()->password_login_enabled);
        $this->post('/dang-nhap', ['email' => $user->email, 'password' => 'SecurePass2026'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('password', session('auth_method'));
    }

    public function test_social_session_cannot_gain_admin_access_after_a_later_promotion(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth_method' => 'social', 'auth_version' => (int) $user->auth_version]);
        $user->forceFill(['access_group_id' => AccessGroup::where('key', 'platform')->value('id')])->save();
        $this->get('/ui/admin-dashboard?context=platform-admin')->assertRedirect('/dang-nhap');
        $this->assertGuest();
    }

    public function test_password_login_and_security_page_continue_to_work(): void
    {
        $user = User::factory()->create(['email' => 'regular@example.test', 'password' => 'SecurePass2026']);
        $this->post('/dang-nhap', ['email' => $user->email, 'password' => 'SecurePass2026'])->assertRedirect();
        $this->get('/ui/profile-security?context=personal&section=security')->assertOk()->assertSee('Tài khoản liên kết')->assertSee('Đổi mật khẩu');
        $this->post('/dang-xuat')->assertRedirect('/');
        $this->assertGuest();
        $this->get('/ui/login?context=public')->assertOk()->assertDontSee('Tiếp tục với Google')->assertDontSee('Tiếp tục với Facebook');
        config(['email_auth.show_social_buttons' => true]);
        $this->provider();
        $this->get('/ui/register?context=public')->assertOk()->assertSee('/auth/google/redirect', false);
    }
}
