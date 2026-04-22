<?php

return [
    /*
    |--------------------------------------------------------------------------
    | UY3 Webhook Secret
    |--------------------------------------------------------------------------
    | Shared secret para autenticar o POST do parceiro UY3 para sua API.
    */
    'webhook_secret' => env('UY3_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | UY3 - Exportação CSV
    |--------------------------------------------------------------------------
    */
    'export' => [
        'queue' => env('UY3_EXPORT_QUEUE', 'reports'),
        'ttl_seconds' => (int) env('UY3_EXPORT_TTL_SECONDS', 6 * 3600),
        'grace_seconds' => (int) env('UY3_EXPORT_GRACE_SECONDS', 600),
        'timeout_seconds' => (int) env('UY3_EXPORT_TIMEOUT_SECONDS', 3600),
        'cleanup_timeout_seconds' => (int) env('UY3_EXPORT_CLEANUP_TIMEOUT_SECONDS', 300),
        'memory_limit' => env('UY3_EXPORT_MEMORY', '256M'),

        'storage' => [
            'disk' => env('UY3_EXPORT_DISK', 'local'),
            'directory' => trim((string) env('UY3_EXPORT_DIR', 'uy3-exports'), '/'),
            'filename_prefix' => (string) env('UY3_EXPORT_FILENAME_PREFIX', 'uy3_leads_clt_export'),
            'fallback_filename' => (string) env('UY3_EXPORT_FALLBACK_FILENAME', 'uy3_leads_clt_export.csv'),
            'csv_temp_local_path' => (string) env('UY3_EXPORT_TEMP_PATH', 'framework/cache/csv-temp'),
        ],

        'csv' => [
            'delimiter' => env('UY3_EXPORT_CSV_DELIMITER', ';'),
            'enclosure' => env('UY3_EXPORT_CSV_ENCLOSURE', '"'),
            'bom' => (bool) env('UY3_EXPORT_CSV_BOM', true),
        ],

        'query' => [
            'flush_every' => (int) env('UY3_EXPORT_FLUSH_EVERY', 2000),
        ],

        'cache' => [
            'key_prefix' => (string) env('UY3_EXPORT_CACHE_KEY_PREFIX', 'uy3_export'),
            'deleted_status_ttl_cap_seconds' => (int) env('UY3_EXPORT_DELETED_STATUS_TTL_CAP_SECONDS', 600),
        ],

        'stream' => [
            'content_type' => (string) env('UY3_EXPORT_CONTENT_TYPE', 'text/csv; charset=UTF-8'),
            'accel_buffering' => (string) env('UY3_EXPORT_ACCEL_BUFFERING', 'no'),
        ],
    ],
];
