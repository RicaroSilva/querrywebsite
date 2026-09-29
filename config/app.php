<?php
/**
 * General application configuration.
 * Values come from .env; defaults here are safe for production.
 */
return [
    'name'     => env('APP_NAME', 'QueryDeck'),
    'env'      => env('APP_ENV', 'production'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'url'      => rtrim((string) env('APP_URL', ''), '/'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'locale'   => env('APP_LOCALE', 'pt'),
    'key'      => env('APP_KEY', ''),
    'version'  => '1.0.0',

    'session' => [
        'name'     => env('SESSION_NAME', 'querydeck_sid'),
        'lifetime' => (int) env('SESSION_LIFETIME', 7200),
        'secure'   => env('SESSION_SECURE_COOKIE', 'auto'),
    ],

    'auth' => [
        'max_attempts'    => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'lockout_seconds' => (int) env('LOGIN_LOCKOUT_SECONDS', 900),
    ],

    'test_user' => [
        'name'     => env('TEST_USER_NAME', 'Administrator'),
        'email'    => env('TEST_USER_EMAIL'),
        'password' => env('TEST_USER_PASSWORD'),
    ],
];
