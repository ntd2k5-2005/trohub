<?php

namespace App\Modules\DataPlatform\Adapters;

use App\Modules\DataPlatform\Contracts\DocumentStore;
use MongoDB\Driver\{BulkWrite, Manager, Query};
use RuntimeException;

final class MongoDocuments implements DocumentStore
{
    private function manager(): Manager
    {
        if (!config('data_stores.mongodb.enabled') || !class_exists(Manager::class)) throw new RuntimeException('MongoDB unavailable');
        return new Manager(config('data_stores.mongodb.uri'), ['serverSelectionTimeoutMS'=>1500]);
    }
    private function namespace(string $collection): string
    {
        if (!in_array($collection,['roommate_profiles','review_details'],true)) throw new RuntimeException('Unsupported document collection');
        return config('data_stores.mongodb.database').'.'.$collection;
    }
    public function put(string $collection, string $id, array $document): void
    {
        unset($document['_id']); $bulk=new BulkWrite;
        $bulk->update(['_id'=>$id],['$set'=>$document],['upsert'=>true]);
        $this->manager()->executeBulkWrite($this->namespace($collection),$bulk);
    }
    public function find(string $collection, array $filter, int $limit=100): array
    {
        return json_decode(json_encode($this->manager()->executeQuery($this->namespace($collection),new Query($filter,['limit'=>max(1,min($limit,1000))]))->toArray(),JSON_THROW_ON_ERROR),true);
    }
    public function delete(string $collection, string $id): void
    {
        $bulk=new BulkWrite; $bulk->delete(['_id'=>$id],['limit'=>1]);
        $this->manager()->executeBulkWrite($this->namespace($collection),$bulk);
    }
}
