<?php

namespace App\Modules\DataPlatform\Adapters;

use App\Modules\DataPlatform\Contracts\ProjectionStore;
use MongoDB\Driver\BulkWrite;
use MongoDB\Driver\Manager;
use MongoDB\Driver\Query;
use RuntimeException;

final class MongoProjection implements ProjectionStore
{
    private function manager()
    {
        if (! class_exists('MongoDB\\Driver\\Manager')) {
            throw new RuntimeException('MongoDB driver unavailable');
        }

        return new Manager(config('data_stores.mongodb.uri'), ['serverSelectionTimeoutMS' => 1500, 'connectTimeoutMS' => 1500]);
    }

    private function collection(): string
    {
        return config('data_stores.mongodb.database').'.event_documents';
    }

    public function put(array $event): void
    {
        $bulk = new BulkWrite;
        $bulk->update(['_id' => $event['event_id']], ['$set' => $event], ['upsert' => true]);
        $this->manager()->executeBulkWrite($this->collection(), $bulk);
    }

    public function history(int $workspaceId, string $from, string $until, int $limit = 100): array
    {
        $query = new Query(['workspace_id' => $workspaceId, 'event_time' => ['$gte' => $from, '$lte' => $until]], ['sort' => ['event_time' => -1], 'limit' => min($limit, 1000)]);

        return json_decode(json_encode($this->manager()->executeQuery($this->collection(), $query)->toArray(), JSON_THROW_ON_ERROR), true);
    }

    public function purge(int $workspaceId): void
    {
        $bulk = new BulkWrite;
        $bulk->delete(['workspace_id' => $workspaceId], ['limit' => 0]);
        $this->manager()->executeBulkWrite($this->collection(), $bulk);
    }
}
