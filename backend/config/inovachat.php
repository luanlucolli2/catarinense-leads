<?php

return [

    'webhook_secret' => env('INOVACHAT_WEBHOOK_SECRET'),

    'api' => [
        'base_url' => rtrim(env('INOVACHAT_API_BASE', 'https://api20.inovachat.com.br'), '/'),

        /**
         * Base URL específica para API Oficial de mensagens.
         * Se não setar, cai na base_url padrão.
         */
        'official_base_url' => rtrim(
            env('INOVACHAT_API_BASE_OFFICIAL', env('INOVACHAT_API_BASE', 'https://api20.inovachat.com.br')),
            '/'
        ),

        /**
         * Modo da API de mensagens:
         * - basic    => /api/messages/send (body/openTicket/queueId)
         * - official => /api/messages/sendOfficialData (text)
         */
        'message_mode' => env('INOVACHAT_MESSAGE_API_MODE', 'basic'),

        /**
         * Backward-compatible: se você tiver apenas 1 conexão, pode continuar usando.
         * Em multi-conexões, os Services vão receber o token correto por request
         * (token_origin / connection_token do lead) e este vira apenas fallback.
         */
        'connection_token' => env('INOVACHAT_CONNECTION_TOKEN'),
    ],

    /**
     * Multi-conexões:
     * - token_origin (webhook de fila) e connection_token (flow -> sua API) são o mesmo valor.
     * - aqui você mantém um allowlist dos tokens válidos.
     */
    'connections' => [
        'tokens' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INOVACHAT_CONNECTION_TOKENS', (string) env('INOVACHAT_CONNECTION_TOKEN', '')))
        ))),
    ],

    'queue_webhook' => [
        /**
         * token_origin pode variar por conexão.
         * Se não definir INOVACHAT_QUEUE_WEBHOOK_TOKEN_ORIGINS, cai no allowlist de connections.tokens.
         */
        'token_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INOVACHAT_QUEUE_WEBHOOK_TOKEN_ORIGINS', ''))
        ))),

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
