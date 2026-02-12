<?php

use Laravel\Sanctum\Sanctum;

return [

    'stateful' => array_values(array_filter(array_map(
        static fn (string $domain): string => trim($domain),
        explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
            '%s%s',
            'localhost,localhost:3000,localhost:5173,localhost:8080,127.0.0.1,127.0.0.1:5173,127.0.0.1:8000,127.0.0.1:8080,::1,',
            Sanctum::currentApplicationUrlWithPort()
        )))
    ))),

    // Garante que Sanctum tente web guard primeiro, depois Bearer
    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiração de Tokens (PAT)
    |--------------------------------------------------------------------------
    | Em minutos. 43200 = 30 dias. Null = sem expiração (evitar).
    */
    'expiration' => env('SANCTUM_EXPIRATION', 43200),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    | Útil para scanners de segredo. Mantém consistência com "dev".
    */
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'st_'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    | Pipeline para requests stateful da SPA.
    */
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies'      => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token'  => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
