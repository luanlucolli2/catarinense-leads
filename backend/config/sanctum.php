<?php

use Laravel\Sanctum\Sanctum;

return [

    'stateful' => [
        'localhost',
        'localhost:8080',
        '192.168.25.165:8080',
        '127.0.0.1',
        '127.0.0.1:8080',
        '::1',
        '172.29.43.177:8080',
    ],

    // Garante que Sanctum tente web guard primeiro, depois Bearer
    'guard' => ['web'],

    /**
     * Expiração de PATs em minutos.
     * Use env SANCTUM_EXPIRATION. Padrão 30 dias.
     * Null = sem expiração (não recomendado).
     */
    'expiration' => env('SANCTUM_EXPIRATION', 43200),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'st_'),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
