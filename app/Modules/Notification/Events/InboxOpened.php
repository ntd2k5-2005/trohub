<?php

namespace App\Modules\Notification\Events;

use App\Models\User;

final class InboxOpened
{
    public function __construct(public User $user) {}
}
