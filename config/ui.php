<?php

return [
    'theme' => [
        'primary' => env('APP_BRAND_PRIMARY') ?: '#5B5BD6',
        'accent' => env('APP_BRAND_ACCENT') ?: '#14B8A6',
        'radius' => '18px',
        'sidebar_width' => '280px',
        'content_max_width' => '1600px',
    ],
    'density' => 'comfortable',
    'default_theme' => 'system',
    'breakpoints' => [
        'sm' => 640,
        'md' => 768,
        'lg' => 1024,
        'xl' => 1280,
        '2xl' => 1536,
    ],
];
