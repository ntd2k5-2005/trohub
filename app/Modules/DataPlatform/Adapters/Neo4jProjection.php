<?php

namespace App\Modules\DataPlatform\Adapters;

use App\Modules\DataPlatform\Contracts\ProjectionStore;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class Neo4jProjection implements ProjectionStore
{
    private function query(string $statement, array $parameters): array
    {
        $response = Http::connectTimeout(1)->timeout(3)->withBasicAuth(config('data_stores.neo4j.username'), config('data_stores.neo4j.password') ?? '')
            ->post(rtrim(config('data_stores.neo4j.http_uri'), ' /').'/db/neo4j/tx/commit', ['statements' => [['statement' => $statement, 'parameters' => $parameters]]])->throw()->json();
        if (! empty($response['errors'])) {
            throw new RuntimeException('Neo4j query rejected');
        }

        return $response['results'][0]['data'] ?? [];
    }

    public function put(array $event): void
    {
        $this->query('MERGE (e:BusinessEvent {event_id: $id}) SET e.workspace_id = $workspace, e.event_time = $time, e.payload = $payload MERGE (w:Workspace {source_id: $workspace}) MERGE (e)-[:IN_WORKSPACE]->(w)', ['id' => $event['event_id'], 'workspace' => $event['workspace_id'] ?? 0, 'time' => $event['event_time'], 'payload' => json_encode($event, JSON_THROW_ON_ERROR)]);
        if (isset($event['payload']['roommate'])) {
            $n = $event['payload']['roommate'];
            $this->query('MERGE (n:RoommateNeed {source_id:$id}) WITH n WHERE n.updated_at IS NULL OR n.updated_at <= $time SET n.budget=$budget, n.status=$status, n.updated_at=$time WITH n OPTIONAL MATCH (n)-[old:SEEKS_AREA]->() DELETE old WITH n MERGE (a:Area {name:$district}) MERGE (n)-[:SEEKS_AREA]->(a)', ['id' => (int) $n['id'], 'budget' => (int) $n['budget'], 'status' => $n['status'], 'time' => $event['event_time'], 'district' => $n['district']]);
            if (isset($n['user_id'])) {
                $this->query('MATCH (n:RoommateNeed {source_id:$id}) MERGE (u:User {source_id:$user}) MERGE (u)-[:HAS_NEED]->(n)', ['id' => $n['id'], 'user' => $n['user_id']]);
            }
        }
    }

    public function compatible(int $needId): array
    {
        return array_map(fn ($row) => (int) $row['row'][0], $this->query('MATCH (me:RoommateNeed {source_id:$id})-[:SEEKS_AREA]->(a:Area)<-[:SEEKS_AREA]-(other:RoommateNeed) WHERE other.source_id <> $id AND other.status = $open AND abs(other.budget-me.budget) <= $tolerance RETURN other.source_id ORDER BY abs(other.budget-me.budget) LIMIT 20', ['id' => $needId, 'open' => 'open', 'tolerance' => config('business.roommate_budget_tolerance', 1000000)]));
    }

    public function history(int $workspaceId, string $from, string $until, int $limit = 100): array
    {
        $rows = $this->query('MATCH (e:BusinessEvent)-[:IN_WORKSPACE]->(w:Workspace {source_id: $workspace}) WHERE e.event_time >= $from AND e.event_time <= $until RETURN e.payload ORDER BY e.event_time DESC LIMIT $limit', ['workspace' => $workspaceId, 'from' => $from, 'until' => $until, 'limit' => min($limit, 1000)]);

        return array_map(fn ($r) => json_decode($r['row'][0], true, 512, JSON_THROW_ON_ERROR), $rows);
    }

    public function purge(int $workspaceId): void
    {
        $this->query('MATCH (e:BusinessEvent {workspace_id: $workspace}) DETACH DELETE e', ['workspace' => $workspaceId]);
        $this->query('MATCH (w:Workspace {source_id: $workspace}) DETACH DELETE w', ['workspace' => $workspaceId]);
    }
}
