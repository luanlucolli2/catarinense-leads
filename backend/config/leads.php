<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Leads - Paginacao
    |--------------------------------------------------------------------------
    */
    'pagination' => [
        'per_page_default' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Leads - Importacao
    |--------------------------------------------------------------------------
    */
    'import' => [
        'mimes' => ['xlsx', 'xls'],
        'types' => ['cadastral', 'higienizacao', 'clt'],
        'default_origin' => 'Upload Padrão',

        'lock' => [
            'name' => 'imports_mutex',
            'wait_seconds' => 5,
        ],

        'queue' => 'imports',

        'storage' => [
            'disk' => 'local',
            'directory' => 'imports',
            'fallback_disks' => ['public'],
        ],

        'in_progress_statuses' => ['pendente', 'em_progresso'],

        'chunk_size' => 1000,
        'db_batch_size' => (int) env('LEADS_IMPORT_DB_BATCH_SIZE', 500),
        'max_errors_per_job' => (int) env('LEADS_IMPORT_MAX_ERRORS_PER_JOB', 5000),
        'vendor_cache_max' => (int) env('LEADS_IMPORT_VENDOR_CACHE_MAX', 5000),
        'pre_count_total_rows' => filter_var(env('LEADS_IMPORT_PRE_COUNT_TOTAL_ROWS', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Leads - Filtros
    |--------------------------------------------------------------------------
    */
    'filters' => [
        'cache_ttl_seconds' => (int) env('LEADS_FILTERS_CACHE_TTL_SECONDS', 60),
        'cache_key' => (string) env('LEADS_FILTERS_CACHE_KEY', 'leads:filters:v1'),

        'mass_filter' => [
            'names_max_terms' => (int) env('LEADS_FILTERS_NAMES_MAX_TERMS', 120),
            'names_chunk_size' => (int) env('LEADS_FILTERS_NAMES_CHUNK_SIZE', 20),
            'cpf_max_terms' => (int) env('LEADS_FILTERS_CPF_MAX_TERMS', 5000),
            'phones_max_terms' => (int) env('LEADS_FILTERS_PHONES_MAX_TERMS', 5000),
            'default_max_terms' => (int) env('LEADS_FILTERS_DEFAULT_MAX_TERMS', 1000),
        ],

        'birth_month' => [
            'year_start' => (int) env('LEADS_BIRTH_MONTH_YEAR_START', 1900),
            'year_end' => (int) env('LEADS_BIRTH_MONTH_YEAR_END', (int) date('Y')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Leads - Exportacao
    |--------------------------------------------------------------------------
    */
    'export' => [
        'queue' => env('PREVIEW_JOB_QUEUE', 'reports'),
        'ttl_seconds' => (int) env('LEADS_EXPORT_TTL_SECONDS', 6 * 3600),
        'grace_seconds' => (int) env('LEADS_EXPORT_GRACE_SECONDS', 600),
        'timeout_seconds' => 7200,
        'cleanup_timeout_seconds' => 300,
        'memory_limit' => env('LEADS_EXPORT_MEMORY', '256M'),

        'storage' => [
            'disk' => env('LEADS_EXPORT_DISK', 'local'),
            'directory' => trim((string) env('LEADS_EXPORT_DIR', 'leads-exports'), '/'),
            'filename_prefix' => 'leads_export',
            'fallback_filename' => 'leads_export.csv',
            'excel_temp_local_path' => 'framework/cache/excel-temp',
        ],

        'csv' => [
            'delimiter' => env('LEADS_EXPORT_CSV_DELIMITER', ';'),
            'enclosure' => env('LEADS_EXPORT_CSV_ENCLOSURE', '"'),
            'bom' => (bool) env('LEADS_EXPORT_CSV_BOM', true),
        ],

        'query' => [
            'chunk_size' => (int) env('LEADS_EXPORT_CHUNK', 800),
            'flush_every' => (int) env('LEADS_EXPORT_FLUSH_EVERY', 2000),
        ],

        'cache' => [
            'key_prefix' => 'leads_export',
            'deleted_status_ttl_cap_seconds' => 600,
        ],

        'stream' => [
            'content_type' => 'text/csv; charset=UTF-8',
            'accel_buffering' => 'no',
        ],
    ],
];
