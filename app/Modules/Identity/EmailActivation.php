<?php

namespace App\Modules\Identity;

use App\Models\User;
use App\Modules\DataPlatform\Journal;
use App\Modules\Identity\Models\AuthMailDelivery;
use App\Modules\Identity\Models\EmailChallenge;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class EmailActivation
{
    public function issue(User $user): EmailChallenge
    {
        return DB::transaction(function () use ($user) {
            $fresh = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! $fresh->needsEmailActivation() || $fresh->status !== 'active') {
                throw ValidationException::withMessages(['email' => 'Không thể gửi yêu cầu xác minh cho tài khoản này.']);
            }
            $last = EmailChallenge::where('user_id', $fresh->id)->latest('created_at')->first();
            if ($last && $last->created_at->greaterThan(now()->subSeconds(config('email_auth.resend_seconds')))) {
                throw ValidationException::withMessages(['email' => 'Vui lòng đợi một phút trước khi gửi lại email.']);
            }
            EmailChallenge::where('user_id', $fresh->id)->whereNotNull('active_user_id')->update(['active_user_id' => null, 'revoked_at' => now()]);
            AuthMailDelivery::where('user_id', $fresh->id)->where('purpose', 'activation')->whereIn('status', ['pending', 'failed', 'sending'])->update(['status' => 'cancelled', 'payload' => null]);
            $token = Str::random(64);
            $otp = (string) random_int(100000, 999999);
            $expires = now()->addMinutes(config('email_auth.verification_minutes'));
            $challenge = EmailChallenge::create(['id' => (string) Str::uuid(), 'user_id' => $fresh->id, 'active_user_id' => $fresh->id, 'email' => $fresh->email, 'token_hash' => hash('sha256', $token), 'otp_hash' => Hash::make($otp), 'expires_at' => $expires]);
            $mail = app(AuthMail::class)->create($fresh, 'activation', ['url' => route('email.activation.link', ['challenge' => $challenge->id, 'token' => $token]), 'otp' => $otp], $expires, $challenge->id);
            app(AuthMail::class)->launch($mail);

            return $challenge;
        });
    }

    public function consume(string $id, string $secret, string $method): void
    {
        // Return errors after committing the attempt counter, not by rolling its transaction back.
        $error = DB::transaction(function () use ($id, $secret, $method) {
            $candidate = EmailChallenge::find($id);
            if (! $candidate) {
                return 'Yêu cầu xác minh không hợp lệ.';
            }
            $user = User::whereKey($candidate->user_id)->lockForUpdate()->first();
            $challenge = EmailChallenge::whereKey($id)->lockForUpdate()->first();
            if (! $user || ! $challenge->active_user_id || $challenge->consumed_at || $challenge->revoked_at || $challenge->expires_at->lessThanOrEqualTo(now())
                || $challenge->attempts >= config('email_auth.verification_attempts') || $challenge->email !== $user->email || $user->status !== 'active' || ! $user->needsEmailActivation()) {
                return 'Liên kết hoặc mã đã hết hiệu lực. Vui lòng yêu cầu email mới.';
            }
            $valid = $method === 'link' ? hash_equals($challenge->token_hash, hash('sha256', $secret)) : Hash::check($secret, $challenge->otp_hash);
            if (! $valid) {
                $challenge->increment('attempts');

                return 'Mã hoặc liên kết xác minh không đúng.';
            }
            $challenge->update(['consumed_at' => now(), 'active_user_id' => null]);
            $user->forceFill(['email_verified_at' => now(), 'email_activation_required' => false, 'auth_version' => (int) $user->auth_version + 1])->save();
            AuthMailDelivery::where('challenge_id', $id)->whereIn('status', ['pending', 'failed', 'sending'])->update(['status' => 'cancelled', 'payload' => null]);
            app(Journal::class)->record($user->id, 'email.verified', $user, changes: ['method' => $method]);
            event(new Verified($user));

            return null;
        });
        if ($error) {
            throw ValidationException::withMessages(['verification' => $error]);
        }
    }
}
