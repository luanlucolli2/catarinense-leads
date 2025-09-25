<?php

use Maatwebsite\Excel\Excel;
use PhpOffice\PhpSpreadsheet\Reader\Csv;

return [
    'exports' => [
        // FromQuery usa isso; para generator não afeta.
        'chunk_size'             => 1000,

        // Sem pré-cálculo de fórmulas (barato em memória/CPU).
        'pre_calculate_formulas' => false,

        // Evita preencher células vazias (menos cells em memória).
        'strict_null_comparison' => false,

        'csv' => [
            'delimiter'              => ',',
            'enclosure'              => '"',
            'line_ending'            => PHP_EOL,
            'use_bom'                => false,
            'include_separator_line' => false,
            'excel_compatibility'    => false,
            'output_encoding'        => '',
            'test_auto_detect'       => true,
        ],

        'properties' => [
            'creator'        => '',
            'lastModifiedBy' => '',
            'title'          => '',
            'description'    => '',
            'subject'        => '',
            'keywords'       => '',
            'category'       => '',
            'manager'        => '',
            'company'        => '',
        ],
    ],

    'imports' => [
        // Ler apenas dados (ignora estilos) → menos memória.
        'read_only'    => true,

        // Mantém linhas vazias (útil para consistência de contagem).
        'ignore_empty' => false,

        'heading_row'  => [
            'formatter' => 'slug',
        ],

        'csv' => [
            'delimiter'        => null,
            'enclosure'        => '"',
            'escape_character' => '\\',
            'contiguous'       => false,
            'input_encoding'   => Csv::GUESS_ENCODING,
        ],

        'properties' => [
            'creator'        => '',
            'lastModifiedBy' => '',
            'title'          => '',
            'description'    => '',
            'subject'        => '',
            'keywords'       => '',
            'category'       => '',
            'manager'        => '',
            'company'        => '',
        ],

        'cells' => [
            'middleware' => [
                // \Maatwebsite\Excel\Middleware\TrimCellValue::class,
                // \Maatwebsite\Excel\Middleware\ConvertEmptyCellValuesToNull::class,
            ],
        ],
    ],

    'extension_detector' => [
        'xlsx'     => Excel::XLSX,
        'xlsm'     => Excel::XLSX,
        'xltx'     => Excel::XLSX,
        'xltm'     => Excel::XLSX,
        'xls'      => Excel::XLS,
        'xlt'      => Excel::XLS,
        'ods'      => Excel::ODS,
        'ots'      => Excel::ODS,
        'slk'      => Excel::SLK,
        'xml'      => Excel::XML,
        'gnumeric' => Excel::GNUMERIC,
        'htm'      => Excel::HTML,
        'html'     => Excel::HTML,
        'csv'      => Excel::CSV,
        'tsv'      => Excel::TSV,
        'pdf'      => Excel::DOMPDF,
    ],

    'value_binder' => [
        // Mantém binding padrão (rápido). Conversões específicas ficam no export.
        'default' => Maatwebsite\Excel\DefaultValueBinder::class,
    ],

    // ===== cache de células: SEM illuminate =====
    'cache' => [
        // 'batch' grava em lotes quando atinge o limite abaixo.
        'driver'      => 'batch',

        // Limite em KB (~96 MB) antes de derramar para o storage tmp.
        // Bom equilíbrio para ~50k linhas mantendo o pico previsível.
        'batch'       => [
            'memory_limit' => 98304,
        ],

        // Não usar illuminate.
        'illuminate'  => [
            'store' => null,
        ],

        // TTL padrão de itens de cache (não crítico aqui).
        'default_ttl' => 10800,
    ],

    'transactions' => [
        'handler' => 'db',
        'db'      => [
            'connection' => null,
        ],
    ],

    'temporary_files' => [
        // Diretório local para arquivos temporários (montado em volume).
        'local_path'          => storage_path('framework/cache/laravel-excel'),

        'local_permissions'   => [
            // 'dir'  => 0755,
            // 'file' => 0644,
        ],

        // Mantém local (sem disco remoto).
        'remote_disk'         => null,
        'remote_prefix'       => null,

        // Não forçar resync remoto (não usado).
        'force_resync_remote' => null,
    ],
];
