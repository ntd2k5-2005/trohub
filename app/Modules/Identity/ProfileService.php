<?php

namespace App\Modules\Identity;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\DataPlatform\Journal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProfileService
{
    public function __construct(private Journal $journal) {}

    public function update(User $user, string $name): void
    {
        abort_unless(app(Authorization::class)->personal($user, 'profile.update'), 403);
        DB::transaction(function () use ($user, $name) {
            $before = $user->only('name');
            $user->update(['name' => $name]);
            $this->journal->record($user->id, 'profile.updated', $user, null, ['before' => $before, 'after' => $user->only('name')]);
        });
    }

    public function password(User $user, string $password): void
    {
        abort_unless(app(Authorization::class)->personal($user, 'profile.password'), 403);
        DB::transaction(function () use ($user, $password) {
            $fresh=User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $fresh->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'auth_version'=>(int)$fresh->auth_version+1])->save();
            $user->refresh();
            $this->journal->record($user->id, 'password.changed', $user);
        });
    }
}
