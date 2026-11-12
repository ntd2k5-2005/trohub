<?php

namespace App\Http\Middleware;

use App\Modules\AccessControl\AccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class EnforceSocialSession
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->session()->get('auth_method') === 'social' && ($user = $request->user())) {
            $user->refresh();
            if ($user->status !== 'active' || (int) $user->auth_version !== (int) $request->session()->get('auth_version')
                || app(AccessService::class)->platformAuthorityCodes($user) !== []) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', 'Vui lòng đăng nhập lại bằng phương thức xác thực hiện có của tài khoản.');
            }
        }

        return $next($request);
    }
}
