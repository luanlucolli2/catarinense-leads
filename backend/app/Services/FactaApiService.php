<?php

namespace App\Services;

use App\Support\CltLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use Throwable;

class FactaApiService
{
    /** API */
    private string $baseUrl;
    private ?string $basicAuth;
    private int $tokenTtl;
    private int $tokenLockTtl;
    private int $tokenLockWait;
    private int $tokenTtlSkew;

    /** HTTP (1ª rodada) */
    private int $httpTimeout;
    private int $httpConnectTimeout;
    private int $httpRetry;
    private int $httpRetryDelayMs;

    /** HTTP (2ª rodada opcional em pool) */
    private bool $httpSecondTry;
    private int $httpSecondTimeout;
    private int $httpSecondConnectTimeout;
    private bool $httpRateLimitImmediateRetry;
    private int $httpRateLimitMaxRetries;
    private int $httpRateLimitDefaultPauseSeconds;
    private int $httpRateLimitPauseCapSeconds;
    private bool $logFactaResponses;

    /** Pré-autorização (CLT online) */
    private string $preAuthAverbador;
    private string $preAuthNome;
    private string $preAuthTipoEnvio;
    private int $preAuthPhoneAttempts;

    /** DDDs válidos do Brasil (ANATEL) */
    private const VALID_BR_DDDS = [
        '11', '12', '13', '14', '15', '16', '17', '18', '19',
        '21', '22', '24',
        '27', '28',
        '31', '32', '33', '34', '35', '37', '38',
        '41', '42', '43', '44', '45', '46',
        '47', '48', '49',
        '51', '53', '54', '55',
        '61', '62', '63', '64', '65', '66', '67', '68', '69',
        '71', '73', '74', '75', '77', '79',
        '81', '82', '83', '84', '85', '86', '87', '88', '89',
        '91', '92', '93', '94', '95', '96', '97', '98', '99',
    ];

    /** Loga headers e trecho do corpo em respostas 403, com redaction e truncamento. */
    private function logForbidden(HttpResponse $resp, ?string $cpf = null): void
    {
        try {
            // Headers (array<string, array<string>>)
            $all = $resp->headers();
            $safe = [];
            foreach ($all as $k => $vals) {
                $key = (string) $k;
                // Redação de itens sensíveis
                if (stripos($key, 'authorization') === 0 || stripos($key, 'cookie') === 0 || stripos($key, 'set-cookie') === 0) {
                    $safe[$key] = ['REDACTED'];
                } else {
                    $safe[$key] = array_map('strval', (array) $vals);
                }
            }

            // Corpo (trecho)
            $body = (string) $resp->body();
            $snippet = $this->truncate($body, 4000);

            CltLog::warning(
                '[FACTA] 403 Forbidden'
                . ($cpf ? " (cpf={$cpf})" : '')
                . ' — headers=' . json_encode($safe, JSON_UNESCAPED_UNICODE)
                . ' body_snippet=' . $snippet
            );
        } catch (\Throwable $e) {
            CltLog::warning('[FACTA] Falha ao logar 403: ' . $e->getMessage());
        }
    }


    public function __construct()
    {
        $api = (array) config('cltfacta.api', []);
        $http = (array) config('cltfacta.http', []);

        // API
        $this->baseUrl = rtrim((string) ($api['base_url'] ?? ''), '/');
        $this->basicAuth = $api['basic_auth'] ?? null;
        $this->tokenTtl = (int) ($api['token_ttl'] ?? 3300);
        $this->tokenLockTtl = (int) ($api['token_lock_ttl'] ?? 10);
        $this->tokenLockWait = (int) ($api['token_lock_wait'] ?? 5);
        $this->tokenTtlSkew = (int) ($api['token_ttl_skew'] ?? 30);

        // HTTP (1ª)
        $this->httpTimeout = (int) ($http['timeout'] ?? 15);
        $this->httpConnectTimeout = (int) ($http['connect_timeout'] ?? 10);
        $this->httpRetry = (int) ($http['retry'] ?? 1);
        $this->httpRetryDelayMs = (int) ($http['retry_delay_ms'] ?? 200);

        // HTTP (2ª)
        $this->httpSecondTry = (bool) ($http['second_try'] ?? true);
        $this->httpSecondTimeout = (int) ($http['second_timeout'] ?? 10);
        $this->httpSecondConnectTimeout = (int) ($http['second_connect_timeout'] ?? 5);
        $this->httpRateLimitImmediateRetry = (bool) ($http['rate_limit_immediate_retry'] ?? true);
        $this->httpRateLimitMaxRetries = max(0, (int) ($http['rate_limit_max_retries'] ?? 1));
        $this->httpRateLimitDefaultPauseSeconds = max(1, (int) ($http['rate_limit_default_pause_seconds'] ?? 3));
        $this->httpRateLimitPauseCapSeconds = max(1, (int) ($http['rate_limit_pause_cap_seconds'] ?? 30));
        $this->logFactaResponses = (bool) config('cltfacta.logging.facta_log_responses', true);

        // Pré-autorização obrigatória antes do autoriza-consulta
        $this->preAuthAverbador = (string) ($api['pre_auth_averbador'] ?? '10010');
        $this->preAuthNome = (string) ($api['pre_auth_nome'] ?? 'slkjhdsjkha asdkjhd iou');
        $this->preAuthTipoEnvio = (string) ($api['pre_auth_tipo_envio'] ?? 'WHATSAPP');
        $this->preAuthPhoneAttempts = max(1, (int) ($api['pre_auth_phone_attempts'] ?? 8));
    }

