<?php

namespace App\Modules\Chat\Contracts;

use App\Models\User;
use App\Modules\Chat\Models\Thread;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

interface ChatContext
{
    public function transaction(User $user, Thread $thread, string $permission, \Closure $operation): mixed;

    public function permits(User $user, Thread $thread, string $permission): bool;

    public function visibleSources(User $user, ?Workspace $workspace, string $query): Builder;

    public function authorize(User $user, Thread $thread): void;

    public function side(User $user, Thread $thread): string;

    public function details(User $user, Thread $thread): array;

    public function card(User $user, Thread $thread): array;

    public function sent(User $user, Thread $thread): void;

    public function read(User $user, Thread $thread): void;
}
