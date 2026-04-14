<?php

return [
    'api' => [
        'base_url' => env('PRESENCA_BASE_URL', 'https://presenca-bank-api.azurewebsites.net'),
        'login' => env('PRESENCA_LOGIN'),
        'senha' => env('PRESENCA_SENHA'),
        'tenant_id' => env('PRESENCA_TENANT_ID', 'superuser'),
        'produto_id' => (int) env('PRESENCA_PRODUTO_ID', 28),
        'token_ttl_fallback_seconds' => (int) env('PRESENCA_TOKEN_TTL_FALLBACK_SECONDS', 3300),
        'token_ttl_skew_seconds' => (int) env('PRESENCA_TOKEN_TTL_SKEW_SECONDS', 30),
        'token_lock_ttl' => (int) env('PRESENCA_TOKEN_LOCK_TTL', 10),
        'token_lock_wait' => (int) env('PRESENCA_TOKEN_LOCK_WAIT', 5),
    ],

    'http' => [
        'timeout' => (int) env('PRESENCA_HTTP_TIMEOUT', 30),
        'connect_timeout' => (int) env('PRESENCA_HTTP_CONNECT_TIMEOUT', 10),
        'retry_attempts' => (int) env('PRESENCA_HTTP_RETRY_ATTEMPTS', 4),
        'retry_base_delay_ms' => (int) env('PRESENCA_HTTP_RETRY_BASE_DELAY_MS', 1000),
        'retry_max_delay_ms' => (int) env('PRESENCA_HTTP_RETRY_MAX_DELAY_MS', 12000),
        'default_429_delay_seconds' => (int) env('PRESENCA_HTTP_429_DEFAULT_DELAY_SECONDS', 3),
    ],

    'rate_limit' => [
        'enabled' => (bool) env('PRESENCA_RATE_LIMIT_ENABLED', true),
        'min_interval_ms' => (int) env('PRESENCA_MIN_INTERVAL_MS', 2000),
        'max_requests_per_minute' => (int) env('PRESENCA_MAX_REQUESTS_PER_MINUTE', 30),
        'lock_ttl' => (int) env('PRESENCA_RATE_LOCK_TTL', 10),
        'lock_wait' => (int) env('PRESENCA_RATE_LOCK_WAIT', 5),
    ],

    'simulacao' => [
        'retry_attempts' => (int) env('PRESENCA_SIMULACAO_RETRY_ATTEMPTS', 12),
        'retry_delay_seconds' => (int) env('PRESENCA_SIMULACAO_RETRY_DELAY_SECONDS', 3),
        'email_domain' => env('PRESENCA_EMAIL_DOMAIN', 'example.com'),
    ],

    'termo' => [
        'phone_retry_attempts' => (int) env('PRESENCA_TERMO_PHONE_RETRY_ATTEMPTS', 5),
    ],

    'authorization' => [
        'reuse_ttl_seconds' => (int) env('PRESENCA_AUTH_REUSE_TTL_SECONDS', 172800),
        'local_cache_max' => (int) env('PRESENCA_AUTH_LOCAL_CACHE_MAX', 5000),
        'warmup_batch_size' => (int) env('PRESENCA_AUTH_WARMUP_BATCH_SIZE', 500),
    ],

    'job' => [
        'queue' => env('PRESENCA_JOB_QUEUE', 'presenca'),
        'timeout_seconds' => (int) env('PRESENCA_JOB_TIMEOUT', 115200),
        'status_check_interval_ms' => (int) env('PRESENCA_JOB_STATUS_CHECK_INTERVAL_MS', 1000),
        'progress_flush_interval_seconds' => (int) env('PRESENCA_JOB_PROGRESS_FLUSH_INTERVAL_SECONDS', 10),
        'rows_buffer_flush' => (int) env('PRESENCA_JOB_ROWS_BUFFER_FLUSH', 100),
    ],

    'preview' => [
        'queue' => env('PRESENCA_PREVIEW_QUEUE', 'reports'),
    ],

    'storage' => [
        'reports_disk' => env('PRESENCA_REPORTS_DISK', 'local'),
        'dir_reports' => env('PRESENCA_REPORTS_DIR', 'presenca-reports'),
        'dir_spool' => env('PRESENCA_SPOOL_DIR', 'presenca-spool'),
        'final_prefix' => env('PRESENCA_FINAL_PREFIX', 'presenca-consulta'),
    ],

    'csv' => [
        'embed_bom' => (bool) env('PRESENCA_CSV_EMBED_BOM', true),
        'final_eol' => env('PRESENCA_CSV_FINAL_EOL', 'LF'),
    ],

    'logging' => [
        'enabled' => (bool) env('PRESENCA_LOG_ENABLED', true),
        'api_log_responses' => (bool) env('PRESENCA_API_LOG_RESPONSES', true),
        'api_log_success_responses' => (bool) env('PRESENCA_API_LOG_SUCCESS_RESPONSES', false),
        'api_log_429' => (bool) env('PRESENCA_API_LOG_429', true),
    ],
];
