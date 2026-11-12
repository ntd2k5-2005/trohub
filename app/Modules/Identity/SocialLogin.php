<?php

namespace App\Modules\Identity;

use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\DataPlatform\Journal;
use App\Modules\Identity\Models\SocialAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as ProviderUser;

final class SocialLogin
{
    public function configured(string $provider): bool
    {
        $settings = config('social_login.providers.'.$provider);
        if (! $settings || ! $settings['enabled']) {
            return false;
        }
        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            if (! is_string(config("services.$provider.$key")) || trim(config("services.$provider.$key")) === '') {
                return false;
            }
        }
        $uri = parse_url(config("services.$provider.redirect"));

        return isset($uri['host'], $uri['scheme']) && ! isset($uri['user']) && ! isset($uri['pass']) && ! isset($uri['fragment']) && ! isset($uri['query'])
            && ($uri['scheme'] === 'https' || app()->environment('local', 'testing') && $uri['scheme'] === 'http');
    }

    public function installed(): bool
    {
        return Schema::hasTable('social_accounts') && Schema::hasColumn('users', 'password_login_enabled');
    }

    public function identity(string $provider, ProviderUser $remote): array
    {
        $id = $remote->getId();
        if (! is_scalar($id) || trim((string) $id) === '' || strlen((string) $id) > 191) {
            throw ValidationException::withMessages(['social' => 'Không nhận được định danh hợp lệ từ nhà cung cấp.']);
        }
        $email = is_string($remote->getEmail()) ? mb_strtolower(trim($remote->getEmail())) : null;
        $email = $email && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        $raw = $remote->getRaw();

        return ['provider' => $provider, 'provider_user_id' => (string) $id, 'email' => $email,
            'name' => mb_substr(trim((string) $remote->getName()), 0, 100) ?: 'Người dùng '.config('product.name'),
            // Google userinfo is fetched server-side with the freshly exchanged token.
            // Facebook's "verified" field does not prove ownership of an email address.
            'email_verified' => $provider === 'google' && $email !== null && (($raw['email_verified'] ?? false) === true || ($raw['verified_email'] ?? false) === true)];
    }

    public function linked(array $identity): ?User
    {
        return SocialAccount::where('provider', $identity['provider'])->where('provider_user_id', $identity['provider_user_id'])->first()?->user;
    }

    public function assertUser(User $user): void
    {
        if ($user->status !== 'active' || app(AccessService::class)->platformAuthorityCodes($user) !== []) {
            throw ValidationException::withMessages(['social' => 'Tài khoản này không thể sử dụng đăng nhập Google hoặc Facebook. Vui lòng dùng phương thức đăng nhập hiện có.']);
        }
    }

    public function emailOwner(string $email): ?User
    {
        return User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->first();
    }

    public function attach(array $identity, ?User $target = null, ?string $verifiedEmail = null): User
    {
        try {
            return DB::transaction(function () use ($identity, $target, $verifiedEmail) {
                $existing = SocialAccount::where('provider', $identity['provider'])->where('provider_user_id', $identity['provider_user_id'])->lockForUpdate()->first();
                if ($existing) {
                    if ($target && $existing->user_id !== $target->id) {
                        throw ValidationException::withMessages(['social' => 'Tài khoản nhà cung cấp đã được liên kết với một tài khoản khác.']);
                    }
                    $user = User::whereKey($existing->user_id)->lockForUpdate()->firstOrFail();
                    if ($target) {
                        $this->assertSameProof($target, $user);
                    }
                    $this->assertUser($user);

                    return $user;
                }
                if ($target) {
                    $user = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
                    $this->assertSameProof($target, $user);
                    $this->assertUser($user);
                } else {
                    if (! $verifiedEmail || $this->emailOwner($verifiedEmail)) {
                        throw ValidationException::withMessages(['social' => 'Email đã có tài khoản. Vui lòng xác thực tài khoản hiện có trước khi liên kết.']);
                    }
                    $group = AccessGroup::where('key', config('authorization.registration_group'))->where('scope', 'personal')->where('active', true)->first();
                    if (! $group) {
                        throw ValidationException::withMessages(['social' => 'Chưa thể tạo tài khoản lúc này. Vui lòng thử lại sau.']);
                    }
                    $user = User::create(['name' => $identity['name'], 'email' => $verifiedEmail, 'password' => Str::random(64)]);
                    $user->forceFill(['access_group_id' => $group->id, 'password_login_enabled' => false, 'email_verified_at' => now()])->save();
                    $this->assertUser($user);
                }
                $account = SocialAccount::create(['user_id' => $user->id, 'provider' => $identity['provider'], 'provider_user_id' => $identity['provider_user_id'], 'email' => $identity['email'], 'name' => $identity['name']]);
                app(Journal::class)->record($user->id, 'social.linked', $account, changes: ['provider' => $identity['provider']]);

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Another callback may have inserted the immutable provider identity first.
            $user = $this->linked($identity);
            if ($user && (! $target || $user->id === $target->id)) {
                if ($target) {
                    $this->assertSameProof($target, $user);
                }
                $this->assertUser($user);

                return $user;
            }
            throw ValidationException::withMessages(['social' => 'Thông tin liên kết vừa thay đổi. Vui lòng đăng nhập lại để xác thực tài khoản hiện có.']);
        }
    }

    private function assertSameProof(User $proof, User $fresh): void
    {
        if (! $fresh->password_login_enabled || (int) $proof->auth_version !== (int) $fresh->auth_version || ! hash_equals($proof->password, $fresh->password)) {
            throw ValidationException::withMessages(['social' => 'Thông tin bảo mật vừa thay đổi. Vui lòng xác thực tài khoản lại.']);
        }
    }
}
