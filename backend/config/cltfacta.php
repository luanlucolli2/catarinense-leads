<?php

return [

    // ===== FACTA ONLINE =====
    'api' => [
        'base_url'        => env('FACTA_BASE_URL', 'https://webservice.facta.com.br'),
        'basic_auth'      => env('FACTA_BASIC_AUTH'),
        'token_ttl'       => (int) env('FACTA_TOKEN_TTL_SECONDS', 3300),
        'token_lock_ttl'  => (int) env('FACTA_TOKEN_LOCK_TTL', 10),
        'token_lock_wait' => (int) env('FACTA_TOKEN_LOCK_WAIT', 5),
        'token_ttl_skew'  => (int) env('FACTA_TOKEN_TTL_SKEW', 30),
        'pre_auth_averbador' => env('FACTA_PRE_AUTH_AVERBADOR', '10010'),
        'pre_auth_nome' => env('FACTA_PRE_AUTH_NOME', 'slkjhdsjkha asdkjhd iou'),
        'pre_auth_tipo_envio' => env('FACTA_PRE_AUTH_TIPO_ENVIO', 'WHATSAPP'),
        'pre_auth_phone_attempts' => (int) env('FACTA_PRE_AUTH_PHONE_ATTEMPTS', 8),
        // cache da pré-autorização por CPF para reduzir chamadas repetidas entre tentativas
        'pre_auth_cache_ttl' => (int) env('FACTA_PRE_AUTH_CACHE_TTL_SECONDS', 1800),
    ],

    'http' => [
        'timeout'                 => (int) env('CLT_HTTP_TIMEOUT', 15),
        'connect_timeout'         => (int) env('CLT_HTTP_CONNECT_TIMEOUT', 10),
        'retry'                   => (int) env('CLT_HTTP_RETRY', 1),
        'retry_delay_ms'          => (int) env('CLT_HTTP_RETRY_DELAY_MS', 200),
        'second_try'              => (bool) env('CLT_HTTP_SECOND_TRY', true),
        'second_timeout'          => (int) env('CLT_HTTP_TIMEOUT_SECOND', 10),
        'second_connect_timeout'  => (int) env('CLT_HTTP_CONNECT_TIMEOUT_SECOND', 5),
        'rate_limit_immediate_retry' => (bool) env('CLT_HTTP_RATE_LIMIT_IMMEDIATE_RETRY', true),
        'rate_limit_max_retries'  => (int) env('CLT_HTTP_RATE_LIMIT_MAX_RETRIES', 1),
        'rate_limit_default_pause_seconds' => (int) env('CLT_HTTP_RATE_LIMIT_DEFAULT_PAUSE_SECONDS', 3),
        'rate_limit_pause_cap_seconds' => (int) env('CLT_HTTP_RATE_LIMIT_PAUSE_CAP_SECONDS', 30),
    ],

    // ===== CRÉDITO TRABALHADOR (continuação online) =====
    'credit_worker' => [
        // ETAPA 4: /proposta/operacoes-disponiveis
        'produto'       => env('FACTA_CLT_CREDITO_PRODUTO', 'D'),
        'tipo_operacao' => env('FACTA_CLT_CREDITO_TIPO_OPERACAO', '13'),
        'averbador'     => env('FACTA_CLT_CREDITO_AVERBADOR', '10010'),
        'convenio'      => env('FACTA_CLT_CREDITO_CONVENIO', '3'),
        'opcao_valor'   => env('FACTA_CLT_CREDITO_OPCAO_VALOR', '2'),
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
            'timeout'         => (int) env('CLT_OFF_HTTP_TIMEOUT', 10),
            'connect_timeout' => (int) env('CLT_OFF_HTTP_CONNECT_TIMEOUT', 5),
            'retry'           => (int) env('CLT_OFF_HTTP_RETRY', 1),
            'retry_delay_ms'  => (int) env('CLT_OFF_HTTP_RETRY_DELAY_MS', 200),
        ],
    ],

    // ===== JOB =====
    'job' => [
        // novas filas distintas
        'queue_online'        => env('CLT_ON_JOB_QUEUE', 'clt-on'),
        'queue_offline'       => env('CLT_OFF_JOB_QUEUE', 'clt-off'),

        // legado (não usado quando enviamos para filas distintas, mas mantido como fallback)
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
        // intervalo mínimo para reconsultar status do job no banco (reduz polling excessivo)
        'status_check_interval_ms' => (int) env('CLT_JOB_STATUS_CHECK_INTERVAL_MS', 1000),
        // Flush incremental dos buffers internos do job para reduzir pico de RAM.
        'rows_buffer_flush'   => (int) env('CLT_JOB_ROWS_BUFFER_FLUSH', 300),
        'snap_buffer_flush'   => (int) env('CLT_JOB_SNAP_BUFFER_FLUSH', 300),
    ],

    // ===== QUEUE DE FINALIZAÇÃO/PREVIEW =====
    'preview' => [
        'queue' => env('CLT_PREVIEW_QUEUE', 'reports'),
        // Evita depender de outro worker para promover o CSV final quando o spool está em disco local.
        'inline' => (bool) env('CLT_PREVIEW_INLINE', true),
    ],

    // ===== STORAGE =====
    'storage' => [
        'reports_disk' => env('CLT_REPORTS_DISK', 'local'),
        'dir_reports'  => env('CLT_REPORTS_DIR', 'clt-reports'),
        'dir_spool'    => env('CLT_SPOOL_DIR', 'clt-spool'),
        'final_prefix' => env('CLT_FINAL_PREFIX', 'clt-consulta'),
    ],

    // ===== CSV (BOM/EOL) =====
    'csv' => [
        'embed_bom' => (bool) env('CLT_CSV_EMBED_BOM', true),
        'final_eol' => env('CLT_CSV_FINAL_EOL', 'LF'), // 'LF' ou 'CRLF'
    ],

    // ===== LOG =====
    'logging' => [
        // Chave mestre de logs do módulo CLT (online + offline)
        'enabled' => (bool) env('CLT_LOG_ENABLED', true),
        // Logs detalhados de resposta FACTA (/solicita e /autoriza)
        'facta_log_responses' => (bool) env('CLT_FACTA_LOG_RESPONSES', true),
        // Em produção pequena (1vCPU), logs de sucesso geram I/O desnecessário.
        // Mantemos por padrão apenas respostas com erro (>=400).
        'facta_log_success_responses' => (bool) env('CLT_FACTA_LOG_SUCCESS_RESPONSES', false),
        // Log de performance por chunk do job (verbose).
        'chunk_perf_debug' => (bool) env('CLT_CHUNK_PERF_DEBUG', false),
        // Log periódico de flush de spool (verbose).
        'flush_progress_log' => (bool) env('CLT_FLUSH_PROGRESS_LOG', false),
        // Log do ciclo de backoff cooperativo do job.
        'backoff_log' => (bool) env('CLT_BACKOFF_LOG', false),
    ],
];
