<?php

return [
    /*
    |--------------------------------------------------------------------------
    | FGTS OFF – API (FGTS Base Offline)
    |--------------------------------------------------------------------------
    */
    'base_url'   => env('FACTA_OFF_BASE_URL', 'https://fgtsoff.facta.com.br'),
    'basic_auth' => env('FACTA_OFF_BASIC_AUTH'),
    'token_ttl'  => (int) env('FACTA_OFF_TOKEN_TTL_SECONDS', 3600),

    'token' => [
        'lock_ttl'  => (int) env('FACTA_OFF_TOKEN_LOCK_TTL', 10),
        'lock_wait' => (int) env('FACTA_OFF_TOKEN_LOCK_WAIT', 5),
        'ttl_skew'  => (int) env('FACTA_OFF_TOKEN_TTL_SKEW', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP (timeouts e retry)
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout'         => (int) env('FGTS_OFF_HTTP_TIMEOUT', 10),
        'connect_timeout' => (int) env('FGTS_OFF_HTTP_CONNECT_TIMEOUT', 5),
        'retry'           => (int) env('FGTS_OFF_HTTP_RETRY', 1),
        'retry_delay_ms'  => (int) env('FGTS_OFF_HTTP_RETRY_DELAY_MS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Concorrência (pool)
    |--------------------------------------------------------------------------
    */
    'pool' => [
        'concurrency' => (int) env('FGTS_OFF_POOL_CONCURRENCY', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limit (token bucket global)
    |--------------------------------------------------------------------------
    */
    'rate' => [
        'tokens_per_second' => (int) env('FGTS_OFF_RATE_TOKENS_PER_SECOND', 2),
        'burst'             => (int) env('FGTS_OFF_RATE_BURST', 2),
        'bucket_lock_ttl'   => (int) env('FGTS_OFF_BUCKET_LOCK_TTL', 2),
        'bucket_lock_wait'  => (int) env('FGTS_OFF_BUCKET_LOCK_WAIT', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | JOB (teimosinha / backoff / janela) – consultas FGTS OFF
    |--------------------------------------------------------------------------
    */
    'job' => [
        'queue'                 => env('FGTS_OFF_JOB_QUEUE', 'fgts'),
        'timeout_seconds'       => (int) env('FGTS_OFF_JOB_TIMEOUT', 18000),
        'max_attempts'          => (int) env('FGTS_OFF_CONSULT_MAX_ATTEMPTS', 5),
        'retry_delay_seconds'   => (int) env('FGTS_OFF_CONSULT_RETRY_DELAY_SECONDS', 30),
        'chunk'                 => (int) env('FGTS_OFF_HTTP_CHUNK', 6),
        'min_chunk'             => (int) env('FGTS_OFF_HTTP_MIN_CHUNK', 2),
        'retry_after_max'       => (int) env('FGTS_OFF_HTTP_RETRY_AFTER_MAX', 120),
        'preview_interval_seconds' => (int) env('FGTS_OFF_PREVIEW_INTERVAL_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | PRÉVIA – fila dedicada (relatórios/prévias on-demand)
    |--------------------------------------------------------------------------
    */
    'preview' => [
        'queue' => env('PREVIEW_JOB_QUEUE', 'reports'),
    ],

    /*
    |--------------------------------------------------------------------------
    | STORAGE (relatórios e prévias)
    |--------------------------------------------------------------------------
    */
    'storage' => [
        // Disco do Filesystem (ex.: public, local, s3)
        'reports_disk' => env('FGTS_OFF_REPORTS_DISK', 'local'),

        'dir_reports'  => env('FGTS_OFF_REPORTS_DIR', 'fgts-off-reports'),
        'dir_previews' => env('FGTS_OFF_PREVIEWS_DIR', 'fgts-off-previews'),
        'dir_spool'    => env('FGTS_OFF_SPOOL_DIR', 'fgts-off-spool'),

        'final_prefix'  => env('FGTS_OFF_FINAL_PREFIX', 'fgts-offline'),
        'preview_suffix'=> env('FGTS_OFF_PREVIEW_SUFFIX', 'preview'),
    ],

    /*
    |--------------------------------------------------------------------------
    | CSV (normalização do arquivo final)
    |--------------------------------------------------------------------------
    | embed_bom: inclui BOM UTF-8 no final (excel-friendly).
    | final_eol: 'LF' (Unix) ou 'CRLF' (Windows). Default: LF.
    */
    'csv' => [
        'embed_bom' => (bool) env('FGTS_OFF_CSV_EMBED_BOM', true),
        'final_eol' => env('FGTS_OFF_CSV_FINAL_EOL', 'LF'), // 'LF' | 'CRLF'
    ],
];
