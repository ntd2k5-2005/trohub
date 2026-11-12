<?php

return [
    'timezone' => 'Asia/Ho_Chi_Minh',
    // Existing Laravel timestamps are written in app.timezone. Override only after a verified DB time conversion.
    'storage_timezone' => env('BUSINESS_STORAGE_TIMEZONE', env('APP_TIMEZONE', 'Asia/Ho_Chi_Minh')),
    'expiring_days' => 7,
    'preview_limit' => 3,
];
