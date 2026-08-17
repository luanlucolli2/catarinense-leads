<?php

return [
    'auth' => [
        'base_url' => env('HUBCREDITO_BASE_URL', 'https://api.hubcredito.com.br'),
        'username' => env('HUBCREDITO_USERNAME'),
        'password' => env('HUBCREDITO_PASSWORD'),
        'token_ttl_skew' => (int) env('HUBCREDITO_TOKEN_TTL_SKEW', 60),
        'token_lock_ttl' => (int) env('HUBCREDITO_TOKEN_LOCK_TTL', 10),
        'token_lock_wait' => (int) env('HUBCREDITO_TOKEN_LOCK_WAIT', 5),
        'refresh_grant_type' => env('HUBCREDITO_REFRESH_GRANT_TYPE', 'refresh_token'),
    ],

    'integration' => [
        'loja_id' => (int) env('HUBCREDITO_LOJA_ID', 15895),
        'tipo_operacao' => (string) env('HUBCREDITO_TIPO_OPERACAO', '27'),
        'email_domain' => env('HUBCREDITO_EMAIL_DOMAIN', 'hubcredito.local'),
    ],

    'presimulacao' => [
        'valor' => (float) env('HUBCREDITO_PRE_VALOR', 5000),
        'numero_parcelas' => (int) env('HUBCREDITO_PRE_PARCELAS', 12),
    ],

    'http' => [
        'timeout' => (int) env('HUBCREDITO_HTTP_TIMEOUT', 30),
        'connect_timeout' => (int) env('HUBCREDITO_HTTP_CONNECT_TIMEOUT', 10),
        'retry' => (int) env('HUBCREDITO_HTTP_RETRY', 1),
        'retry_delay_ms' => (int) env('HUBCREDITO_HTTP_RETRY_DELAY_MS', 300),
        'min_interval_ms' => (int) env('HUBCREDITO_HTTP_MIN_INTERVAL_MS', 1000),
        'rate_limit_sleep_seconds' => (int) env('HUBCREDITO_HTTP_429_SLEEP_SECONDS', 15),
    ],

    'job' => [
        'queue' => env('HUBCREDITO_JOB_QUEUE', 'hubcredito-clt'),
        'timeout_seconds' => (int) env('HUBCREDITO_JOB_TIMEOUT', 259200),
        'phase2_timeout_seconds' => (int) env('HUBCREDITO_PHASE2_TIMEOUT_SECONDS', 2700),
        'phase2_start_delay_seconds' => (int) env('HUBCREDITO_PHASE2_START_DELAY_SECONDS', 60),
        'phase1_request_interval_ms' => (int) env('HUBCREDITO_PHASE1_REQUEST_INTERVAL_MS', 1500),
        'phase1_batch_pause_every_requests' => (int) env('HUBCREDITO_PHASE1_BATCH_PAUSE_EVERY_REQUESTS', 100),
        'phase1_batch_pause_seconds' => (int) env('HUBCREDITO_PHASE1_BATCH_PAUSE_SECONDS', 30),
        'poll_delay_seconds' => (int) env('HUBCREDITO_POLL_DELAY_SECONDS', 120),
    ],

    'preview' => [
        'queue' => env('HUBCREDITO_PREVIEW_QUEUE', 'reports'),
    ],

    'storage' => [
        'reports_disk' => env('HUBCREDITO_REPORTS_DISK', 'local'),
        'dir_reports' => env('HUBCREDITO_REPORTS_DIR', 'hubcredito-reports'),
        'dir_spool' => env('HUBCREDITO_SPOOL_DIR', 'hubcredito-spool'),
        'final_prefix' => env('HUBCREDITO_FINAL_PREFIX', 'hubcredito-consulta'),
    ],

    'csv' => [
        'embed_bom' => (bool) env('HUBCREDITO_CSV_EMBED_BOM', true),
        'final_eol' => env('HUBCREDITO_CSV_FINAL_EOL', 'LF'),
    ],

    'logging' => [
        'enabled' => (bool) env('HUBCREDITO_LOG_ENABLED', false),
        'api_responses' => (bool) env('HUBCREDITO_LOG_API_RESPONSES', false),
        'api_response_body' => (bool) env('HUBCREDITO_LOG_API_RESPONSE_BODY', false),
        'api_response_body_max_chars' => (int) env('HUBCREDITO_LOG_API_RESPONSE_BODY_MAX_CHARS', 4000),
    ],
];
