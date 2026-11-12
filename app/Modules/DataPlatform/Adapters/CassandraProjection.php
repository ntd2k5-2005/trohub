<?php

namespace App\Modules\DataPlatform\Adapters;

use App\Modules\DataPlatform\Contracts\ProjectionStore;
use CassandraNative\Auth\PasswordAuthenticator;
use CassandraNative\Cluster\ClusterBuilder;
use CassandraNative\ColumnType;
use CassandraNative\Statement\SimpleStatement;
use RuntimeException;

final class CassandraProjection implements ProjectionStore
{
    private function session()
    {
        $keyspace = config('data_stores.cassandra.keyspace');
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $keyspace)) {
            throw new RuntimeException('Invalid Cassandra keyspace');
        }
        $builder = (new ClusterBuilder)
            ->withContactPoints([config('data_stores.cassandra.host')])
            ->withPort(config('data_stores.cassandra.port'))
            ->withConnectTimeout(1.5)->withRequestTimeout(2)
            ->withMaxConnectionAttempts(1)->withPersistentSessions(false);
        if (config('data_stores.cassandra.username')) {
            $builder->withCredentials(new PasswordAuthenticator(config('data_stores.cassandra.username'), config('data_stores.cassandra.password')));
        }
        $session = $builder->build();
        $session->connect($keyspace);

        return $session;
    }

    private function execute(string $cql, array $args)
    {
        $session = $this->session();
        try {
            return $session->execute(new SimpleStatement($cql), array_map(fn ($value) => [$value, ColumnType::Varchar], $args));
        } finally {
            $session->close();
        }
    }

    public function put(array $event): void
    {
        $this->execute('INSERT INTO history_by_workspace (workspace_id, event_time, event_id, payload) VALUES (?, ?, ?, ?)', [(string) ($event['workspace_id'] ?? 0), $event['event_time'], $event['event_id'], json_encode($event, JSON_THROW_ON_ERROR)]);
    }

    public function history(int $workspaceId, string $from, string $until, int $limit = 100): array
    {
        $rows = $this->execute('SELECT payload FROM history_by_workspace WHERE workspace_id = ? AND event_time >= ? AND event_time <= ? LIMIT '.max(1, min($limit, 1000)), [(string) $workspaceId, $from, $until]);
        $result = [];
        foreach ($rows as $row) {
            $result[] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
        }

        return $result;
    }

    public function purge(int $workspaceId): void
    {
        $this->execute('DELETE FROM history_by_workspace WHERE workspace_id = ?', [(string) $workspaceId]);
    }
}
