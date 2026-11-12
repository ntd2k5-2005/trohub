<?php

namespace App\Contracts;

use App\Models\User;
use App\Modules\Workspace\Models\Membership;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

interface Authorization
{
    public function global(User $user, string $permission): bool;

    public function personal(User $user, string $permission): bool;

    public function allowed(User $user, string $permission, Workspace $workspace, ?int $propertyId = null, ?int $roomId = null): bool;

    public function authorize(User $user, string $permission, Workspace $workspace, ?int $propertyId = null, ?int $roomId = null): void;

    public function grants(User $user, string $permission): Builder;

    public function member(User $user, Workspace $workspace): ?Membership;

    public function workspaces(User $user): Collection;

    public function propertyIds(User $user, Workspace $workspace, string $permission): array;

    /** Read-only presentation snapshot; mutations must call authorize() afresh. */
    public function permissionPropertyMap(User $user, Workspace $workspace, array $permissions): array;

    public function roomIds(User $user, Workspace $workspace, string $permission): array;

    public function effectiveCodes(User $user, ?Workspace $workspace = null): array;
}
