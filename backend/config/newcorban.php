<?php

return [
    'base_url' => rtrim((string) env('NEWCORBAN_URL', ''), '/'),
    'username' => env('NEWCORBAN_USERNAME', ''),
    'password' => env('NEWCORBAN_PASSWORD', ''),
    'empresa' => env('NEWCORBAN_EMPRESA', ''),
    'timeout' => (int) env('NEWCORBAN_TIMEOUT', 15),
];
