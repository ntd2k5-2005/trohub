// Execute against the configured projection database after authorization.
db.event_documents.createIndex({workspace_id: 1, event_time: -1});
db.event_documents.createIndex({event_type: 1, event_time: -1});
// _id = event_id is the idempotency key. Do not TTL all safety/audit events indiscriminately.
