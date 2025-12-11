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

    /*
    |--------------------------------------------------------------------------
    | Handoff (fila de vendedores após autorização C6)
    |--------------------------------------------------------------------------
    */
    'handoff' => [
        // ID da fila dos vendedores (ex.: "Vendedores CLT C6").
        'queue_id' => env('INOVACHAT_HANDOFF_QUEUE_ID'),

        // Status a ser aplicado ao ticket quando for para a fila humana.
        // Conforme doc da API Atualizar Ticket: open, pending, closed.
        'status'   => env('INOVACHAT_HANDOFF_STATUS', 'pending'),
    ],
];
