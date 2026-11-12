<?php

namespace App\Modules\DataPlatform\Contracts;

interface HotCache
{
    public function remember(string $key, int $seconds, callable $source): mixed;

    public function forget(string $key): void;
}
