// config/cltfacta.php
<?php

return [

    'api' => [
        'base_url'        => env('FACTA_BASE_URL', 'https://webservice.facta.com.br'),
        'basic_auth'      => env('FACTA_BASIC_AUTH'),
        'token_ttl'       => (int) env('FACTA_TOKEN_TTL_SECONDS', 3300),
        'token_lock_ttl'  => (int) env('FACTA_TOKEN_LOCK_TTL', 10),
        'token_lock_wait' => (int) env('FACTA_TOKEN_LOCK_WAIT', 5),
        'token_ttl_skew'  => (int) env('FACTA_TOKEN_TTL_SKEW', 30),
    ],

    'http' => [
        'timeout'                 => (int) env('CLT_HTTP_TIMEOUT', 15),
        'connect_timeout'         => (int) env('CLT_HTTP_CONNECT_TIMEOUT', 10),
        'retry'                   => (int) env('CLT_HTTP_RETRY', 1),
        'retry_delay_ms'          => (int) env('CLT_HTTP_RETRY_DELAY_MS', 200),
        'second_try'              => (bool) env('CLT_HTTP_SECOND_TRY', true),
        'second_timeout'          => (int) env('CLT_HTTP_TIMEOUT_SECOND', 10),
        'second_connect_timeout'  => (int) env('CLT_HTTP_CONNECT_TIMEOUT_SECOND', 5),
    ],

    // ===== OFFLINE (CLT-OFF) =====
    'clt_off' => [
        'api' => [
            'base_url'        => env('CLT_OFF_BASE_URL', ''),
            'basic_auth'      => env('CLT_OFF_BASIC_AUTH'),
            'token_ttl'       => (int) env('CLT_OFF_TOKEN_TTL_SECONDS', 3600),
            'token_lock_ttl'  => (int) env('CLT_OFF_TOKEN_LOCK_TTL', 10),
            'token_lock_wait' => (int) env('CLT_OFF_TOKEN_LOCK_WAIT', 5),
            'token_ttl_skew'  => (int) env('CLT_OFF_TOKEN_TTL_SKEW', 30),
        ],
        'http' => [
            'timeout'                 => (int) env('CLT_OFF_HTTP_TIMEOUT', 10),
            'connect_timeout'         => (int) env('CLT_OFF_HTTP_CONNECT_TIMEOUT', 5),
            'retry'                   => (int) env('CLT_OFF_HTTP_RETRY', 1),
            'retry_delay_ms'          => (int) env('CLT_OFF_HTTP_RETRY_DELAY_MS', 200),
            'second_try'              => (bool) env('CLT_OFF_HTTP_SECOND_TRY', false),
            'second_timeout'          => (int) env('CLT_OFF_HTTP_TIMEOUT_SECOND', 8),
            'second_connect_timeout'  => (int) env('CLT_OFF_HTTP_CONNECT_TIMEOUT_SECOND', 4),
        ],
        'storage' => [
            'final_prefix' => env('CLT_OFF_FINAL_PREFIX', 'clt-offline'),
        ],
    ],

    // ===== JOB (CLT online e offline) =====
    'job' => [
        'queue'               => env('CLT_JOB_QUEUE', 'clt'),
        'timeout_seconds'     => (int) env('CLT_JOB_TIMEOUT', 115200),
        'max_attempts'        => (int) env('CLT_CONSULT_MAX_ATTEMPTS', 5),
        'retry_delay_seconds' => (int) env('CLT_CONSULT_RETRY_DELAY_SECONDS', 60),
        'chunk'               => (int) env('CLT_HTTP_CHUNK', 20),
        'min_chunk'           => (int) env('CLT_HTTP_MIN_CHUNK', 5),
        'retry_after_max'     => (int) env('CLT_HTTP_RETRY_AFTER_MAX', 120),

        'chunk_delay_ms'      => (int) env('CLT_JOB_CHUNK_DELAY_MS', 200),
        'subchunk'            => (int) env('CLT_JOB_SUBCHUNK', 5),
        'subchunk_delay_ms'   => (int) env('CLT_JOB_SUBCHUNK_DELAY_MS', 120),
    ],

    // onde salvar relatórios / prévias / spool
    'storage' => [
        'reports_disk' => env('CLT_REPORTS_DISK', 'local'),
        'dir_reports'  => env('CLT_REPORTS_DIR', 'clt-reports'),
        'dir_previews' => env('CLT_PREVIEWS_DIR', 'clt-previews'),
        'dir_spool'    => env('CLT_SPOOL_DIR', 'clt-spool'),
        'final_prefix' => env('CLT_FINAL_PREFIX', 'clt-consulta'),
        'clt_off_final_prefix' => env('CLT_OFF_FINAL_PREFIX', 'clt-offline'),
    ],

    // fila usada para gerar prévias (reutiliza PREVIEW_JOB_QUEUE)
    'preview' => [
        'queue' => env('PREVIEW_JOB_QUEUE', 'reports'),
    ],

];
