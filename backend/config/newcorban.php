<?php

return [
    'base_url' => rtrim((string) env('NEWCORBAN_BASE_URL', env('NEWCORBAN_URL', '')), '/'),
    'api_token' => env('NEWCORBAN_API_TOKEN', ''),
    'timeout' => (int) env('NEWCORBAN_TIMEOUT', 15),

    'defaults' => [
        'seller_id' => env('NEWCORBAN_SELLER_ID', '3384'),
        'co_seller_id' => env('NEWCORBAN_CO_SELLER_ID'),
        'team_id' => env('NEWCORBAN_TEAM_ID', '287'),
        'franchise_id' => env('NEWCORBAN_FRANCHISE_ID'),
        'origin_id' => env('NEWCORBAN_ORIGIN_ID', '9243'),
    ],

    'products' => [
        'fgts' => [
            'product_id' => '7',
            'covenant_id' => '100000',
        ],
        'clt' => [
            'product_id' => '13',
            'covenant_id' => '54451',
        ],
    ],

    'banks' => [
        'mercantil' => [
            'bank_id' => '389',
            'promoter_id' => '411',
            'typing_login' => null,
        ],
        'presenca' => [
            'bank_id' => '3299',
            'promoter_id' => '411',
            'typing_login' => '42485740801_U4UN',
        ],
        'v8' => [
            'bank_id' => '935',
            'promoter_id' => '413',
            'typing_login' => 'karen@catarinensecredito.com.br',
        ],
        'facta' => [
            'bank_id' => '935',
            'promoter_id' => '411',
            'typing_login' => '20953',
        ],
        'hubcredito' => [
            'bank_id' => '2744',
            'promoter_id' => '4633',
            'typing_login' => '05395929940@9019',
        ],
        'pan' => [
            'bank_id' => '623',
            'promoter_id' => '411',
            'typing_login' => '11521981906_007528',
        ],
        'c6' => [
            'bank_id' => '626',
            'promoter_id' => '411',
            'typing_login' => '03805806086_000855',
        ],
        'soma' => [
            'bank_id' => '2560092',
            'promoter_id' => '3570',
            'typing_login' => 'soma_live_f34a9523608ed2c1',
            'omit_table_code' => true,
        ],
        'novo_saque' => [
            'bank_id' => '500001',
            'promoter_id' => '4412',
            'typing_login' => 'contatoia@catarinensecredito.com.br',
            'omit_table_code' => true,
        ],
    ],

    'catalogs' => [
        'banks' => '/banks',
        'promoters' => '/promoters',
        'products' => '/products',
        'covenants' => '/covenants',
        'teams' => '/teams',
        'proposal-origins' => '/proposal-origins',
        'franchises' => '/franchises',
        'tables' => '/tables',
    ],
];
