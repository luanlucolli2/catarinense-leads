<?php

return [

    'webhook_secret' => env('INOVACHAT_WEBHOOK_SECRET'),

    'api' => [
        'base_url'         => rtrim(env('INOVACHAT_API_BASE', 'https://api20.inovachat.com.br'), '/'),
        'connection_token' => env('INOVACHAT_CONNECTION_TOKEN'),
    ],

    'queue_webhook' => [
        'token_origin' => env('INOVACHAT_QUEUE_WEBHOOK_TOKEN_ORIGIN', 'API.CLTCHATBOTTESTE.06112025'),

        // fila onde o ticket fica aguardando autorização (fila do webhook)
        'c6_wait_queue_id' => env('INOVACHAT_C6_WAIT_QUEUE_ID', '99'),

        // anti-spam quando o lead manda várias mensagens nessa fila
        'reminder_cooldown_seconds' => (int) env('INOVACHAT_C6_WAIT_REMINDER_COOLDOWN_SECONDS', 120),
    ],

    'http' => [
        'timeout'         => (int) env('INOVACHAT_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('INOVACHAT_HTTP_CONNECT_TIMEOUT', 5),
        'retry'           => (int) env('INOVACHAT_HTTP_RETRY', 1),
        'retry_delay_ms'  => (int) env('INOVACHAT_HTTP_RETRY_DELAY_MS', 200),
    ],

    'handoff' => [
        'queue_id' => env('INOVACHAT_HANDOFF_QUEUE_ID'),
        'status'   => env('INOVACHAT_HANDOFF_STATUS', 'pending'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tags (IDs do Inovachat)
    |--------------------------------------------------------------------------
    */
    'tags' => [
        // ID da tag "C6 não autorizado" no Inovachat
        'c6_not_authorized_id' => (int) env('INOVACHAT_TAG_C6_NAO_AUTORIZADO_ID', 0),
    ],
];
