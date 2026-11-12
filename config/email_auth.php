<?php

return [
    'verification_minutes' => 20,
    'verification_attempts' => 5,
    'resend_seconds' => 60,
    'pending_session_minutes' => 60,
    'queue' => env('EMAIL_AUTH_QUEUE', false),
    'queue_name' => 'auth-mail',
    'tries' => 3,
    'backoff' => [30, 120, 300],
    'lease_seconds' => 120,
    'show_social_buttons' => env('SOCIAL_LOGIN_SHOW_BUTTONS', false),
];
