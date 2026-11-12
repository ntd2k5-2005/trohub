<?php

namespace App\Modules\Identity;

use App\Models\User;
use App\Modules\Identity\Models\AuthMailDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuthMail
{
    public function create(User $user, string $purpose, array $payload, $expires, ?string $challenge = null): AuthMailDelivery
    {
        return AuthMailDelivery::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'challenge_id' => $challenge,
            'purpose' => $purpose, 'payload' => $payload + ['email' => $user->email, 'name' => $user->name, 'purpose' => $purpose, 'expires_label' => $expires->format('d/m/Y H:i')], 'expires_at' => $expires]);
    }

    public function launch(AuthMailDelivery $mail): void
    {
        DB::afterCommit(function () use ($mail): void {
            try {
                if (config('email_auth.queue')) {
                    SendAuthMail::dispatch($mail->id)->onQueue(config('email_auth.queue_name'));
                } else {
                    SendAuthMail::dispatchSync($mail->id);
                }
            } catch (\Throwable) {
                AuthMailDelivery::whereKey($mail->id)->whereIn('status', ['pending', 'sending', 'failed'])->update(['status' => 'failed']);
            }
        });
    }

    public function passwordReset(User $user, string $token): void
    {
        $expires = now()->addMinutes(config('auth.passwords.users.expire'));
        AuthMailDelivery::where('user_id', $user->id)->where('purpose', 'recovery')->whereIn('status', ['pending', 'failed', 'sending'])->update(['status' => 'cancelled', 'payload' => null]);
        $mail = $this->create($user, 'recovery', ['url' => route('password.reset', ['token' => $token, 'email' => $user->email])], $expires);
        $this->launch($mail);
    }
}
