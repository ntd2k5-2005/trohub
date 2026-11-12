<?php

return [
    'providers' => [
        'google' => ['label' => 'Google', 'enabled' => env('SOCIAL_LOGIN_GOOGLE_ENABLED', false), 'scopes' => ['openid', 'email', 'profile'], 'pkce' => true],
        'facebook' => ['label' => 'Facebook', 'enabled' => env('SOCIAL_LOGIN_FACEBOOK_ENABLED', false), 'scopes' => ['public_profile', 'email'], 'pkce' => false],
    ],
    'pending_minutes' => 20,
    'email_code_minutes' => 10,
    'email_code_attempts' => 5,
    'email_resend_seconds' => 60,
];
