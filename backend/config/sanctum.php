<?php

use Laravel\Sanctum\Sanctum;

return [

   'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,localhost:8080')),


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
