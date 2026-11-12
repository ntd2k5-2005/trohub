-- Run with a separately authorized Cassandra account. Single-node development settings.
CREATE KEYSPACE IF NOT EXISTS trohub WITH replication = {'class':'SimpleStrategy','replication_factor':1};
CREATE TABLE IF NOT EXISTS trohub.history_by_workspace (
    workspace_id text,
    event_time text,
    event_id text,
    payload text,
    PRIMARY KEY ((workspace_id), event_time, event_id)
) WITH CLUSTERING ORDER BY (event_time DESC, event_id ASC);
-- ISO-8601 timestamps must be normalized to the same UTC format.
-- Before large-volume deployment, introduce time buckets and retention per event class.
