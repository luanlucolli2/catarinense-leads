<?php

return [
    'bff' => [
        'base_url' => env('V8_BFF_BASE_URL', 'https://bff.v8sistema.com'),
        'provider' => env('V8_FGTS_PROVIDER', 'bms'),
        'target_amount' => (int) env('V8_FGTS_TARGET_AMOUNT', 0),
        'fee_label' => env('V8_FGTS_FEE_LABEL', 'normal'),
        'fee_cache_ttl_seconds' => (int) env('V8_FGTS_FEE_CACHE_TTL', 300),
    ],

    'http' => [
        'timeout' => (int) env('V8_FGTS_HTTP_TIMEOUT', 15),
        'connect_timeout' => (int) env('V8_FGTS_HTTP_CONNECT_TIMEOUT', 10),
        'retry' => (int) env('V8_FGTS_HTTP_RETRY', 1),
        'retry_delay_ms' => (int) env('V8_FGTS_HTTP_RETRY_DELAY_MS', 200),
        'min_interval_ms_phase1' => (int) env('V8_FGTS_HTTP_MIN_INTERVAL_MS_PHASE1', 200),
        'min_interval_ms_polling' => (int) env('V8_FGTS_HTTP_MIN_INTERVAL_MS_POLLING', 10000),
        'min_interval_ms_fees' => (int) env('V8_FGTS_HTTP_MIN_INTERVAL_MS_FEES', 2000),
        'min_interval_ms_simulation' => (int) env('V8_FGTS_HTTP_MIN_INTERVAL_MS_SIMULATION', 2000),
        'rate_limit_sleep_seconds' => (int) env('V8_FGTS_HTTP_429_SLEEP_SECONDS', 15),
    ],

    'job' => [
        'queue' => env('V8_FGTS_JOB_QUEUE', 'v8-fgts'),
        'timeout_seconds' => (int) env('V8_FGTS_JOB_TIMEOUT', 21600),
        'dedupe_block_size' => (int) env('V8_FGTS_DEDUPE_BLOCK_SIZE', 5000),
        'start_buffer' => (int) env('V8_FGTS_START_BUFFER', 12),
        'polling_buffer' => (int) env('V8_FGTS_POLLING_BUFFER', 80),
        'start_max_attempts' => (int) env('V8_FGTS_START_MAX_ATTEMPTS', 3),
        'start_retry_delay_seconds' => (int) env('V8_FGTS_START_RETRY_DELAY_SECONDS', 30),
        'polling_round_delay_seconds' => (int) env('V8_FGTS_POLLING_ROUND_DELAY_SECONDS', 20),
        'polling_timeout_seconds' => (int) env('V8_FGTS_POLLING_TIMEOUT_SECONDS', 900),
        'polling_max_rounds' => (int) env('V8_FGTS_POLLING_MAX_ROUNDS', 30),
        'selection_tolerance_seconds' => (int) env('V8_FGTS_SELECTION_TOLERANCE_SECONDS', 5),
        'flush_rows' => (int) env('V8_FGTS_FLUSH_ROWS', 4000),
        'flush_seconds' => (int) env('V8_FGTS_FLUSH_SECONDS', 5),
        'flush_bytes_step' => (int) env('V8_FGTS_FLUSH_BYTES_STEP', 262144),
    ],

    'preview' => [
        'queue' => env('V8_FGTS_PREVIEW_QUEUE', 'reports'),
    ],

    'storage' => [
        'reports_disk' => env('V8_FGTS_REPORTS_DISK', 'local'),
        'dir_reports' => env('V8_FGTS_REPORTS_DIR', 'v8-fgts-reports'),
        'dir_spool' => env('V8_FGTS_SPOOL_DIR', 'v8-fgts-spool'),
        'final_prefix' => env('V8_FGTS_FINAL_PREFIX', 'v8-fgts-consulta'),
    ],

    'csv' => [
        'embed_bom' => (bool) env('V8_FGTS_CSV_EMBED_BOM', true),
        'final_eol' => env('V8_FGTS_CSV_FINAL_EOL', 'LF'),
    ],
];
