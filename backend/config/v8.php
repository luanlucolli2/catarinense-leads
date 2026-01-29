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
        'min_interval_ms_phase1' => (int) env('V8_HTTP_MIN_INTERVAL_MS_PHASE1', 2000),
        'min_interval_ms_phase2' => (int) env('V8_HTTP_MIN_INTERVAL_MS_PHASE2', 10000),
        'rate_limit_sleep_seconds' => (int) env('V8_HTTP_429_SLEEP_SECONDS', 15),
    ],

    'job' => [
        'queue'                      => env('V8_JOB_QUEUE', 'v8'),
        'timeout_seconds'            => (int) env('V8_JOB_TIMEOUT', 115200),
        'status_max_attempts'        => (int) env('V8_STATUS_MAX_ATTEMPTS', 10),
        'status_retry_delay_seconds' => (int) env('V8_STATUS_RETRY_DELAY_SECONDS', 30),
        'status_round_delay_seconds' => (int) env('V8_STATUS_ROUND_DELAY_SECONDS', 20),
        'status_batch_limit'         => (int) env('V8_STATUS_BATCH_LIMIT', 50),
        'status_lookback_hours'      => (int) env('V8_STATUS_LOOKBACK_HOURS', 48),
        'status_lookback_existing_hours' => (int) env('V8_STATUS_LOOKBACK_EXISTING_HOURS', 168),
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
