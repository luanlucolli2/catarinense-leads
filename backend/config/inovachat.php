<?php

/**
 * Fonte única:
 * - INOVACHAT_CONNECTIONS_MAP="TOKEN1:basic,TOKEN2:official,..."
 *
 * Fallback (legado):
 * - INOVACHAT_CONNECTION_TOKENS / INOVACHAT_QUEUE_WEBHOOK_TOKEN_ORIGINS / INOVACHAT_CONNECTION_TOKEN
 */
if (!function_exists('inovachat_parse_connections_map')) {
    function inovachat_parse_connections_map(): array
    {
        $raw = trim((string) env('INOVACHAT_CONNECTIONS_MAP', ''));

        $map = [];

        if ($raw !== '') {
            $pairs = array_filter(array_map('trim', explode(',', $raw)));

            foreach ($pairs as $pair) {
                $parts = array_map('trim', explode(':', $pair, 2));
                if (count($parts) !== 2) {
                    continue;
                }

                [$token, $mode] = $parts;

                $token = (string) $token;
                $mode = strtolower((string) $mode);

                if ($token === '') {
                    continue;
                }
                if (!in_array($mode, ['basic', 'official'], true)) {
                    continue;
                }

                $map[$token] = $mode;
            }

            return $map;
        }

        $defaultMode = strtolower((string) env('INOVACHAT_MESSAGE_API_MODE', 'basic'));
        $defaultMode = in_array($defaultMode, ['basic', 'official'], true) ? $defaultMode : 'basic';

        $legacyTokens = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INOVACHAT_CONNECTION_TOKENS', (string) env('INOVACHAT_CONNECTION_TOKEN', '')))
        )));

        foreach ($legacyTokens as $t) {
            if ($t !== '') {
                $map[$t] = $defaultMode;
            }
        }

        $fallbackToken = trim((string) env('INOVACHAT_CONNECTION_TOKEN', ''));
        if ($fallbackToken !== '' && !isset($map[$fallbackToken])) {
            $map[$fallbackToken] = $defaultMode;
        }

        return $map;
    }
}

$connectionsMap = inovachat_parse_connections_map();
$connectionTokens = array_keys($connectionsMap);

// URA tokens (mantido separado se você usa isso em outras partes)
$uraTokens = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env(
        'INOVACHAT_URA_CONNECTION_TOKENS',
        (string) env('INOVACHAT_CONNECTION_TOKENS', (string) env('INOVACHAT_CONNECTION_TOKEN', ''))
    ))
)));

return [

    'webhook_secret' => env('INOVACHAT_WEBHOOK_SECRET'),

    'api' => [
        'base_url' => rtrim(env('INOVACHAT_API_BASE', 'https://api20.inovachat.com.br'), '/'),

        'official_base_url' => rtrim(
            env('INOVACHAT_API_BASE_OFFICIAL', env('INOVACHAT_API_BASE', 'https://api20.inovachat.com.br')),
            '/'
        ),

        /**
         * default global apenas como fallback se token não estiver no mapa
         */
        'message_mode' => env('INOVACHAT_MESSAGE_API_MODE', 'basic'),

        /**
         * token default (fallback) caso alguém chame serviços sem informar token
         */
        'connection_token' => env('INOVACHAT_CONNECTION_TOKEN'),
    ],

    'connections' => [
        /**
         * Fonte única para decidir o modo por token
         * ex: [ 'OFICIAL5' => 'official', 'API.xxx' => 'basic' ]
         */
        'map' => $connectionsMap,

        /**
         * Lista de tokens aceitos na triagem (validação) etc.
         */
        'tokens' => $connectionTokens,

        /**
         * Mantido se você usa URA tokens para outras rotas
         */
        'ura_tokens' => $uraTokens,
    ],

    'queue_webhook' => [
        /**
         * Fonte única:
         * - por padrão, usa as chaves do connections.map.
         * - se quiser sobrescrever por algum motivo muito específico, ainda pode via env legado.
         */
        'token_origins' => array_values(array_unique(array_filter(array_map(
            'trim',
            explode(',', (string) env('INOVACHAT_QUEUE_WEBHOOK_TOKEN_ORIGINS', ''))
        )))) ?: $connectionTokens,

        'c6_wait_queue_id' => env('INOVACHAT_C6_WAIT_QUEUE_ID', '99'),

        'reminder_cooldown_seconds' => (int) env('INOVACHAT_C6_WAIT_REMINDER_COOLDOWN_SECONDS', 120),

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

    'logging' => [
        'verbose'      => (bool) env('INOVACHAT_VERBOSE_LOGS', false),
        'log_failures' => (bool) env('INOVACHAT_LOG_FAILURES', true),
    ],
];