    /**
     * Obtém token com lock para evitar thundering herd
     */
 public function getToken(): ?string
{
    // cache quente
    $cached = Cache::get('facta_token');
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    $lock = Cache::lock('facta_token_lock', $this->tokenLockTtl);
    $lock->block($this->tokenLockWait);

    try {
        // re-check após adquirir o lock
        $cached = Cache::get('facta_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if (!$this->basicAuth) {
            throw new \RuntimeException('FACTA token error: credencial BASIC ausente (FACTA_BASIC_AUTH)');
        }

        // 1ª chamada
        $resp = Http::withHeaders([
                'Authorization' => 'Basic '.$this->basicAuth,
                'Accept'        => 'application/json',
            ])
            ->timeout(max(1, $this->httpTimeout))
            ->connectTimeout(max(1, $this->httpConnectTimeout))
            ->retry(
                max(0, $this->httpRetry),
                max(0, $this->httpRetryDelayMs),
                fn ($e, $request) =>
                    $e instanceof ConnectionException
                    || optional($request->response())->status() === 429
                    || optional($request->response())->serverError()
            )
            ->get($this->baseUrl.'/gera-token');

        if ($resp->status() === 403) {
            $this->logForbidden($resp, null);
        }

        if (!$resp->ok()) {
            $msg = $this->responseMessage($resp); // pega message/mensagem ou resume HTML
            throw new \RuntimeException("FACTA token error: {$msg}");
        }

        // Tenta decodificar JSON
        $json = $resp->json();

        if (!is_array($json)) {
            // corpo 200 porém não-JSON (HTML, texto, etc.)
            $msg = $this->responseMessage($resp);
            throw new \RuntimeException("FACTA token error: {$msg}");
        }

        // Alguns backends usam 'message' em vez de 'mensagem'
        $erroFlag = (bool) ($json['erro'] ?? false);
        if ($erroFlag) {
            $msg = trim((string) ($json['mensagem'] ?? $json['message'] ?? 'Erro no /gera-token'));
            if ($msg === '') {
                $msg = $this->responseMessage($resp);
            }
            throw new \RuntimeException("FACTA token error: {$msg}");
        }

        $token = $json['token'] ?? null;
        if (!is_string($token) || $token === '') {
            // 200 sem 'token' → trata como falha com mensagem decente
            $msg = trim((string) ($json['mensagem'] ?? $json['message'] ?? 'token ausente na resposta'));
            throw new \RuntimeException("FACTA token error: {$msg}");
        }

        // Cacheia com skew
        $ttl = max(30, $this->tokenTtl - $this->tokenTtlSkew);
        Cache::put('facta_token', $token, $ttl);

        return $token;
    } finally {
        optional($lock)->release();
    }
}


    /**
     * Consulta unitária (fallback/compat)
     */
    public function autorizaConsulta(string $cpf): array
    {
        $cpf = preg_replace('/\D+/', '', $cpf ?? '');
        if (strlen($cpf) !== 11) {
            return [
                'ok' => false,
                'mensagem' => 'CPF inválido',
                'vinculos' => null,
                'retriable' => false,
                'not_found' => false,
                'http_status' => null,
                'retry_after' => null,
            ];
        }

        try {
            $token = $this->getToken();
            if (!is_string($token) || $token === '') {
                throw new \RuntimeException('Token FACTA ausente');
            }

            $preAuth = $this->solicitaAutorizacaoConsulta($cpf, $token);
            if (!($preAuth['ok'] ?? false)) {
                return $this->errorResult(
                    (string) ($preAuth['mensagem'] ?? 'Falha na pré-autorização'),
                    (bool) ($preAuth['retriable'] ?? false),
                    isset($preAuth['http_status']) ? (int) $preAuth['http_status'] : null,
                    $preAuth['retry_after'] ?? null
                );
            }

            $doRequest = function () use ($cpf, &$token) {
                return Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ])
                    ->timeout($this->httpTimeout)
                    ->connectTimeout($this->httpConnectTimeout)
                    ->retry(max(0, $this->httpRetry), max(0, $this->httpRetryDelayMs), fn($e) => $e instanceof ConnectionException)
                    ->get($this->baseUrl . '/consignado-trabalhador/autoriza-consulta', [
                        'cpf' => $cpf,
                    ]);
            };

