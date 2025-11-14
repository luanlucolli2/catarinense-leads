<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    | Usamos ENV para permitir domínios de staging/produção e localhost.
    */
    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', 'localhost,localhost:8080')),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    | Tenta o guard "web" (cookie stateful) antes do Bearer.
    */
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
