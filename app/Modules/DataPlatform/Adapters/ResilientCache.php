<?php

namespace App\Modules\DataPlatform\Adapters;

use App\Modules\DataPlatform\Contracts\HotCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ResilientCache implements HotCache
{
    public function remember(string $key, int $seconds, callable $source): mixed
    {
        if (! config('data_stores.redis.enabled')) {
            return $source();
        }
        try {
            return Cache::store('redis')->remember($key, $seconds, $source);
        } catch (Throwable $e) {
            Log::warning('Optional cache unavailable', ['exception' => $e::class]);

            return $source();
        }
    }

    public function forget(string $key): void
    {
        if (! config('data_stores.redis.enabled')) {
            return;
        } try {
            Cache::store('redis')->forget($key);
        } catch (Throwable $e) {
            Log::warning('Optional cache invalidation failed', ['exception' => $e::class]);
        }
    }
}