            $resp = $doRequest();
            $this->logAutorizaConsultaResponse($resp, $cpf, 'initial', 1);

            if ($resp->status() === 403) {
                $this->logForbidden($resp, $cpf);
            }

            if ($resp->status() === 401) {
                Cache::forget('facta_token');
                $token = $this->getToken();
                if (!is_string($token) || $token === '') {
                    throw new \RuntimeException('Token FACTA ausente após refresh');
                }
                $resp = $doRequest();
                $this->logAutorizaConsultaResponse($resp, $cpf, 'after_401_refresh', 1);
                if ($resp->status() === 403) {
                    $this->logForbidden($resp, $cpf);
                }
            }

            if ($this->httpRateLimitImmediateRetry && $this->httpRateLimitMaxRetries > 0) {
                for ($rlAttempt = 1; $resp->status() === 429 && $rlAttempt <= $this->httpRateLimitMaxRetries; $rlAttempt++) {
                    $this->sleepBeforeImmediate429Retry(
                        'autoriza-consulta',
                        $this->getRetryAfterSeconds($resp),
                        $cpf,
                        $rlAttempt
                    );

                    $resp = $doRequest();
                    $this->logAutorizaConsultaResponse($resp, $cpf, 'after_429_backoff', $rlAttempt);

                    if ($resp->status() === 403) {
                        $this->logForbidden($resp, $cpf);
                    }

                    if ($resp->status() === 401) {
                        Cache::forget('facta_token');
                        $token = $this->getToken();
                        if (!is_string($token) || $token === '') {
                            throw new \RuntimeException('Token FACTA ausente após refresh');
                        }
                        $resp = $doRequest();
                        $this->logAutorizaConsultaResponse($resp, $cpf, 'after_429_backoff_401_refresh', $rlAttempt);
                        if ($resp->status() === 403) {
                            $this->logForbidden($resp, $cpf);
                        }
                    }
                }
            }

