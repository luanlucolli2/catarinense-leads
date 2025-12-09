<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inovachat – Webhook + API de Mensagem
    |--------------------------------------------------------------------------
    */

    'webhook_secret' => env('INOVACHAT_WEBHOOK_SECRET'),

    'api' => [
        'base_url'         => rtrim(env('INOVACHAT_API_BASE', 'https://api20.inovachat.com.br'), '/'),
        'connection_token' => env('INOVACHAT_CONNECTION_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP (timeouts e retry) – Mensagens de texto
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout'         => (int) env('INOVACHAT_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('INOVACHAT_HTTP_CONNECT_TIMEOUT', 5),
        'retry'           => (int) env('INOVACHAT_HTTP_RETRY', 1),
        'retry_delay_ms'  => (int) env('INOVACHAT_HTTP_RETRY_DELAY_MS', 200),
    ],
];
