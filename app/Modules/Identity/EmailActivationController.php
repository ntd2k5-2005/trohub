<?php

namespace App\Modules\Identity;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Identity\Models\AuthMailDelivery;
use App\Modules\Identity\Models\EmailChallenge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class EmailActivationController extends Controller
{
    public static function remember(Request $request, User $user): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('activation', ['user_id' => $user->id, 'expires' => now()->addMinutes(config('email_auth.pending_session_minutes'))->timestamp]);
    }

    private function pending(Request $request): User
    {
        $pending = $request->session()->get('activation');
        abort_unless(is_array($pending) && $pending['expires'] > now()->timestamp, 419, 'Vui lòng đăng nhập để tiếp tục xác minh email.');
        $user = User::findOrFail($pending['user_id']);
        abort_unless($user->needsEmailActivation() && $user->status === 'active', 403);

        return $user;
    }

    public function notice(Request $request)
    {
        $user = $this->pending($request);
        $delivery = AuthMailDelivery::where('user_id', $user->id)->where('purpose', 'activation')->latest('created_at')->first();

        return $this->view(['email' => $user->email, 'deliveryStatus' => $delivery?->status]);
    }

    public function resend(Request $request, EmailActivation $activation)
    {
        $activation->issue($this->pending($request));

        return redirect()->route('email.activation.notice')->with('status', 'Yêu cầu gửi email xác minh đã được ghi nhận.');
    }

    public function otp(Request $request, EmailActivation $activation)
    {
        $user = $this->pending($request);
        $data = $request->validate(['otp' => 'required|digits:6']);
        $id = EmailChallenge::where('active_user_id', $user->id)->value('id');
        $activation->consume($id ?? '', $data['otp'], 'otp');

        return $this->done($request);
    }

    public function link(string $challenge, string $token)
    {
        // GET only presents confirmation; mail scanners must not activate an account.
        abort_unless(EmailChallenge::whereKey($challenge)->exists(), 404);

        return $this->view(['challenge' => $challenge, 'token' => $token]);
    }

    public function confirm(Request $request, EmailActivation $activation)
    {
        $data = $request->validate(['challenge' => 'required|uuid', 'token' => 'required|string|size:64']);
        $activation->consume($data['challenge'], $data['token'], 'link');

        return $this->done($request);
    }

    private function done(Request $request)
    {
        $request->session()->forget('activation');
        $request->session()->regenerate();

        return redirect()->route('login')->with('status', 'Email đã được xác minh. Bạn có thể đăng nhập bằng mật khẩu.');
    }

    private function view(array $data)
    {
        return response()->view('product.email-activation', $data + ['screen' => ['auth' => 'activation'], 'context' => 'public', 'navigation' => collect(), 'shell' => ['items' => collect()], 'page' => ['title' => 'Xác minh email', '_asset_key' => 'identity']]);
    }
}
