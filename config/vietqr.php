<?php

return [
    'driver' => env('VIETQR_DRIVER', 'quicklink'),
    'template' => env('VIETQR_TEMPLATE', 'compact2'),
    'client_id' => env('VIETQR_CLIENT_ID'),
    'api_key' => env('VIETQR_API_KEY'),
    'timeout' => 8,
    'catalog_ttl' => 86400,
    'catalog_stale_ttl' => 604800,
    'max_image_bytes' => 2097152,
    'banks_url' => 'https://api.vietqr.io/v2/banks',
    'generate_url' => 'https://api.vietqr.io/v2/generate',
    'image_base' => 'https://img.vietqr.io/image/',
];
