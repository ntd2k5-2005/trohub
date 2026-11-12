<?php

namespace App\Contracts;

use App\Models\User;

interface WorkAssignments
{
    public function claim(User $actor, int $workspace, string $type, int $id, string $permission): void;
    public function complete(int $workspace, string $type, int $id): void;
    public function assertCurrent(User $actor, int $workspace, string $type, int $id): void;
}
