<?php

namespace App\Modules\Identity;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\DataPlatform\Journal;
use App\Modules\Identity\Models\AuthMailDelivery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $candidate = User::where('email', $data['email'])->first();
        if ($candidate && $candidate->status === 'active' && $candidate->needsEmailActivation() && $candidate->password_login_enabled !== false && Hash::check($data['password'], $candidate->password)) {
            EmailActivationController::remember($request, $candidate);

            return redirect()->route('email.activation.notice');
        }
        $credentials = $data + ['status' => 'active'];
        if (Schema::hasColumn('users', 'password_login_enabled')) {
            $credentials['password_login_enabled'] = true;
        }
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Email hoặc mật khẩu không đúng, hoặc tài khoản đang bị khóa.']);
        }
        $request->session()->regenerate();
        $request->session()->put('auth_version', (int) $request->user()->auth_version);
        $request->session()->put('auth_method', 'password');

        return redirect()->intended(route('ui.show', ['slug' => 'personal-dashboard', 'context' => 'personal']));
    }

    public function register(Request $request)
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|max:254|unique:users', 'password' => 'required|string|min:10|confirmed']);
        if (! Schema::hasTable('email_verification_challenges')) {
            throw ValidationException::withMessages(['email' => 'Đăng ký tạm thời chưa sẵn sàng. Vui lòng thử lại sau.']);
        }
        $user = DB::transaction(function () use ($data) {
            $user = User::create($data);
            $user->forceFill(['access_group_id' => AccessGroup::where('key', config('authorization.registration_group'))->value('id'), 'email_activation_required' => true])->save();
            app(EmailActivation::class)->issue($user);

            return $user;
        });
        EmailActivationController::remember($request, $user);

        return redirect()->route('email.activation.notice');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    public function profile(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100']);
        app(ProfileService::class)->update($request->user(), $data['name']);

        return back()->with('status', 'Đã cập nhật hồ sơ.');
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|string|current_password', 'password' => 'required|string|min:10|confirmed|different:current_password']);
        app(ProfileService::class)->password($request->user(), $data['password']);
        $request->session()->regenerate();
        $request->session()->put('auth_version', (int) $request->user()->auth_version);

        return back()->with('status', 'Đã đổi mật khẩu. Lần đăng nhập tiếp theo, hãy dùng mật khẩu mới.');
    }

    public function forgot(Request $request)
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $request->validate(['email' => 'required|email']);
        try {
            DB::transaction(function () use ($request): void {
                User::where('email', $request->input('email'))->lockForUpdate()->first();
                Password::sendResetLink($request->only('email'));
            });
        } catch (\Throwable) {
            // Never leak SMTP errors, secrets or whether an email exists.
        }

        return back()->with('status', 'Nếu email tồn tại, yêu cầu gửi hướng dẫn khôi phục đã được ghi nhận.');
    }

    public function reset(Request $request)
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => 'required|min:10|confirmed']);
        $status = DB::transaction(function () use ($data) {
            User::where('email', $data['email'])->lockForUpdate()->first();
            DB::table(config('auth.passwords.users.table'))->where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset($data, function (User $user, string $password) {
                DB::transaction(function () use ($user, $password) {
                    $fresh = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $fresh->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60), 'auth_version' => (int) $fresh->auth_version + 1])->save();
                    if (array_key_exists('password_login_enabled', $fresh->getAttributes())) {
                        $fresh->forceFill(['password_login_enabled' => true])->save();
                    }
                    app(Journal::class)->record($fresh->id, 'password.recovered', $fresh);
                    if (Schema::hasTable('auth_mail_deliveries')) {
                        AuthMailDelivery::where('user_id', $fresh->id)->where('purpose', 'recovery')->whereIn('status', ['pending', 'failed', 'sending'])->update(['status' => 'cancelled', 'payload' => null]);
                    }
                });
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'Liên kết không hợp lệ hoặc đã hết hạn.']);
        }

        return redirect()->route('login')->with('status', 'Đã đổi mật khẩu. Bạn có thể đăng nhập.');
    }
}
