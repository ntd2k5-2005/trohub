<?php

namespace App\Modules\Identity;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Identity\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

final class SocialAuthController extends Controller
{
    public function __construct(private SocialLogin $social) {}

    public function redirect(Request $request, string $provider)
    {
        if ($request->user()) {
            return $this->profile();
        }

        return $this->begin($request, $provider);
    }

    public function link(Request $request, string $provider)
    {
        $this->confirmPassword($request);
        $this->social->assertUser($request->user());

        return $this->begin($request, $provider, $request->user());
    }

    private function begin(Request $request, string $provider, ?User $target = null)
    {
        if (! $this->social->configured($provider) || ! $this->social->installed()) {
            return $this->failed('Đăng nhập bằng nhà cung cấp này chưa sẵn sàng. Bạn vẫn có thể dùng email và mật khẩu.');
        }
        $request->session()->forget(['social_pending', 'social_flow', 'state', 'code_verifier']);
        $request->session()->put('social_flow', ['provider' => $provider, 'expires' => now()->addMinutes(config('social_login.pending_minutes'))->timestamp,
            'target_id' => $target?->id, 'auth_version' => $target?->auth_version]);
        try {
            return $this->driver($provider)->redirect();
        } catch (\Throwable) {
            $request->session()->forget(['social_flow', 'state', 'code_verifier']);

            return $this->failed('Chưa thể kết nối nhà cung cấp. Vui lòng thử lại sau hoặc dùng mật khẩu.');
        }
    }

    private function driver(string $provider)
    {
        Socialite::forgetDrivers();
        $driver = Socialite::driver($provider)->setScopes(config("social_login.providers.$provider.scopes"));
        if ($provider === 'facebook') {
            $driver->fields(['id', 'name', 'email'])->usingGraphVersion(config('services.facebook.version'));
        }
        if (config("social_login.providers.$provider.pkce")) {
            $driver->enablePKCE();
        }

        return $driver;
    }

    public function callback(Request $request, string $provider)
    {
        $flow = $request->session()->pull('social_flow');
        $state = $request->session()->get('state');
        $valid = is_array($flow) && $flow['provider'] === $provider && $flow['expires'] > now()->timestamp
            && is_string($state) && $state !== '' && is_string($request->query('state')) && hash_equals($state, $request->query('state'));
        if (! $valid || ! $this->social->configured($provider) || ! $this->social->installed()) {
            $request->session()->forget(['state', 'code_verifier']);

            return $this->failed('Phiên xác thực không hợp lệ hoặc đã hết hạn. Vui lòng bắt đầu đăng nhập lại.');
        }
        if ($request->has('error')) {
            $request->session()->forget(['state', 'code_verifier']);

            return $this->failed('Bạn đã hủy đăng nhập. Bạn có thể thử lại hoặc dùng email và mật khẩu.');
        }
        if (! is_string($request->query('code')) || $request->query('code') === '') {
            $request->session()->forget(['state', 'code_verifier']);

            return $this->failed('Không nhận được mã xác thực hợp lệ. Vui lòng thử lại.');
        }
        try {
            // Socialite validates and consumes state again. Never use stateless() here.
            $identity = $this->social->identity($provider, $this->driver($provider)->user());
            if ($flow['target_id']) {
                $user = $request->user();
                if (! $user || $user->id !== $flow['target_id'] || (int) $user->fresh()->auth_version !== (int) $flow['auth_version']
                    || (int) $request->session()->get('auth_version') !== (int) $flow['auth_version']) {
                    return $this->failed('Phiên xác thực tài khoản đã thay đổi. Vui lòng đăng nhập và liên kết lại.');
                }
                $this->social->attach($identity, $user);

                return $this->profile()->with('status', 'Đã liên kết tài khoản thành công.');
            }
            if ($request->user()) {
                return $this->failed('Phiên đăng nhập đã thay đổi. Vui lòng bắt đầu lại.');
            }
            if ($user = $this->social->linked($identity)) {
                $this->social->assertUser($user);

                return $this->signedIn($request, $user);
            }
            if ($identity['email_verified'] && ! $this->social->emailOwner($identity['email'])) {
                return $this->signedIn($request, $this->social->attach($identity, verifiedEmail: $identity['email']));
            }
            $request->session()->put('social_pending', ['identity' => $identity,
                'phase' => $identity['email'] && $this->social->emailOwner($identity['email']) ? 'link' : 'email',
                'email' => $identity['email'], 'expires' => now()->addMinutes(config('social_login.pending_minutes'))->timestamp, 'attempts' => 0]);

            return redirect()->route('social.complete');
        } catch (ValidationException $e) {
            return $this->failed(collect($e->errors())->flatten()->first());
        } catch (\Throwable) {
            // Provider/Guzzle errors can embed tokens or authorization URLs. Do not log them.
            return $this->failed('Chưa thể hoàn tất xác thực với nhà cung cấp. Vui lòng thử lại hoặc dùng mật khẩu.');
        } finally {
            $request->session()->forget(['state', 'code_verifier']);
        }
    }

    public function complete(Request $request)
    {
        $pending = $this->pending($request);

        return response()->view('product.social-complete', ['pending' => $pending,
            'context' => 'public', 'navigation' => collect(), 'shell' => ['items' => collect()],
            'page' => ['title' => 'Hoàn tất đăng nhập', '_asset_key' => 'identity'], 'screen' => ['auth' => 'social']]);
    }

