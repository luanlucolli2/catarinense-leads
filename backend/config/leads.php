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

