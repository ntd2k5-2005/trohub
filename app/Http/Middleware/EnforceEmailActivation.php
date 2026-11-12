<?php

namespace App\Http\Middleware;

use App\Modules\Identity\EmailActivationController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class EnforceEmailActivation
{
    public function handle(Request $request, Closure $next)
    {
        if ($user = $request->user()) {
            $user->refresh();
            if ($user->needsEmailActivation()) {
                EmailActivationController::remember($request, $user);

                return redirect()->route('email.activation.notice');
            }
            if ((int) $request->session()->get('auth_version', 0) !== (int) $user->auth_version) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', 'Phiên đăng nhập đã kết thúc sau thay đổi bảo mật.');
            }
            abort_unless($user->status === 'active', 403);
        }

        return $next($request);
    }
}
