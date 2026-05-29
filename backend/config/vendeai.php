<?php

return [
    'webhook_token' => env('VENDEAI_WEBHOOK_TOKEN', ''),

    'export' => [
        'queue' => env('VENDEAI_EXPORT_QUEUE', env('PREVIEW_JOB_QUEUE', 'reports')),
        'ttl_seconds' => (int) env('VENDEAI_EXPORT_TTL_SECONDS', 6 * 3600),
        'grace_seconds' => (int) env('VENDEAI_EXPORT_GRACE_SECONDS', 600),
        'timeout_seconds' => (int) env('VENDEAI_EXPORT_TIMEOUT_SECONDS', 3600),
        'cleanup_timeout_seconds' => (int) env('VENDEAI_EXPORT_CLEANUP_TIMEOUT_SECONDS', 300),
        'memory_limit' => env('VENDEAI_EXPORT_MEMORY', '256M'),

        'storage' => [
            'disk' => env('VENDEAI_EXPORT_DISK', 'local'),
            'directory' => trim((string) env('VENDEAI_EXPORT_DIR', 'vendeai-exports'), '/'),
            'fallback_filename' => (string) env('VENDEAI_EXPORT_FALLBACK_FILENAME', 'vendeai_export.csv'),
        ],

        'csv' => [
            'delimiter' => env('VENDEAI_EXPORT_CSV_DELIMITER', ';'),
            'enclosure' => env('VENDEAI_EXPORT_CSV_ENCLOSURE', '"'),
            'bom' => (bool) env('VENDEAI_EXPORT_CSV_BOM', true),
        ],

        'query' => [
            'flush_every' => (int) env('VENDEAI_EXPORT_FLUSH_EVERY', 2000),
        ],

        'cache' => [
            'key_prefix' => (string) env('VENDEAI_EXPORT_CACHE_KEY_PREFIX', 'vendeai_export'),
            'deleted_status_ttl_cap_seconds' => (int) env('VENDEAI_EXPORT_DELETED_STATUS_TTL_CAP_SECONDS', 600),
        ],

        'stream' => [
            'content_type' => (string) env('VENDEAI_EXPORT_CONTENT_TYPE', 'text/csv; charset=UTF-8'),
            'accel_buffering' => (string) env('VENDEAI_EXPORT_ACCEL_BUFFERING', 'no'),
        ],
    ],
];
