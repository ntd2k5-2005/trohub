<?php

namespace App\Modules\DataPlatform\Contracts;

/** A rebuildable projection, never an authority for access, occupancy or money. */
interface ProjectionStore
{
    public function put(array $event): void;

    public function history(int $workspaceId, string $from, string $until, int $limit = 100): array;

    public function purge(int $workspaceId): void;
}
