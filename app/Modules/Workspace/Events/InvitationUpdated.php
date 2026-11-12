<?php

namespace App\Modules\Workspace\Events;

final class InvitationUpdated
{
    public function __construct(public int $invitationId, public bool $attention = true) {}
}
