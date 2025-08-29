<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FACTA API (CLT)
    |--------------------------------------------------------------------------
    */
    'api' => [
        // Base da API (homolog por padrão)
        'base_url'        => env('FACTA_BASE_URL', 'https://webservice-homol.facta.com.br'),

        // Credencial "Basic ..." (base64 user:pass)
        'basic_auth'      => env('FACTA_BASIC_AUTH'),

        // TTL do token e parâmetros do lock para evitar thundering herd
        'token_ttl'       => (int) env('FACTA_TOKEN_TTL_SECONDS', 3300),
        'token_lock_ttl'  => (int) env('FACTA_TOKEN_LOCK_TTL', 10),
        'token_lock_wait' => (int) env('FACTA_TOKEN_LOCK_WAIT', 5),

        // Redução do TTL efetivo (skew) para evitar expiração simultânea
        'token_ttl_skew'  => (int) env('FACTA_TOKEN_TTL_SKEW', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP (timeouts, retry e segunda rodada em pool)
    |--------------------------------------------------------------------------
    */
    'http' => [
        // Primeira rodada
        'timeout'               => (int) env('CLT_HTTP_TIMEOUT', 15),
        'connect_timeout'       => (int) env('CLT_HTTP_CONNECT_TIMEOUT', 10),
        'retry'                 => (int) env('CLT_HTTP_RETRY', 1),
        'retry_delay_ms'        => (int) env('CLT_HTTP_RETRY_DELAY_MS', 200),

        // Segunda rodada (pool) para respostas ausentes
        'second_try'            => (bool) env('CLT_HTTP_SECOND_TRY', true),
        'second_timeout'        => (int) env('CLT_HTTP_TIMEOUT_SECOND', 10),
        'second_connect_timeout'=> (int) env('CLT_HTTP_CONNECT_TIMEOUT_SECOND', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | JOB (backoff, concorrência e janelas)
    |--------------------------------------------------------------------------
    */
    'job' => [
        // Timeout total do job (padrão atual: 18000s = 5h)
        'timeout_seconds'        => (int) env('CLT_JOB_TIMEOUT', 18000),

        // Teimosinha
        'max_attempts'           => (int) env('CLT_CONSULT_MAX_ATTEMPTS', 5),
        'retry_delay_seconds'    => (int) env('CLT_CONSULT_RETRY_DELAY_SECONDS', 60),

        // Concorrência
        'chunk'                  => (int) env('CLT_HTTP_CHUNK', 20),
        'min_chunk'              => (int) env('CLT_HTTP_MIN_CHUNK', 5),

        // Cap de Retry-After
        'retry_after_max'        => (int) env('CLT_HTTP_RETRY_AFTER_MAX', 120),

        // Intervalo para gerar PRÉVIA (o código usa 60s como constante)
        'preview_interval_seconds'=> (int) env('CLT_PREVIEW_INTERVAL_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | STORAGE (relatórios e prévias)
    |--------------------------------------------------------------------------
    */
    'storage' => [
        // Disco do Filesystem (ex.: public, s3, etc.)
        'reports_disk' => env('CLT_REPORTS_DISK', 'public'),

        // Diretórios e nomes (eram strings fixas no código; expostos aqui
        // para facilitar customização futura, mantendo os mesmos padrões)
        'dir_reports'  => env('CLT_REPORTS_DIR', 'clt-reports'),
        'dir_previews' => env('CLT_PREVIEWS_DIR', 'clt-previews'),

        // Padrões de nome de arquivo (opcional, mantendo os atuais)
        'final_prefix'   => env('CLT_FINAL_PREFIX', 'clt-consulta'),
        'preview_suffix' => env('CLT_PREVIEW_SUFFIX', 'preview'),
    ],

];
