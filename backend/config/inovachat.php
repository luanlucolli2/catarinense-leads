<?php

return [

    'webhook_secret' => env('INOVACHAT_WEBHOOK_SECRET'),

    'api' => [
        'base_url' => rtrim(env('INOVACHAT_API_BASE', 'https://api20.inovachat.com.br'), '/'),

        'official_base_url' => rtrim(
            env('INOVACHAT_API_BASE_OFFICIAL', env('INOVACHAT_API_BASE', 'https://api20.inovachat.com.br')),
            '/'
        ),

        'message_mode' => env('INOVACHAT_MESSAGE_API_MODE', 'basic'),

        'connection_token' => env('INOVACHAT_CONNECTION_TOKEN'),
    ],

    'connections' => [
        'tokens' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INOVACHAT_CONNECTION_TOKENS', (string) env('INOVACHAT_CONNECTION_TOKEN', '')))
        ))),

        'ura_tokens' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'INOVACHAT_URA_CONNECTION_TOKENS',
                (string) env('INOVACHAT_CONNECTION_TOKENS', (string) env('INOVACHAT_CONNECTION_TOKEN', ''))
            ))
        ))),
    ],

    'queue_webhook' => [
        'token_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INOVACHAT_QUEUE_WEBHOOK_TOKEN_ORIGINS', ''))
        ))),

        'c6_wait_queue_id' => env('INOVACHAT_C6_WAIT_QUEUE_ID', '99'),

        'reminder_cooldown_seconds' => (int) env('INOVACHAT_C6_WAIT_REMINDER_COOLDOWN_SECONDS', 120),

        // ✅ novos knobs de performance
        'dedupe_ttl_seconds' => (int) env('INOVACHAT_QUEUE_WEBHOOK_DEDUPE_TTL_SECONDS', 20),
        'unauthorized_log_cooldown_seconds' => (int) env('INOVACHAT_QUEUE_WEBHOOK_UNAUTHORIZED_LOG_COOLDOWN_SECONDS', 60),
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

    'tags' => [
        'c6_not_authorized_id' => (int) env('INOVACHAT_TAG_C6_NAO_AUTORIZADO_ID', 0),
    ],

    // ✅ logging knobs (reduz ruído em prod)
    'logging' => [
        'verbose'      => (bool) env('INOVACHAT_VERBOSE_LOGS', false),
        'log_failures' => (bool) env('INOVACHAT_LOG_FAILURES', true),
    ],
];
