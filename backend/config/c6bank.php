<?php

return [

    /*
    |--------------------------------------------------------------------------
    | C6 Bank – API Marketplace Proposal Service
    |--------------------------------------------------------------------------
    */

    // Base oficial da doc do C6
    'base_url' => rtrim(
        env('C6_BASE_URL', 'https://marketplace-proposal-service-api-p.c6bank.info'),
        '/'
    ),

    'auth' => [
        'username' => env('C6_USERNAME'),
        'password' => env('C6_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP (timeouts e retry)
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout'         => (int) env('C6_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('C6_HTTP_CONNECT_TIMEOUT', 5),
        'retry'           => (int) env('C6_HTTP_RETRY', 1),
        'retry_delay_ms'  => (int) env('C6_HTTP_RETRY_DELAY_MS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | JOB – geração de link de autorização (trabalhador)
    |--------------------------------------------------------------------------
    | Fila dedicada para serializar chamadas ao C6.
    */
    'job' => [
        'queue'   => env('C6_JOB_QUEUE', 'c6-auth'),
        'timeout' => (int) env('C6_JOB_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Headers específicos
    |--------------------------------------------------------------------------
    */
    'headers' => [
        // Accept da API de geração de link (doc C6)
        'authorization_generate_accept' => env(
            'C6_AUTHORIZATION_GENERATE_ACCEPT',
            'application/vnd.c6bank_authorization_generate_liveness_v1+json'
        ),
    ],
];
