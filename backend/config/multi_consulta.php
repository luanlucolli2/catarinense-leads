<?php

return [
    'base_url' => rtrim((string) env('MULTI_CONSULTA_BASE_URL', ''), '/'),
    'email' => (string) env('MULTI_CONSULTA_EMAIL', ''),
    'password' => (string) env('MULTI_CONSULTA_PASSWORD', ''),
    'timeout' => (int) env('MULTI_CONSULTA_HTTP_TIMEOUT', 15),
    'connect_timeout' => (int) env('MULTI_CONSULTA_HTTP_CONNECT_TIMEOUT', 5),
    'token_skew_seconds' => (int) env('MULTI_CONSULTA_TOKEN_SKEW_SECONDS', 60),
];
