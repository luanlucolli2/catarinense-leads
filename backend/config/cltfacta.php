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
        'token_retry_max_attempts' => (int) env('FACTA_TOKEN_RETRY_MAX_ATTEMPTS', 8),
        'token_retry_base_delay_ms' => (int) env('FACTA_TOKEN_RETRY_BASE_DELAY_MS', 1000),
        'token_retry_max_delay_ms' => (int) env('FACTA_TOKEN_RETRY_MAX_DELAY_MS', 30000),
        'pre_auth_averbador' => env('FACTA_PRE_AUTH_AVERBADOR', '10010'),
        'pre_auth_nome' => env('FACTA_PRE_AUTH_NOME', 'slkjhdsjkha asdkjhd iou'),
        'pre_auth_tipo_envio' => env('FACTA_PRE_AUTH_TIPO_ENVIO', 'WHATSAPP'),
        'pre_auth_phone_attempts' => (int) env('FACTA_PRE_AUTH_PHONE_ATTEMPTS', 8),
        // cache da pré-autorização por CPF para reduzir chamadas repetidas entre tentativas
        'pre_auth_cache_ttl' => (int) env('FACTA_PRE_AUTH_CACHE_TTL_SECONDS', 1800),
        // validade persistente no banco para evitar nova autorização em consultas futuras
        'pre_auth_persist_ttl_days' => (int) env('FACTA_PRE_AUTH_PERSIST_TTL_DAYS', 30),
        // flush de persistência em lote para reduzir roundtrips no banco
        'pre_auth_persist_batch_size' => (int) env('FACTA_PRE_AUTH_PERSIST_BATCH_SIZE', 100),
        // cooldown único entre a última pré-autorização do lote e o início das consultas
        'pre_auth_post_cooldown_ms' => (int) env('FACTA_PRE_AUTH_POST_COOLDOWN_MS', 15000),
    ],

    'http' => [
        'timeout'                 => (int) env('CLT_HTTP_TIMEOUT', 15),
        'connect_timeout'         => (int) env('CLT_HTTP_CONNECT_TIMEOUT', 10),
        'retry'                   => (int) env('CLT_HTTP_RETRY', 1),
        'retry_delay_ms'          => (int) env('CLT_HTTP_RETRY_DELAY_MS', 200),
        'transient_retry_delay_ms' => (int) env('CLT_HTTP_TRANSIENT_RETRY_DELAY_MS', 3000),
        'transient_pause_seconds' => (int) env('CLT_HTTP_TRANSIENT_PAUSE_SECONDS', 3),
        'second_try'              => (bool) env('CLT_HTTP_SECOND_TRY', true),
        'second_timeout'          => (int) env('CLT_HTTP_TIMEOUT_SECOND', 10),
        'second_connect_timeout'  => (int) env('CLT_HTTP_CONNECT_TIMEOUT_SECOND', 5),
        'rate_limit_immediate_retry' => (bool) env('CLT_HTTP_RATE_LIMIT_IMMEDIATE_RETRY', true),
        'rate_limit_max_retries'  => (int) env('CLT_HTTP_RATE_LIMIT_MAX_RETRIES', 1),
        'rate_limit_default_pause_seconds' => (int) env('CLT_HTTP_RATE_LIMIT_DEFAULT_PAUSE_SECONDS', 3),
        'rate_limit_pause_cap_seconds' => (int) env('CLT_HTTP_RATE_LIMIT_PAUSE_CAP_SECONDS', 30),
        // Limite global para toda a base FACTA (200 rpm informado pelo provedor).
        'global_rate_limit_enabled' => (bool) env('CLT_HTTP_GLOBAL_RATE_LIMIT_ENABLED', true),
        'global_rate_limit_rps' => (int) env('CLT_HTTP_GLOBAL_RATE_LIMIT_RPS', 4),
        'global_rate_limit_rpm' => (int) env('CLT_HTTP_GLOBAL_RATE_LIMIT_RPM', 180),
        'global_rate_limit_sleep_ms' => (int) env('CLT_HTTP_GLOBAL_RATE_LIMIT_SLEEP_MS', 80),
        // Janela máxima por pool para evitar burst acima do permitido.
        'autoriza_pool_window' => (int) env('CLT_HTTP_AUTORIZA_POOL_WINDOW', 4),
        'policy_pool_window' => (int) env('CLT_HTTP_POLICY_POOL_WINDOW', 4),
    ],

    // ===== CRÉDITO TRABALHADOR (continuação online) =====
    'credit_worker' => [
        // ETAPA 4: /proposta/operacoes-disponiveis
        'produto'       => env('FACTA_CLT_CREDITO_PRODUTO', 'D'),
        'tipo_operacao' => env('FACTA_CLT_CREDITO_TIPO_OPERACAO', '13'),
        'averbador'     => env('FACTA_CLT_CREDITO_AVERBADOR', '10010'),
        'convenio'      => env('FACTA_CLT_CREDITO_CONVENIO', '3'),
        'opcao_valor'   => env('FACTA_CLT_CREDITO_OPCAO_VALOR', '2'),
        // Quantas tabelas da política de crédito processar em paralelo por CPF elegível.
        'policy_batch_size' => (int) env('FACTA_CLT_CREDITO_POLICY_BATCH_SIZE', 4),
        // Rodadas máximas da fase 2 (varredura do CSV para política de crédito).
        'phase2_max_attempts' => (int) env('CLT_CREDIT_PHASE2_MAX_ATTEMPTS', 3),
        // Intervalo entre rodadas da fase 2 quando ainda há pendências retriables.
        'phase2_retry_delay_seconds' => (int) env('CLT_CREDIT_PHASE2_RETRY_DELAY_SECONDS', 30),
        // Retry imediato por linha retriable antes de delegar para a próxima rodada.
        'phase2_immediate_retry_delay_ms' => (int) env('CLT_CREDIT_PHASE2_IMMEDIATE_RETRY_DELAY_MS', 3000),
        // Checkpoint de progresso da fase 2 (máx. frequência de update no banco).
        'phase2_progress_flush_interval_ms' => (int) env('CLT_CREDIT_PHASE2_PROGRESS_FLUSH_INTERVAL_MS', 20000),
        'phase2_progress_flush_every_rows' => (int) env('CLT_CREDIT_PHASE2_PROGRESS_FLUSH_EVERY_ROWS', 200),
        // Persistência incremental da fase 2 para prévia (arquivo delta, sem reescrever spool completo).
        'phase2_delta_flush_interval_ms' => (int) env('CLT_CREDIT_PHASE2_DELTA_FLUSH_INTERVAL_MS', 2000),
        'phase2_delta_flush_every_rows' => (int) env('CLT_CREDIT_PHASE2_DELTA_FLUSH_EVERY_ROWS', 20),
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
            // alinhado ao .env (antes não estava mapeado)
            'second_try'              => (bool) env('CLT_OFF_HTTP_SECOND_TRY', false),
            'second_timeout'          => (int) env('CLT_OFF_HTTP_TIMEOUT_SECOND', 8),
            'second_connect_timeout'  => (int) env('CLT_OFF_HTTP_CONNECT_TIMEOUT_SECOND', 4),
        ],
        // se usado pelo service
        'min_interval_ms' => (int) env('CLT_OFF_MIN_INTERVAL_MS', 0),
    ],

    // ===== JOB =====
    'job' => [
        // filas distintas
        'queue_online'        => env('CLT_ON_JOB_QUEUE', 'clt-on'),
        'queue_offline'       => env('CLT_OFF_JOB_QUEUE', 'clt-off'),

        'timeout_seconds'     => (int) env('CLT_JOB_TIMEOUT', 259200),
        'max_attempts'        => (int) env('CLT_CONSULT_MAX_ATTEMPTS', 5),
        'retry_delay_seconds' => (int) env('CLT_CONSULT_RETRY_DELAY_SECONDS', 60),
        'chunk'               => (int) env('CLT_HTTP_CHUNK', 24),
        'min_chunk'           => (int) env('CLT_HTTP_MIN_CHUNK', 8),
        'retry_after_max'     => (int) env('CLT_HTTP_RETRY_AFTER_MAX', 120),
        'chunk_delay_ms'      => (int) env('CLT_JOB_CHUNK_DELAY_MS', 80),
        'subchunk'            => (int) env('CLT_JOB_SUBCHUNK', 24),
        'subchunk_delay_ms'   => (int) env('CLT_JOB_SUBCHUNK_DELAY_MS', 0),
        // intervalo mínimo para reconsultar status do job no banco (reduz polling excessivo)
        'status_check_interval_ms' => (int) env('CLT_JOB_STATUS_CHECK_INTERVAL_MS', 1000),
        // Checkpoint de progresso (fase 1) no banco; fase 2 respeita no mínimo este intervalo.
        'progress_flush_interval_seconds' => (int) env('CLT_JOB_PROGRESS_FLUSH_INTERVAL_SECONDS', 20),
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
        // opcional (para futuros recursos de prévia em disco dedicado)
        'dir_previews' => env('CLT_PREVIEWS_DIR', 'clt-previews'),
    ],

    // ===== CSV (BOM/EOL) =====
    'csv' => [
        'embed_bom' => (bool) env('CLT_CSV_EMBED_BOM', true),
        'final_eol' => env('CLT_CSV_FINAL_EOL', 'LF'),
    ],

    // ===== LOG =====
    'logging' => [
        // Chave mestre de logs do módulo CLT (online + offline)
        'enabled' => (bool) env('CLT_LOG_ENABLED', true),
        // Logs detalhados de resposta FACTA (/solicita e /autoriza)
        'facta_log_responses' => (bool) env('CLT_FACTA_LOG_RESPONSES', true),
        // Em produção pequena (1vCPU), logs de sucesso geram I/O desnecessário.
        // Mantemos por padrão apenas respostas com erro (>=400).
        'facta_log_success_responses' => (bool) env('CLT_FACTA_LOG_SUCCESS_RESPONSES', true),
        // Contadores HTTP por job (auditoria por endpoint sem depender de parse de logs).
        'facta_job_http_counters_enabled' => (bool) env('CLT_FACTA_JOB_HTTP_COUNTERS_ENABLED', true),
        'facta_job_http_counters_flush_every' => (int) env('CLT_FACTA_JOB_HTTP_COUNTERS_FLUSH_EVERY', 120),
        'facta_job_http_counters_flush_interval_ms' => (int) env('CLT_FACTA_JOB_HTTP_COUNTERS_FLUSH_INTERVAL_MS', 10000),
        // Log de performance por chunk do job (verbose).
        'chunk_perf_debug' => (bool) env('CLT_CHUNK_PERF_DEBUG', false),
        // Log periódico de flush de spool (verbose).
        'flush_progress_log' => (bool) env('CLT_FLUSH_PROGRESS_LOG', false),
        // Log do ciclo de backoff cooperativo do job.
        'backoff_log' => (bool) env('CLT_BACKOFF_LOG', false),
    ],
];
