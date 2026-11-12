<?php

return [
    'iot' => filter_var(env('FEATURE_IOT', true), FILTER_VALIDATE_BOOL),
    'analytics' => filter_var(env('FEATURE_ANALYTICS', false), FILTER_VALIDATE_BOOL),
    'roommate' => filter_var(env('FEATURE_ROOMMATE', true), FILTER_VALIDATE_BOOL),
    'online_payment' => filter_var(env('FEATURE_ONLINE_PAYMENT', false), FILTER_VALIDATE_BOOL),
    'internal_chat' => filter_var(env('FEATURE_INTERNAL_CHAT', true), FILTER_VALIDATE_BOOL),
];
