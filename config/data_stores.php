<?php

return [
    'relational' => [
        'connection' => env('DB_CONNECTION', 'mysql'),
        'purpose' => 'Dữ liệu giao dịch và quan hệ cốt lõi của nền tảng.',
    ],
    'mongodb' => [
        'enabled' => filter_var(env('MONGODB_ENABLED', false), FILTER_VALIDATE_BOOL),
        'uri' => env('MONGODB_URI', 'mongodb://127.0.0.1:27017'),
        'database' => env('MONGODB_DATABASE', 'trohub'),
        'purpose' => 'Dữ liệu tài liệu / nội dung linh hoạt theo use case được chọn.',
    ],
    'redis' => [
        'enabled' => filter_var(env('REDIS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'purpose' => 'Cache, session, rate limit, dữ liệu tạm thời hoặc realtime.',
    ],
    'cassandra' => [
        'enabled' => filter_var(env('CASSANDRA_ENABLED', false), FILTER_VALIDATE_BOOL),
        'host' => env('CASSANDRA_HOST', '127.0.0.1'),
        'port' => (int) env('CASSANDRA_PORT', 9042),
        'keyspace' => env('CASSANDRA_KEYSPACE', 'trohub'),
        'username' => env('CASSANDRA_USERNAME'),
        'password' => env('CASSANDRA_PASSWORD'),
        'purpose' => 'Chuỗi sự kiện / telemetry có khối lượng lớn và truy vấn theo thời gian.',
    ],
    'neo4j' => [
        'http_uri' => env('NEO4J_HTTP_URI', 'http://127.0.0.1:7474'),
        'enabled' => filter_var(env('NEO4J_ENABLED', false), FILTER_VALIDATE_BOOL),
        'uri' => env('NEO4J_URI', 'bolt://127.0.0.1:7687'),
        'username' => env('NEO4J_USERNAME', 'neo4j'),
        'password' => env('NEO4J_PASSWORD'),
        'purpose' => 'Quan hệ đồ thị như gợi ý, kết nối và bài toán quan hệ nhiều bước.',
    ],
    'meilisearch' => [
        'enabled' => filter_var(env('MEILISEARCH_ENABLED', false), FILTER_VALIDATE_BOOL),
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index' => env('MEILISEARCH_INDEX', 'trohub_listings'),
        'max_candidates' => 1000,
        'timeout_seconds' => 3,
        'task_wait_seconds' => 5,
        'state_store' => 'file',
    ],
    'big_data' => [
        'enabled' => filter_var(env('BIGDATA_ENABLED', false), FILTER_VALIDATE_BOOL),
        'endpoint' => env('BIGDATA_ENDPOINT'),
        'purpose' => 'Module phân tích Big Data cắm ngoài core qua contract/event.',
    ],
];
