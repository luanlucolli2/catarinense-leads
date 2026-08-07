<?php

return [
    'queue' => env('SOMA_CLT_REPORT_QUEUE', 'reports'),
    'storage' => [
        'reports_disk' => env('SOMA_CLT_REPORTS_DISK', 'local'),
        'dir_reports' => env('SOMA_CLT_REPORTS_DIR', 'soma-clt-reports'),
        'final_prefix' => env('SOMA_CLT_FINAL_PREFIX', 'soma-clt-consulta'),
    ],
];