            return $this->parseAutorizaResponse($resp);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'mensagem' => 'Exceção: ' . $e->getMessage(),
                'vinculos' => null,
                'retriable' => true,
                'not_found' => false,
                'http_status' => null,
                'retry_after' => null,
            ];
        }
    }


    /**
     * Consulta em lote concorrente; retorna [cpf => resultado]
     */
    public function autorizaConsultaLote(array $cpfs): array
    {
        $cpfs = array_values(array_filter(array_map(function ($c) {
            $c = preg_replace('/\D+/', '', (string) $c);
            return strlen($c) === 11 ? $c : null;
        }, $cpfs)));

        if (empty($cpfs)) {
            return [];
        }

        $out = [];

        // ✅ PROTEGE a geração do token
        try {
            $token = $this->getToken();
            if (!is_string($token) || $token === '') {
                throw new \RuntimeException('Token FACTA ausente');
            }
        } catch (\Throwable $e) {
            $msg = 'Falha ao gerar token: ' . $e->getMessage();
            foreach ($cpfs as $cpf) {
                $out[$cpf] = $this->errorResult($msg, true);
            }
            return $out;
        }

        // Pré-autorização obrigatória (endpoint /solicita-autorizacao-consulta)
        $authorizedCpfs = [];
        foreach ($cpfs as $cpf) {
            $preAuth = $this->solicitaAutorizacaoConsulta($cpf, $token);
            if (!($preAuth['ok'] ?? false)) {
                $out[$cpf] = $this->errorResult(
                    (string) ($preAuth['mensagem'] ?? 'Falha na pré-autorização'),
                    (bool) ($preAuth['retriable'] ?? false),
                    isset($preAuth['http_status']) ? (int) $preAuth['http_status'] : null,
                    $preAuth['retry_after'] ?? null
                );
                continue;
            }
            $authorizedCpfs[] = $cpf;
        }

        if (empty($authorizedCpfs)) {
            return $out;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];
        $url = $this->baseUrl . '/consignado-trabalhador/autoriza-consulta';
        /** @var array<string,HttpResponse> $responses */
        $responses = [];

        // -------- 1ª TENTATIVA (POOL) --------
        try {
            $responses = $this->requestAutorizaPool(
                $authorizedCpfs,
                $headers,
                $url,
                $this->httpTimeout,
                $this->httpConnectTimeout,
                'initial_pool',
                1
            );
        } catch (Throwable $e) {
            // Pool inteiro falhou → devolve retriable (o Job vai retriar)
            foreach ($authorizedCpfs as $cpf) {
                $out[$cpf] = $this->errorResult('Sem resposta (pool falhou)', true);
            }
            return $out;
        }

        // -------- 401 → renova token apenas dos necessários --------
        $needRetry401 = [];
        foreach ($responses as $cpf => $resp) {
            if ($resp instanceof HttpResponse && $resp->status() === 401) {
                $needRetry401[] = $cpf;
            }
        }
        if (!empty($needRetry401)) {
            Cache::forget('facta_token');
            try {
                $token2 = $this->getToken();
                if (!is_string($token2) || $token2 === '') {
                    throw new \RuntimeException('Token FACTA ausente após refresh');
                }
            } catch (Throwable $e) {
                foreach ($needRetry401 as $cpf) {
                    unset($responses[$cpf]);
                }
                $token2 = null;
            }
            if (is_string($token2) && $token2 !== '') {
                $token = $token2;
                $headers = [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ];
            }
            $headers2 = [
                'Authorization' => 'Bearer ' . ($token2 ?? ''),
                'Accept' => 'application/json',
            ];
            if (!empty($needRetry401) && is_string($token2) && $token2 !== '') {
                try {
                    $retryResponses = $this->requestAutorizaPool(
                        $needRetry401,
                        $headers2,
                        $url,
                        $this->httpTimeout,
                        $this->httpConnectTimeout,
                        'retry_401_pool',
                        1
                    );
                    foreach ($retryResponses as $cpf => $resp) {
                        $responses[$cpf] = $resp;
                    }
                } catch (Throwable $e) {
                    // mantém as 401 (o Job tentará de novo depois)
                }
            }
        }

        // -------- 2ª TENTATIVA (POOL) para MISSING --------
        $missing = [];
        foreach ($authorizedCpfs as $cpf) {
            if (!isset($responses[$cpf]) || !($responses[$cpf] instanceof HttpResponse)) {
                $missing[] = $cpf;
            }
        }
        if (!empty($missing) && $this->httpSecondTry) {
            try {
                $retry2 = $this->requestAutorizaPool(
                    $missing,
                    $headers,
                    $url,
                    $this->httpSecondTimeout,
                    $this->httpSecondConnectTimeout,
                    'missing_pool_retry2',
                    1
                );
                foreach ($retry2 as $cpf => $resp) {
                    $responses[$cpf] = $resp;
                }
            } catch (Throwable $e) {
                // segunda tentativa falhou → deixa missing (Job vai retriar depois)
            }
        }

        // -------- 429 IMEDIATO (POOL) --------
        if ($this->httpRateLimitImmediateRetry && $this->httpRateLimitMaxRetries > 0) {
            for ($rlAttempt = 1; $rlAttempt <= $this->httpRateLimitMaxRetries; $rlAttempt++) {
                $retry429Cpfs = [];
                $retryAfterMax = null;

                foreach ($authorizedCpfs as $cpf) {
                    $resp = $responses[$cpf] ?? null;
                    if (!$resp instanceof HttpResponse || $resp->status() !== 429) {
                        continue;
                    }

                    $retry429Cpfs[] = $cpf;
                    $retryAfter = $this->getRetryAfterSeconds($resp);
                    if ($retryAfter !== null) {
                        $retryAfterMax = $retryAfterMax === null ? $retryAfter : max($retryAfterMax, $retryAfter);
                    }
                }

                if (empty($retry429Cpfs)) {
                    break;
                }

                $this->sleepBeforeImmediate429Retry(
                    'autoriza-consulta',
                    $retryAfterMax,
                    null,
                    $rlAttempt,
                    count($retry429Cpfs)
                );

                try {
                    $retry429Responses = $this->requestAutorizaPool(
                        $retry429Cpfs,
                        $headers,
                        $url,
                        $this->httpSecondTimeout,
                        $this->httpSecondConnectTimeout,
                        'retry_429_pool',
                        $rlAttempt
                    );
                } catch (Throwable $e) {
                    break;
                }

                $retry401After429 = [];
                foreach ($retry429Responses as $cpf => $resp) {
                    if ($resp instanceof HttpResponse && $resp->status() === 401) {
                        $retry401After429[] = $cpf;
                    }
                }

                if (!empty($retry401After429)) {
                    Cache::forget('facta_token');
                    try {
                        $token3 = $this->getToken();
                        if (is_string($token3) && $token3 !== '') {
                            $token = $token3;
                            $headers = [
                                'Authorization' => 'Bearer ' . $token,
                                'Accept' => 'application/json',
                            ];

                            $retry401Responses = $this->requestAutorizaPool(
                                $retry401After429,
                                $headers,
                                $url,
                                $this->httpSecondTimeout,
                                $this->httpSecondConnectTimeout,
                                'retry_401_after_429_pool',
                                $rlAttempt
                            );

                            foreach ($retry401Responses as $cpf => $resp) {
                                $retry429Responses[$cpf] = $resp;
                            }
                        }
                    } catch (Throwable) {
                        // mantém resposta atual desses CPFs
                    }
                }

                foreach ($retry429Responses as $cpf => $resp) {
                    $responses[$cpf] = $resp;
                }
            }
        }

        // -------- Monta saída --------
        foreach ($authorizedCpfs as $cpf) {
            $resp = $responses[$cpf] ?? null;
            if (!$resp instanceof HttpResponse) {
                $out[$cpf] = $this->errorResult('Sem resposta do serviço', true);
                continue;
            }

            // 👉 LOG 403 com headers + corpo (por CPF)
            if ($resp->status() === 403) {
                $this->logForbidden($resp, $cpf);
            }

            $out[$cpf] = $this->parseAutorizaResponse($resp);
        }

        return $out;
    }


    /** --------- Helpers --------- */

    /**
     * @return array<string,HttpResponse>
     */
    private function requestAutorizaPool(
        array $cpfs,
        array $headers,
        string $url,
        int $timeout,
        int $connectTimeout,
        string $stage = 'pool',
        int $attempt = 1
    ): array {
        if (empty($cpfs)) {
            return [];
        }

        $responses = Http::pool(function (Pool $pool) use ($cpfs, $headers, $url, $timeout, $connectTimeout) {
            $reqs = [];
            foreach ($cpfs as $cpf) {
                $reqs[] = $pool->as($cpf)
                    ->withHeaders($headers)
                    ->timeout($timeout)
                    ->connectTimeout($connectTimeout)
                    ->retry(max(0, $this->httpRetry), max(0, $this->httpRetryDelayMs), fn($e) => $e instanceof ConnectionException)
                    ->get($url, ['cpf' => $cpf]);
            }
            return $reqs;
        });

        if ($this->logFactaResponses) {
            foreach ($responses as $cpf => $resp) {
                if ($resp instanceof HttpResponse) {
                    $this->logAutorizaConsultaResponse($resp, (string) $cpf, $stage, $attempt);
                }
            }
        }

        return $responses;
    }

    private function sleepBeforeImmediate429Retry(
        string $endpoint,
        ?int $retryAfterSeconds,
        ?string $cpf,
        int $attempt,
        ?int $batchSize = null
    ): void {
        $base = $retryAfterSeconds !== null
            ? max(1, $retryAfterSeconds)
            : $this->httpRateLimitDefaultPauseSeconds;

        $base = min($base, $this->httpRateLimitPauseCapSeconds);

        $jitterMax = max(1, (int) ceil($base * 0.15));
        $jitter = random_int(0, $jitterMax);
        $sleepSecs = min($this->httpRateLimitPauseCapSeconds, $base + $jitter);

        CltLog::warning('[FACTA] 429 immediate backoff', [
            'endpoint' => $endpoint,
            'cpf' => $cpf,
            'attempt' => $attempt,
            'batch_size' => $batchSize,
            'retry_after' => $retryAfterSeconds,
            'sleep_seconds' => $sleepSecs,
        ]);

        if ($sleepSecs > 0) {
            sleep($sleepSecs);
        }
    }

    // App\Services\FactaApiService.php

    private function errorResult(string $mensagem, bool $retriable, ?int $httpStatus = null, ?int $retryAfter = null): array
    {
        return [
            'ok' => false,
            'mensagem' => $mensagem,
            'vinculos' => null,
            'retriable' => $retriable,
            'not_found' => false,
            'http_status' => $httpStatus,
            'retry_after' => $retryAfter,
        ];
    }

    private function solicitaAutorizacaoConsulta(string $cpf, string &$token): array
    {
        $maxAttempts = max(1, $this->preAuthPhoneAttempts);
        $maxRateLimitRetries = $this->httpRateLimitImmediateRetry ? $this->httpRateLimitMaxRetries : 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $celular = $this->generateRandomCellular();

            $rateLimitAttempt = 0;
            while (true) {
                try {
                    $resp = $this->postSolicitaAutorizacaoConsulta($cpf, $token, $celular);
                    $this->logSolicitaAutorizacaoResponse($resp, $cpf, $celular, $attempt, 'initial');

                    if ($resp->status() === 403) {
                        $this->logForbidden($resp, $cpf);
                    }

                    if ($resp->status() === 401) {
                        Cache::forget('facta_token');
                        $token = $this->getToken();
                        if (!is_string($token) || $token === '') {
                            throw new \RuntimeException('Token FACTA ausente após refresh');
                        }

                        $resp = $this->postSolicitaAutorizacaoConsulta($cpf, $token, $celular);
                        $this->logSolicitaAutorizacaoResponse($resp, $cpf, $celular, $attempt, 'after_401_refresh');
                        if ($resp->status() === 403) {
                            $this->logForbidden($resp, $cpf);
                        }
                    }
                } catch (Throwable $e) {
                    return [
                        'ok' => false,
                        'mensagem' => 'Pré-autorização: Exceção: ' . $e->getMessage(),
                        'retriable' => true,
                        'http_status' => null,
                        'retry_after' => null,
                    ];
                }

                if ($resp->status() === 429 && $rateLimitAttempt < $maxRateLimitRetries) {
                    $rateLimitAttempt++;
                    $this->sleepBeforeImmediate429Retry(
                        'solicita-autorizacao-consulta',
                        $this->getRetryAfterSeconds($resp),
                        $cpf,
                        $rateLimitAttempt
                    );
                    continue;
                }

                break;
            }

            $status = $resp->status();
            $retryAfter = $this->getRetryAfterSeconds($resp);

            if (!$resp->ok()) {
                $mensagem = $this->responseMessage($resp);

                $looksHtml = false;
                try {
                    $body = (string) $resp->body();
                    $looksHtml = ($body !== '') && $this->looksLikeHtml($body);
                } catch (Throwable) {
                    // ignore
                }

                $retriable = in_array($status, [401, 403, 408, 429], true) || $status >= 500 || $looksHtml;

                return [
                    'ok' => false,
                    'mensagem' => 'Pré-autorização: ' . ($mensagem !== '' ? $mensagem : "HTTP {$status}"),
                    'retriable' => $retriable,
                    'http_status' => $status,
                    'retry_after' => $retryAfter,
                ];
            }

            $json = $resp->json();
            if (!is_array($json)) {
                return [
                    'ok' => false,
                    'mensagem' => 'Pré-autorização: Resposta inválida da FACTA',
                    'retriable' => true,
                    'http_status' => $status,
                    'retry_after' => $retryAfter,
                ];
            }

            $payload = $this->normalizeSolicitaAutorizacaoPayload($json);
            if (!is_array($payload)) {
                return [
                    'ok' => false,
                    'mensagem' => 'Pré-autorização: Resposta inválida da FACTA',
                    'retriable' => true,
                    'http_status' => $status,
                    'retry_after' => $retryAfter,
                ];
            }

            $mensagem = trim((string) ($payload['mensagem'] ?? $payload['message'] ?? ''));
            if ($this->isTokenValidoSemAutorizacaoMessage($mensagem)) {
                return [
                    'ok' => true,
                    'mensagem' => $mensagem,
                    'retriable' => false,
                    'http_status' => 200,
                    'retry_after' => null,
                ];
            }

            if ($this->isTelefoneJaInformadoMessage($mensagem)) {
                if ($attempt < $maxAttempts) {
                    continue;
                }

                return [
                    'ok' => false,
                    'mensagem' => 'Pré-autorização: Telefone já informado para outro cpf! (limite de tentativas atingido)',
                    'retriable' => false,
                    'http_status' => $status,
                    'retry_after' => $retryAfter,
                ];
            }

            if ($this->isDddInvalidoMessage($mensagem)) {
                if ($attempt < $maxAttempts) {
                    continue;
                }

                return [
                    'ok' => false,
                    'mensagem' => 'Pré-autorização: celular sem DDD válido (limite de tentativas atingido)',
                    'retriable' => false,
                    'http_status' => $status,
                    'retry_after' => $retryAfter,
                ];
            }

            // Regra de negócio: qualquer outra mensagem nesta etapa é falha terminal do CPF.
            return [
                'ok' => false,
                'mensagem' => 'Pré-autorização: ' . ($mensagem !== '' ? $mensagem : 'Falha na pré-autorização'),
                'retriable' => false,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        return [
            'ok' => false,
            'mensagem' => 'Pré-autorização: Falha ao obter telefone válido',
            'retriable' => false,
            'http_status' => null,
            'retry_after' => null,
        ];
    }

    private function logSolicitaAutorizacaoResponse(
        HttpResponse $resp,
        string $cpf,
        string $celular,
        int $attempt,
        string $stage
    ): void {
        if (!$this->logFactaResponses) {
            return;
        }

        try {
            $json = null;
            $mensagem = null;
            $erro = null;

            try {
                $decoded = $resp->json();
                if (is_array($decoded)) {
                    $json = $decoded;
                    $payload = $this->normalizeSolicitaAutorizacaoPayload($decoded);
                    if (is_array($payload)) {
                        $mensagem = (string) ($payload['mensagem'] ?? $payload['message'] ?? '');
                        if (array_key_exists('erro', $payload)) {
                            $erro = (bool) $payload['erro'];
                        }
                    }
                }
            } catch (Throwable) {
                // mantém fallback para body bruto
            }

            CltLog::warning('[FACTA] /solicita-autorizacao-consulta response', [
                'cpf' => $cpf,
                'celular' => $celular,
                'attempt' => $attempt,
                'stage' => $stage,
                'http_status' => $resp->status(),
                'erro' => $erro,
                'mensagem' => $mensagem,
                'body_snippet' => $this->truncate((string) $resp->body(), 4000),
                'json' => $json,
            ]);
        } catch (Throwable $e) {
            CltLog::warning('[FACTA] Falha ao logar /solicita-autorizacao-consulta: ' . $e->getMessage());
        }
    }

    private function logAutorizaConsultaResponse(
        HttpResponse $resp,
        string $cpf,
        string $stage,
        int $attempt
    ): void {
        if (!$this->logFactaResponses) {
            return;
        }

        try {
            $json = null;
            $mensagem = null;
            $erro = null;

            try {
                $decoded = $resp->json();
                if (is_array($decoded)) {
                    $json = $decoded;
                    $mensagem = (string) ($decoded['mensagem'] ?? $decoded['message'] ?? '');
                    if (array_key_exists('erro', $decoded)) {
                        $erro = (bool) $decoded['erro'];
                    }
                }
            } catch (Throwable) {
                // mantém fallback para body bruto
            }

            CltLog::warning('[FACTA] /autoriza-consulta response', [
                'cpf' => $cpf,
                'attempt' => $attempt,
                'stage' => $stage,
                'http_status' => $resp->status(),
                'erro' => $erro,
                'mensagem' => $mensagem,
                'body_snippet' => $this->truncate((string) $resp->body(), 4000),
                'json' => $json,
            ]);
        } catch (Throwable $e) {
            CltLog::warning('[FACTA] Falha ao logar /autoriza-consulta: ' . $e->getMessage());
        }
    }

    private function normalizeSolicitaAutorizacaoPayload(array $decoded): ?array
    {
        if ($this->isAssocArray($decoded)) {
            return $decoded;
        }

        foreach ($decoded as $item) {
            if (is_array($item)) {
                return $item;
            }
        }

        return null;
    }

    private function isAssocArray(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function postSolicitaAutorizacaoConsulta(string $cpf, string $token, string $celular): HttpResponse
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])
            ->asForm()
            ->timeout($this->httpTimeout)
            ->connectTimeout($this->httpConnectTimeout)
            ->retry(max(0, $this->httpRetry), max(0, $this->httpRetryDelayMs), fn($e) => $e instanceof ConnectionException)
            ->post($this->baseUrl . '/solicita-autorizacao-consulta', [
                'averbador' => $this->preAuthAverbador,
                'nome' => $this->preAuthNome,
                'cpf' => $cpf,
                'celular' => $celular,
                'tipo_envio' => $this->preAuthTipoEnvio,
            ]);
    }

    private function generateRandomCellular(): string
    {
        $ddd = self::VALID_BR_DDDS[array_rand(self::VALID_BR_DDDS)];
        $suffix = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        return $ddd . '9' . $suffix;
    }

    private function isTokenValidoSemAutorizacaoMessage(string $mensagem): bool
    {
        $norm = $this->normalize($mensagem);
        return str_contains($norm, 'token valido') && str_contains($norm, 'nao necessita de autorizacao');
    }

    private function isTelefoneJaInformadoMessage(string $mensagem): bool
    {
        $norm = $this->normalize($mensagem);
        return str_contains($norm, 'telefone ja informado para outro cpf');
    }

    private function isDddInvalidoMessage(string $mensagem): bool
    {
        $norm = $this->normalize($mensagem);
        return str_contains($norm, 'nao possui um ddd valido');
    }

    private function parseAutorizaResponse(HttpResponse $resp): array
    {
        $status = $resp->status();
        $retryAfter = $this->getRetryAfterSeconds($resp);

        if (!$resp->ok()) {
            // 👉 Tornar 403 retriable (comportamento típico de WAF/edge temporário)
            // Mantém 401/408/429/5xx como já estava.
            $mensagem = $this->responseMessage($resp);

            // Se vier HTML, já tratamos como temporário; manter coerência:
            $looksHtml = false;
            try {
                $body = (string) $resp->body();
                $looksHtml = ($body !== '') && $this->looksLikeHtml($body);
            } catch (\Throwable $e) {
                // ignore
            }

            $retriable = in_array($status, [401, 403, 408, 429], true) || $status >= 500 || $looksHtml;

            return [
                'ok' => false,
                'mensagem' => $mensagem ?: "HTTP {$status}",
                'vinculos' => null,
                'retriable' => $retriable,
                'not_found' => false,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        $json = $resp->json();

        // 200 mas corpo inválido → temporário
        if (!is_array($json)) {
            return [
                'ok' => false,
                'mensagem' => $this->responseMessage($resp) ?: 'Resposta inválida da FACTA',
                'vinculos' => null,
                'retriable' => true,
                'not_found' => false,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        // Mensagem HTML em 'mensagem' → tratar como temporário
        $msgRaw = (string) ($json['mensagem'] ?? $json['message'] ?? '');
        if ($msgRaw !== '' && $this->looksLikeHtml($msgRaw)) {
            $short = $this->summarizeHtml($msgRaw);
            return [
                'ok' => false,
                'mensagem' => $short,
                'vinculos' => null,
                'retriable' => true,
                'not_found' => false,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        if (!empty($json['erro'])) {
            $mensagem = (string) ($json['mensagem'] ?? 'Falha na consulta');
            $isNaoEncontrado = $this->isNaoEncontradoMessage($mensagem);

            return [
                'ok' => false,
                'mensagem' => $mensagem,
                'vinculos' => null,
                'retriable' => !$isNaoEncontrado,
                'not_found' => $isNaoEncontrado,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        $container =
            $json['dados_Trabalhador']
            ?? $json['dados_trabalhador']
            ?? $json['dadosTrabalhador']
            ?? null;

        $dados = is_array($container) ? ($container['dados'] ?? null) : null;

        if (is_array($dados) && count($dados) > 0) {
            return [
                'ok' => true,
                'mensagem' => $json['mensagem'] ?? ($container['mensagem'] ?? 'OK'),
                'vinculos' => $dados,
                'retriable' => false,
                'not_found' => false,
                'http_status' => 200,
                'retry_after' => null,
            ];
        }

        return [
            'ok' => true,
            'mensagem' => $json['mensagem'] ?? ($container['mensagem'] ?? 'Sem vínculos'),
            'vinculos' => [],
            'retriable' => false,
            'not_found' => false,
            'http_status' => 200,
            'retry_after' => null,
        ];
    }


    private function looksLikeHtml(string $s): bool
    {
        $snip = mb_substr($s, 0, 2048, 'UTF-8'); // checa só o início
        if (preg_match('/<!DOCTYPE\s+HTML/i', $snip))
            return true;
        if (preg_match('/<html[\s>]/i', $snip))
            return true;
        if (preg_match('/<head>|<title>|<body>/i', $snip))
            return true;
        if (preg_match('/<\/html>/i', $snip))
            return true;
        return false;
    }

    private function summarizeHtml(string $html): string
    {
        $title = null;
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(strip_tags($m[1] ?? ''));
        }
        $lower = mb_strtolower($html, 'UTF-8');
        if (str_contains($lower, '403') && str_contains($lower, 'forbidden')) {
            return ($title !== null ? "{$title}" : 'HTML 403 Forbidden') . ' (tratado como temporário)';
        }
        if (str_contains($lower, '503') && str_contains($lower, 'service unavailable')) {
            return ($title !== null ? "{$title}" : 'HTML 503 Service Unavailable') . ' (tratado como temporário)';
        }
        return ($title !== null ? "HTML: {$title}" : 'Resposta HTML inesperada') . ' (tratado como temporário)';
    }

    private function getRetryAfterSeconds(HttpResponse $resp): ?int
    {
        $h = $resp->header('Retry-After');
        if ($h === null)
            return null;
        $h = trim((string) $h);
        if ($h === '')
            return null;

        if (ctype_digit($h)) {
            return max(0, (int) $h);
        }
        $ts = strtotime($h);
        if ($ts !== false) {
            $delta = $ts - time();
            return $delta > 0 ? $delta : 0;
        }
        return null;
    }

    private function responseMessage(HttpResponse $resp): string
    {
        $status = $resp->status();
        try {
            $json = $resp->json();
            if (is_array($json)) {
                $msg = $json['mensagem'] ?? $json['message'] ?? null;
                if (is_string($msg) && trim($msg) !== '') {
                    if ($this->looksLikeHtml($msg)) {
                        return $this->summarizeHtml($msg);
                    }
                    return trim($msg);
                }
                $encoded = json_encode($json, JSON_UNESCAPED_UNICODE);
                if (is_string($encoded))
                    return $this->truncate(trim($encoded));
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $body = (string) $resp->body();
        if (trim($body) !== '') {
            if ($this->looksLikeHtml($body)) {
                return $this->summarizeHtml($body);
            }
            return $this->truncate(trim($body));
        }

        return "HTTP {$status}";
    }

    private function truncate(string $s, int $max = 500): string
    {
        if (mb_strlen($s, 'UTF-8') <= $max)
            return $s;
        return mb_substr($s, 0, $max, 'UTF-8') . '…';
    }

    private function isNaoEncontradoMessage(string $mensagem): bool
    {
        $msg = trim($mensagem);

        if (strcasecmp($msg, 'CPF não encontrado na base') === 0)
            return true;
        if (strcasecmp($msg, 'CPF nao encontrado na base') === 0)
            return true;

        $norm = $this->normalize($msg);
        if ($norm === 'cpf nao encontrado na base')
            return true;

        return str_contains($norm, 'nao encontrado na base')
            || str_contains($norm, 'não encontrado na base');
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $map = [
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ç' => 'c',
            'Á' => 'a',
            'À' => 'a',
            'Â' => 'a',
            'Ã' => 'a',
            'Ä' => 'a',
            'É' => 'e',
            'È' => 'e',
            'Ê' => 'e',
            'Ë' => 'e',
            'Í' => 'i',
            'Ì' => 'i',
            'Î' => 'i',
            'Ï' => 'i',
            'Ó' => 'o',
            'Ò' => 'o',
            'Ô' => 'o',
            'Õ' => 'o',
            'Ö' => 'o',
            'Ú' => 'u',
            'Ù' => 'u',
            'Û' => 'u',
            'Ü' => 'u',
            'Ç' => 'c',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }
}
