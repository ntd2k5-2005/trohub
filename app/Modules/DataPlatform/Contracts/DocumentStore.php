<?php

namespace App\Modules\DataPlatform\Contracts;

interface DocumentStore
{
    public function put(string $collection, string $id, array $document): void;
    public function find(string $collection, array $filter, int $limit = 100): array;
    public function delete(string $collection, string $id): void;
}
