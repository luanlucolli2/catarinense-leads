<?php

return [
    'base_url'    => env('FACTA_BASE_URL', 'https://webservice.facta.com.br'),
    'basic_auth'  => env('FACTA_BASIC_AUTH'),
    'token_ttl'   => (int) env('FACTA_TOKEN_TTL_SECONDS', 3300),
];