    public function email(Request $request)
    {
        $pending = $this->pending($request);
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $email = $request->validate(['email' => 'required|email|max:254'])['email'];
        if ($this->social->emailOwner($email)) {
            unset($pending['code_hash'], $pending['code_expires']);
            $request->session()->put('social_pending', array_replace($pending, ['phase' => 'link', 'email' => $email]));

            return redirect()->route('social.complete');
        }
        if (($pending['sent_at'] ?? 0) + config('social_login.email_resend_seconds') > now()->timestamp) {
            throw ValidationException::withMessages(['email' => 'Vui lòng đợi một phút trước khi gửi lại mã.']);
        }
        $code = (string) random_int(100000, 999999);
        try {
            Notification::route('mail', $email)->notify(new SocialEmailCode($code));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['email' => 'Chưa thể gửi mã xác minh. Vui lòng thử lại sau hoặc dùng tài khoản hiện có.']);
        }
        $request->session()->put('social_pending', array_replace($pending, ['phase' => 'verify', 'email' => $email,
            'code_hash' => Hash::make($code), 'code_expires' => now()->addMinutes(config('social_login.email_code_minutes'))->timestamp, 'sent_at' => now()->timestamp]));

        return redirect()->route('social.complete')->with('status', 'Đã gửi mã xác minh đến email của bạn.');
    }

    public function verify(Request $request)
    {
        $pending = $this->pending($request);
        $code = $request->validate(['code' => 'required|digits:6'])['code'];
        $pending['attempts']++;
        $request->session()->put('social_pending', $pending);
        if (($pending['code_expires'] ?? 0) <= now()->timestamp || empty($pending['code_hash']) || ! Hash::check($code, $pending['code_hash'])) {
            throw ValidationException::withMessages(['code' => 'Mã xác minh không đúng hoặc đã hết hạn.']);
        }
        if ($this->social->emailOwner($pending['email'])) {
            unset($pending['code_hash'], $pending['code_expires']);
            $request->session()->put('social_pending', array_replace($pending, ['phase' => 'link']));

            return redirect()->route('social.complete');
        }
        $user = $this->social->attach($pending['identity'], verifiedEmail: $pending['email']);

        return $this->signedIn($request, $user);
    }

    public function authenticateExisting(Request $request)
    {
        $pending = $this->pending($request);
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string']);
        $email = mb_strtolower(trim($data['email']));
        $user = $this->social->emailOwner($email);
        if (($pending['email'] && $email !== $pending['email']) || ! $user || ! $user->password_login_enabled || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'Thông tin xác thực không đúng. Nếu chưa có mật khẩu, hãy dùng chức năng khôi phục mật khẩu trước.']);
        }
        $user = $this->social->attach($pending['identity'], $user);

        return $this->signedIn($request, $user);
    }

    public function cancel(Request $request)
    {
        $request->session()->forget(['social_pending', 'social_flow', 'state', 'code_verifier']);

        return redirect()->route('login')->with('status', 'Đã hủy xác thực. Bạn có thể đăng nhập bằng email và mật khẩu.');
    }

    private function pending(Request $request): array
    {
        $pending = $request->session()->get('social_pending');
        if ($request->user() || ! is_array($pending) || $pending['expires'] <= now()->timestamp
            || $pending['attempts'] >= config('social_login.email_code_attempts')
            || ! $this->social->configured($pending['identity']['provider'])) {
            $request->session()->forget('social_pending');
            abort(419, 'Phiên xác thực đã hết hạn. Vui lòng đăng nhập lại.');
        }

        return $pending;
    }

    public function unlink(Request $request, string $provider)
    {
        $this->confirmPassword($request);
        DB::transaction(function () use ($request, $provider) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $this->social->assertUser($user);
            if (! $user->password_login_enabled || (int) $request->session()->get('auth_version') !== (int) $user->auth_version || ! Hash::check($request->input('current_password'), $user->password)) {
                abort(403);
            }
            $account = SocialAccount::where('user_id', $user->id)->where('provider', $provider)->lockForUpdate()->first();
            if ($account) {
                app(Journal::class)->record($user->id, 'social.unlinked', $account, changes: ['provider' => $provider]);
                $account->delete();
            }
        });

        return $this->profile()->with('status', 'Đã hủy liên kết. Bạn vẫn có thể đăng nhập bằng mật khẩu.');
    }

    private function confirmPassword(Request $request): void
    {
        $data = $request->validate(['current_password' => 'required|string']);
        $user = $request->user()->fresh();
        if (! $this->social->installed() || ! $user->password_login_enabled || ! Hash::check($data['current_password'], $user->password)
            || (int) $request->session()->get('auth_version', 0) !== (int) $user->auth_version) {
            throw ValidationException::withMessages(['current_password' => 'Vui lòng xác nhận mật khẩu hiện tại. Nếu chưa có mật khẩu, hãy thiết lập qua khôi phục mật khẩu trước.']);
        }
    }

    private function signedIn(Request $request, User $user)
    {
        $user = $user->fresh();
        $this->social->assertUser($user);
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();
        $request->session()->put(['auth_version' => (int) $user->auth_version, 'auth_method' => 'social']);
        $request->session()->forget(['social_pending', 'social_flow', 'url.intended']);

        // Never trust return URLs, even an old intended URL aimed at an admin context.
        return redirect()->route('ui.show', ['slug' => 'personal-dashboard', 'context' => 'personal']);
    }

    private function profile()
    {
        return redirect()->route('ui.show', ['slug' => 'profile-security', 'context' => 'personal', 'section' => 'security']);
    }

    private function failed(string $message)
    {
        return redirect()->route('ui.show', ['slug' => 'login', 'context' => 'public'])->withErrors(['social' => $message]);
    }
}
