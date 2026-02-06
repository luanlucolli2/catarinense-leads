<?php

return [
    'oauth' => [
        'base_url'       => env('V8_OAUTH_URL', 'https://api.v8digital.com'),
        'username'       => env('V8_OAUTH_USERNAME'),
        'password'       => env('V8_OAUTH_PASSWORD'),
        'audience'       => env('V8_OAUTH_AUDIENCE'),
        'client_id'      => env('V8_OAUTH_CLIENT_ID'),
        'scope'          => env('V8_OAUTH_SCOPE', 'offline_access'),
        'token_ttl_skew' => (int) env('V8_TOKEN_TTL_SKEW', 60),
    ],

    'bff' => [
        'base_url'  => env('V8_BFF_BASE_URL', 'https://bff.v8sistema.com'),
        'provider'  => env('V8_PROVIDER', 'QI'),
        'config_id' => env('V8_CONFIG_ID', 'fbbb3a06-05ca-4567-9a92-ce78cb4db796'),
    ],

    'signer' => [
        'email'         => env('V8_SIGNER_EMAIL', 'luangstl@gmail.com'),
        'phone_country' => env('V8_SIGNER_PHONE_COUNTRY', '55'),
        'phone_area'    => env('V8_SIGNER_PHONE_AREA', '47'),
        'phone_number'  => env('V8_SIGNER_PHONE_NUMBER', '997664631'),
    ],

    'simulation' => [
        'disbursed_amount' => (int) env('V8_DISBURSED_AMOUNT', 500),
        'installments'     => (int) env('V8_INSTALLMENTS', 24),
    ],

    'http' => [
        'timeout'         => (int) env('V8_HTTP_TIMEOUT', 15),
        'connect_timeout' => (int) env('V8_HTTP_CONNECT_TIMEOUT', 10),
        'retry'           => (int) env('V8_HTTP_RETRY', 1),
        'retry_delay_ms'  => (int) env('V8_HTTP_RETRY_DELAY_MS', 200),
        'min_interval_ms' => (int) env('V8_HTTP_MIN_INTERVAL_MS', 2000),
        'min_interval_ms_phase1' => (int) env('V8_HTTP_MIN_INTERVAL_MS_PHASE1', 200),
        'min_interval_ms_phase2' => (int) env('V8_HTTP_MIN_INTERVAL_MS_PHASE2', 10000),
        'min_interval_ms_phase2_status' => (int) env('V8_HTTP_MIN_INTERVAL_MS_PHASE2_STATUS', 10000),
        'min_interval_ms_phase2_simulation' => (int) env('V8_HTTP_MIN_INTERVAL_MS_PHASE2_SIMULATION', 2000),
        'rate_limit_sleep_seconds' => (int) env('V8_HTTP_429_SLEEP_SECONDS', 15),
    ],

    'job' => [
        'queue'                      => env('V8_JOB_QUEUE', 'v8'),
        'timeout_seconds'            => (int) env('V8_JOB_TIMEOUT', 115200),
        'status_max_attempts'        => (int) env('V8_STATUS_MAX_ATTEMPTS', 60),
        'status_retry_delay_seconds' => (int) env('V8_STATUS_RETRY_DELAY_SECONDS', 30),
        'status_round_delay_seconds' => (int) env('V8_STATUS_ROUND_DELAY_SECONDS', 20),
        'status_batch_limit'         => (int) env('V8_STATUS_BATCH_LIMIT', 80),
        'status_batch_limit_min'     => (int) env('V8_STATUS_BATCH_LIMIT_MIN', 50),
        'status_batch_limit_max'     => (int) env('V8_STATUS_BATCH_LIMIT_MAX', 300),
        'status_batch_limit_divisor' => (int) env('V8_STATUS_BATCH_LIMIT_DIVISOR', 50),
        'status_batch_limit_round_start' => (int) env('V8_STATUS_BATCH_LIMIT_ROUND_START', 3),
        'status_batch_limit_round_step'  => (int) env('V8_STATUS_BATCH_LIMIT_ROUND_STEP', 50),
        'status_lookback_hours'      => (int) env('V8_STATUS_LOOKBACK_HOURS', 48),
        'status_lookback_existing_hours' => (int) env('V8_STATUS_LOOKBACK_EXISTING_HOURS', 168),
        'phase1_pool_size'           => (int) env('V8_PHASE1_POOL_SIZE', 9),
        'phase1_batch_delay_seconds' => (int) env('V8_PHASE1_BATCH_DELAY_SECONDS', 1),
        'phase2_start_delay_seconds' => (int) env('V8_PHASE2_START_DELAY_SECONDS', 30),
        'pending_low_threshold'      => (int) env('V8_PENDING_LOW_THRESHOLD', 50),
        'pending_low_seconds'        => (int) env('V8_PENDING_LOW_SECONDS', 3600),
        'reconsent_blocked_max'      => (int) env('V8_RECONSENT_BLOCKED_MAX', 1),
        'reconsent_blocked_delay_seconds' => (int) env('V8_RECONSENT_BLOCKED_DELAY_SECONDS', 4),
        'pause_enabled'              => (bool) env('V8_PAUSE_ENABLED', true),
        'pause_start'                => env('V8_PAUSE_START', '16:27'),
        'pause_end'                  => env('V8_PAUSE_END', '16:30'),
        'pause_timezone'             => env('V8_PAUSE_TZ', 'America/Sao_Paulo'),
        'pause_check_interval_seconds' => (int) env('V8_PAUSE_CHECK_INTERVAL', 15),
    ],

    'preview' => [
        'queue' => env('CLT_PREVIEW_QUEUE', 'reports'),
    ],

    'storage' => [
        'reports_disk' => env('V8_REPORTS_DISK', 'local'),
        'dir_reports'  => env('V8_REPORTS_DIR', 'v8-reports'),
        'dir_spool'    => env('V8_SPOOL_DIR', 'v8-spool'),
        'final_prefix' => env('V8_FINAL_PREFIX', 'v8-consulta'),
    ],

    'csv' => [
        'embed_bom' => (bool) env('V8_CSV_EMBED_BOM', true),
        'final_eol' => env('V8_CSV_FINAL_EOL', 'LF'),
    ],
];
