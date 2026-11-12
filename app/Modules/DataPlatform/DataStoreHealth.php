<?php

namespace App\Modules\DataPlatform;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

final class DataStoreHealth
{
    public function check(): array
    {
        $results = [];
        foreach (['mongodb', 'redis', 'cassandra', 'neo4j', 'meilisearch'] as $name) {
            $result = ['status' => 'disabled', 'checked_at' => now()->toIso8601String()];
            if (config('data_stores.'.$name.'.enabled')) {
                try {
                    if ($name === 'redis') {
                        $pong = Cache::store('redis')->getStore()->connection()->ping();
                        if (! in_array(strtoupper((string) $pong), ['PONG', '1'], true)) {
                            throw new \RuntimeException('Unexpected cache response');
                        }
                    } elseif ($name === 'meilisearch') {
                        $request = Http::connectTimeout(1)->timeout(config('data_stores.meilisearch.timeout_seconds', 3));
                        if ($key = config('data_stores.meilisearch.key')) {
                            $request = $request->withToken($key);
                        }
                        if ($request->get(rtrim(config('data_stores.meilisearch.host'), '/').'/health')->throw()->json('status') !== 'available') {
                            throw new \RuntimeException('Search unavailable');
                        }
                    } else {
                        app(ProjectionManager::class)->store($name)->history(0, '1970-01-01', now()->toIso8601String(), 1);
                    }
                    $result['status'] = 'connected';
                } catch (Throwable) {
                    $result['status'] = 'unavailable';
                }
            }
            $results[$name] = $result;
        }
        Cache::put('data:health', $results, 600);

        return $results;
    }

    public function latest(): array
    {
        return Cache::get('data:health', []);
    }
}
