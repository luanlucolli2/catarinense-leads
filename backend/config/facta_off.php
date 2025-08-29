<?php

return [
    // Base URL do serviço FGTS Base Offline (pode apontar para homol ou prod)
    'base_url'   => env('FACTA_OFF_BASE_URL', 'https://fgtsoff.facta.com.br'),

    // Credenciais em Basic Auth (valor deve ser o user:pass em BASE64)
    // Ex.: base64_encode('usuario:senha') → "dXN1YXJpbzpzZW5oYQ=="
    'basic_auth' => env('FACTA_OFF_BASIC_AUTH'),

    // TTL (segundos) do token Bearer retornado pelo endpoint /gera-token
    'token_ttl'  => (int) env('FACTA_OFF_TOKEN_TTL_SECONDS', 3600),
];
