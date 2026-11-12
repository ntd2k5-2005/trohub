<?php

namespace App\Modules\AccessControl;

use App\Models\User;

final class AccountPolicy
{
    public function __construct(private AccessService $access) {}

    public function canChangeStatus(User $actor, User $target): bool
    {
        if ($actor->id === $target->id || ! $this->access->global($actor, 'account.lock')) {
            return false;
        }

        return ! array_diff($this->access->platformAuthorityCodes($target), $this->access->platformAuthorityCodes($actor));
    }
}
