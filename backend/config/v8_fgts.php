<?php

return [
    'external_api' => [
        'base_url' => env('V8_FGTS_EXTERNAL_API_BASE_URL', 'https://apibot.catarinensecredito.com.br'),
        'email' => env('V8_FGTS_EXTERNAL_API_EMAIL'),
        'password' => env('V8_FGTS_EXTERNAL_API_PASSWORD'),
        'timeout' => (int) env('V8_FGTS_EXTERNAL_API_TIMEOUT', 30),
        'connect_timeout' => (int) env('V8_FGTS_EXTERNAL_API_CONNECT_TIMEOUT', 10),
    ],

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
        'min_interval_ms_phase1' => (int) env('V8_FGTS_HTTP_MIN_INTERVAL_MS_PHASE1', 10000),
        'min_interval_ms_fees' => (int) env('V8_FGTS_HTTP_MIN_INTERVAL_MS_FEES', 5000),
        'min_interval_ms_simulation' => (int) env('V8_FGTS_HTTP_MIN_INTERVAL_MS_SIMULATION', 5000),
        'rate_limit_sleep_seconds' => (int) env('V8_FGTS_HTTP_429_SLEEP_SECONDS', 15),
    ],

    'job' => [
        'queue' => env('V8_FGTS_JOB_QUEUE', env('FGTS_OFF_JOB_QUEUE', 'fgts')),
        'timeout_seconds' => (int) env('V8_FGTS_JOB_TIMEOUT', 21600),
        'prepare_insert_chunk' => (int) env('V8_FGTS_PREPARE_INSERT_CHUNK', 2000),
        'dedupe_in_memory_limit' => (int) env('V8_FGTS_DEDUPE_IN_MEMORY_LIMIT', 100000),
        'start_max_attempts' => (int) env('V8_FGTS_START_MAX_ATTEMPTS', 8),
        'start_retry_delay_seconds' => (int) env('V8_FGTS_START_RETRY_DELAY_SECONDS', 30),
        'polling_round_delay_seconds' => (int) env('V8_FGTS_POLLING_ROUND_DELAY_SECONDS', 20),
        'polling_timeout_seconds' => (int) env('V8_FGTS_POLLING_TIMEOUT_SECONDS', 900),
        'polling_max_rounds' => (int) env('V8_FGTS_POLLING_MAX_ROUNDS', 30),
        'selection_tolerance_seconds' => (int) env('V8_FGTS_SELECTION_TOLERANCE_SECONDS', 5),
        'phase2_search_limit' => (int) env('V8_FGTS_PHASE2_SEARCH_LIMIT', env('V8_FGTS_PHASE2_SEARCH_FALLBACK_LIMIT', 50)),
        'max_requests_per_run' => (int) env('V8_FGTS_MAX_REQUESTS_PER_RUN', 8),
        'max_runtime_seconds' => (int) env('V8_FGTS_MAX_RUNTIME_SECONDS', 90),
        'batch_lock_seconds' => (int) env('V8_FGTS_BATCH_LOCK_SECONDS', 180),
        'schedule_min_delay_seconds' => (int) env('V8_FGTS_SCHEDULE_MIN_DELAY_SECONDS', 1),
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

    'logging' => [
        'batch_summary' => (bool) env('V8_FGTS_LOG_BATCH_SUMMARY', true),
        'phase2_requests' => (bool) env('V8_FGTS_LOG_PHASE2_REQUESTS', false),
        'phase2_success_responses' => (bool) env('V8_FGTS_LOG_PHASE2_SUCCESS_RESPONSES', false),
        'phase2_error_responses' => (bool) env('V8_FGTS_LOG_PHASE2_ERROR_RESPONSES', true),
        'phase2_pending_requeues' => (bool) env('V8_FGTS_LOG_PHASE2_PENDING_REQUEUES', false),
        'store_phase2_snapshots' => (bool) env('V8_FGTS_STORE_PHASE2_SNAPSHOTS', false),
    ],
];
