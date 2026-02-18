<?php

namespace App\Modules\CLT\Services;

use App\Modules\CLT\Support\CltLog;
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
    private bool $logFactaSuccessResponses;

    /** Pré-autorização (CLT online) */
    private string $preAuthAverbador;
    private string $preAuthNome;
    private string $preAuthTipoEnvio;
    private int $preAuthPhoneAttempts;
    private int $preAuthCacheTtl;
    private array $preAuthApprovedLocal = [];

    /** Continuação CLT Online (Etapa 4 e Etapa 3) */
    private string $creditProduto;
    private string $creditTipoOperacao;
    private string $creditAverbador;
    private string $creditConvenio;
    private string $creditOpcaoValor;

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
        $credit = (array) config('cltfacta.credit_worker', []);

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
        $this->logFactaSuccessResponses = (bool) config('cltfacta.logging.facta_log_success_responses', false);

        // Pré-autorização obrigatória antes do autoriza-consulta
        $this->preAuthAverbador = (string) ($api['pre_auth_averbador'] ?? '10010');
        $this->preAuthNome = (string) ($api['pre_auth_nome'] ?? 'slkjhdsjkha asdkjhd iou');
        $this->preAuthTipoEnvio = (string) ($api['pre_auth_tipo_envio'] ?? 'WHATSAPP');
        $this->preAuthPhoneAttempts = max(1, (int) ($api['pre_auth_phone_attempts'] ?? 8));
        $this->preAuthCacheTtl = max(0, (int) ($api['pre_auth_cache_ttl'] ?? 1800));

        // Continuação (crédito trabalhador) - somente online
        $this->creditProduto = (string) ($credit['produto'] ?? 'D');
        $this->creditTipoOperacao = (string) ($credit['tipo_operacao'] ?? '13');
        $this->creditAverbador = (string) ($credit['averbador'] ?? '10010');
        $this->creditConvenio = (string) ($credit['convenio'] ?? '3');
        $this->creditOpcaoValor = (string) ($credit['opcao_valor'] ?? '2');
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

            if (!$this->hasPreAuthGrant($cpf)) {
                $preAuth = $this->solicitaAutorizacaoConsulta($cpf, $token);
                if (!($preAuth['ok'] ?? false)) {
                    return $this->errorResult(
                        (string) ($preAuth['mensagem'] ?? 'Falha na pré-autorização'),
                        (bool) ($preAuth['retriable'] ?? false),
                        isset($preAuth['http_status']) ? (int) $preAuth['http_status'] : null,
                        $preAuth['retry_after'] ?? null
                    );
                }
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
                $this->clearPreAuthGrantCache();
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
                    $retryAfter = $this->getRetryAfterSeconds($resp);
                    if (!$this->shouldRetry429Immediately($retryAfter)) {
                        break;
                    }

                    $this->sleepBeforeImmediate429Retry(
                        'autoriza-consulta',
                        $retryAfter,
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
                        $this->clearPreAuthGrantCache();
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

            $parsed = $this->parseAutorizaResponse($resp);
            if (!empty($parsed['ok'])) {
                $this->markPreAuthGrant($cpf);
            }
            return $parsed;
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
        $normalizedCpfs = [];
        foreach ($cpfs as $c) {
            $digits = preg_replace('/\D+/', '', (string) $c);
            if (strlen($digits) === 11) {
                $normalizedCpfs[] = $digits;
            }
        }
        $cpfs = array_values(array_unique($normalizedCpfs));

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
            if ($this->hasPreAuthGrant($cpf)) {
                $authorizedCpfs[] = $cpf;
                continue;
            }

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
            $this->clearPreAuthGrantCache();
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
                if (!$this->shouldRetry429Immediately($retryAfterMax)) {
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
                    $this->clearPreAuthGrantCache();
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
            if (!empty($out[$cpf]['ok'])) {
                $this->markPreAuthGrant($cpf);
            }
        }

        return $out;
    }

    /**
     * Continuação do fluxo para CPF elegível (CLT online):
     * - Etapa 4: /proposta/operacoes-disponiveis
     * - Etapa 3: /consignado-trabalhador/analise-politica-credito
     */
    public function continuarCreditoTrabalhadorElegivel(array $ctx): array
    {
        $cpf = preg_replace('/\D+/', '', (string) ($ctx['cpf'] ?? ''));
        $matricula = trim((string) ($ctx['matricula'] ?? ''));
        $dataNascimento = $this->toFactaDate($ctx['dataNascimento'] ?? null);
        $dataAdmissao = $this->toFactaDate($ctx['dataAdmissao'] ?? null);
        $valorParcela = $this->toMoneyString($ctx['valorParcela'] ?? null);
        $valorRenda = $this->toMoneyString($ctx['valorRenda'] ?? null);

        if (strlen($cpf) !== 11) {
            return [
                'attempted' => true,
                'aprovado' => false,
                'mensagem' => 'CPF inválido para continuação da análise de crédito.',
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => false,
                'http_status' => null,
                'retry_after' => null,
            ];
        }

        if ($matricula === '' || $dataNascimento === null || $dataAdmissao === null || $valorParcela === null || $valorRenda === null) {
            return [
                'attempted' => true,
                'aprovado' => false,
                'mensagem' => 'Dados insuficientes para continuação da análise de crédito.',
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => false,
                'http_status' => null,
                'retry_after' => null,
            ];
        }

        $valorParcelaNum = (float) $valorParcela;
        $valorRendaNum = (float) $valorRenda;

        $validationMessages = [];
        if ($valorParcelaNum < 0.0) {
            $validationMessages[] = 'Parcela negativa (margem indisponível).';
        } elseif ($valorParcelaNum == 0.0) {
            $validationMessages[] = 'Parcela zerada (margem indisponível).';
        }

        if ($valorRendaNum < 0.0) {
            $validationMessages[] = 'Renda negativa.';
        } elseif ($valorRendaNum == 0.0) {
            $validationMessages[] = 'Renda zerada.';
        }

        if (!empty($validationMessages)) {
            return [
                'attempted' => true,
                'aprovado' => false,
                'mensagem' => implode(' ', $validationMessages),
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => false,
                'http_status' => null,
                'retry_after' => null,
            ];
        }

        $token = null;
        try {
            $token = $this->getToken();
            if (!is_string($token) || $token === '') {
                throw new \RuntimeException('Token FACTA ausente');
            }
        } catch (Throwable $e) {
            return [
                'attempted' => true,
                'aprovado' => false,
                'mensagem' => 'Falha ao gerar token: ' . $e->getMessage(),
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => true,
                'http_status' => null,
                'retry_after' => null,
            ];
        }

        $op = $this->consultaOperacoesDisponiveis($cpf, $dataNascimento, $valorRenda, $valorParcela, $token);
        if (!($op['ok'] ?? false)) {
            return [
                'attempted' => true,
                'aprovado' => false,
                'mensagem' => (string) ($op['mensagem'] ?? 'Falha na consulta de operações disponíveis.'),
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => (bool) ($op['retriable'] ?? true),
                'http_status' => $op['http_status'] ?? null,
                'retry_after' => $op['retry_after'] ?? null,
            ];
        }

        $tabelas = is_array($op['tabelas'] ?? null) ? $op['tabelas'] : [];
        if (empty($tabelas)) {
            return [
                'attempted' => true,
                'aprovado' => false,
                'mensagem' => (string) ($op['mensagem'] ?? 'Nenhuma tabela disponível.'),
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => false,
                'http_status' => 200,
                'retry_after' => null,
            ];
        }

        $lastMensagem = 'Operação fora da política de crédito.';
        foreach ($tabelas as $tb) {
            if (!is_array($tb)) {
                continue;
            }

            $prazo = isset($tb['prazo']) && is_numeric($tb['prazo']) ? (int) $tb['prazo'] : null;
            $valorEmprestimo = $this->toMoneyString($tb['valor_liquido'] ?? null);
            if ($prazo === null || $prazo <= 0 || $valorEmprestimo === null) {
                continue;
            }

            $policy = $this->consultaAnalisePoliticaCredito(
                $cpf,
                $matricula,
                $dataNascimento,
                $dataAdmissao,
                $prazo,
                $valorEmprestimo,
                $token
            );

            if (!($policy['ok'] ?? false)) {
                return [
                    'attempted' => true,
                    'aprovado' => false,
                    'mensagem' => (string) ($policy['mensagem'] ?? 'Falha na análise de política de crédito.'),
                    'valor_maximo_disponivel' => null,
                    'prazo_maximo_disponivel' => null,
                    'retriable' => (bool) ($policy['retriable'] ?? true),
                    'http_status' => $policy['http_status'] ?? null,
                    'retry_after' => $policy['retry_after'] ?? null,
                ];
            }

            if (!empty($policy['aprovado'])) {
                return [
                    'attempted' => true,
                    'aprovado' => true,
                    'mensagem' => (string) ($policy['mensagem'] ?? 'Aprovado pela política de crédito.'),
                    'valor_maximo_disponivel' => $policy['valor_maximo_disponivel'] ?? null,
                    'prazo_maximo_disponivel' => $policy['prazo_maximo_disponivel'] ?? null,
                    'retriable' => false,
                    'http_status' => 200,
                    'retry_after' => null,
                ];
            }

            $lastMensagem = (string) ($policy['mensagem'] ?? $lastMensagem);
        }

        return [
            'attempted' => true,
            'aprovado' => false,
            'mensagem' => $lastMensagem,
            'valor_maximo_disponivel' => null,
            'prazo_maximo_disponivel' => null,
            'retriable' => false,
            'http_status' => 200,
            'retry_after' => null,
        ];
    }

    private function consultaOperacoesDisponiveis(
        string $cpf,
        string $dataNascimento,
        string $valorRenda,
        string $valorParcela,
        string &$token
    ): array {
        $params = [
            'produto' => $this->creditProduto,
            'tipo_operacao' => $this->creditTipoOperacao,
            'averbador' => $this->creditAverbador,
            'convenio' => $this->creditConvenio,
            'opcao_valor' => $this->creditOpcaoValor,
            'valor_parcela' => $valorParcela,
            'cpf' => $cpf,
            'data_nascimento' => $dataNascimento,
            'valor_renda' => $valorRenda,
        ];

        $doRequest = function () use (&$token, $params) {
            return Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
                ->timeout($this->httpTimeout)
                ->connectTimeout($this->httpConnectTimeout)
                ->retry(max(0, $this->httpRetry), max(0, $this->httpRetryDelayMs), fn($e) => $e instanceof ConnectionException)
                ->get($this->baseUrl . '/proposta/operacoes-disponiveis', $params);
        };

        try {
            $resp = $doRequest();
            $this->logOperacoesDisponiveisResponse($resp, $cpf, 'initial', 1);

            if ($resp->status() === 403) {
                $this->logForbidden($resp, $cpf);
            }

            if ($resp->status() === 401) {
                Cache::forget('facta_token');
                $this->clearPreAuthGrantCache();
                $token = $this->getToken();
                if (!is_string($token) || $token === '') {
                    throw new \RuntimeException('Token FACTA ausente após refresh');
                }

                $resp = $doRequest();
                $this->logOperacoesDisponiveisResponse($resp, $cpf, 'after_401_refresh', 1);
                if ($resp->status() === 403) {
                    $this->logForbidden($resp, $cpf);
                }
            }

            if ($this->httpRateLimitImmediateRetry && $this->httpRateLimitMaxRetries > 0) {
                for ($rlAttempt = 1; $resp->status() === 429 && $rlAttempt <= $this->httpRateLimitMaxRetries; $rlAttempt++) {
                    $retryAfter = $this->getRetryAfterSeconds($resp);
                    if (!$this->shouldRetry429Immediately($retryAfter)) {
                        break;
                    }

                    $this->sleepBeforeImmediate429Retry(
                        'proposta/operacoes-disponiveis',
                        $retryAfter,
                        $cpf,
                        $rlAttempt
                    );

                    $resp = $doRequest();
                    $this->logOperacoesDisponiveisResponse($resp, $cpf, 'after_429_backoff', $rlAttempt);
                    if ($resp->status() === 403) {
                        $this->logForbidden($resp, $cpf);
                    }

                    if ($resp->status() === 401) {
                        Cache::forget('facta_token');
                        $this->clearPreAuthGrantCache();
                        $token = $this->getToken();
                        if (!is_string($token) || $token === '') {
                            throw new \RuntimeException('Token FACTA ausente após refresh');
                        }

                        $resp = $doRequest();
                        $this->logOperacoesDisponiveisResponse($resp, $cpf, 'after_429_backoff_401_refresh', $rlAttempt);
                        if ($resp->status() === 403) {
                            $this->logForbidden($resp, $cpf);
                        }
                    }
                }
            }

            return $this->parseOperacoesDisponiveisResponse($resp);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'tabelas' => [],
                'mensagem' => 'Exceção em operações disponíveis: ' . $e->getMessage(),
                'retriable' => true,
                'http_status' => null,
                'retry_after' => null,
            ];
        }
    }

    private function consultaAnalisePoliticaCredito(
        string $cpf,
        string $matricula,
        string $dataNascimento,
        string $dataAdmissao,
        int $prazo,
        string $valorEmprestimo,
        string &$token
    ): array {
        $params = [
            'cpf' => $cpf,
            'matricula' => $matricula,
            'dataNascimento' => $dataNascimento,
            'dataAdmissao' => $dataAdmissao,
            'prazo' => $prazo,
            'valorEmprestimo' => $valorEmprestimo,
        ];

        $doRequest = function () use (&$token, $params) {
            return Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
                ->timeout($this->httpTimeout)
                ->connectTimeout($this->httpConnectTimeout)
                ->retry(max(0, $this->httpRetry), max(0, $this->httpRetryDelayMs), fn($e) => $e instanceof ConnectionException)
                ->get($this->baseUrl . '/consignado-trabalhador/analise-politica-credito', $params);
        };

        try {
            $resp = $doRequest();
            $this->logAnalisePoliticaCreditoResponse($resp, $cpf, 'initial', 1, $prazo, $valorEmprestimo);

            if ($resp->status() === 403) {
                $this->logForbidden($resp, $cpf);
            }

            if ($resp->status() === 401) {
                Cache::forget('facta_token');
                $this->clearPreAuthGrantCache();
                $token = $this->getToken();
                if (!is_string($token) || $token === '') {
                    throw new \RuntimeException('Token FACTA ausente após refresh');
                }

                $resp = $doRequest();
                $this->logAnalisePoliticaCreditoResponse($resp, $cpf, 'after_401_refresh', 1, $prazo, $valorEmprestimo);
                if ($resp->status() === 403) {
                    $this->logForbidden($resp, $cpf);
                }
            }

            if ($this->httpRateLimitImmediateRetry && $this->httpRateLimitMaxRetries > 0) {
                for ($rlAttempt = 1; $resp->status() === 429 && $rlAttempt <= $this->httpRateLimitMaxRetries; $rlAttempt++) {
                    $retryAfter = $this->getRetryAfterSeconds($resp);
                    if (!$this->shouldRetry429Immediately($retryAfter)) {
                        break;
                    }

                    $this->sleepBeforeImmediate429Retry(
                        'consignado-trabalhador/analise-politica-credito',
                        $retryAfter,
                        $cpf,
                        $rlAttempt
                    );

                    $resp = $doRequest();
                    $this->logAnalisePoliticaCreditoResponse(
                        $resp,
                        $cpf,
                        'after_429_backoff',
                        $rlAttempt,
                        $prazo,
                        $valorEmprestimo
                    );
                    if ($resp->status() === 403) {
                        $this->logForbidden($resp, $cpf);
                    }

                    if ($resp->status() === 401) {
                        Cache::forget('facta_token');
                        $this->clearPreAuthGrantCache();
                        $token = $this->getToken();
                        if (!is_string($token) || $token === '') {
                            throw new \RuntimeException('Token FACTA ausente após refresh');
                        }

                        $resp = $doRequest();
                        $this->logAnalisePoliticaCreditoResponse(
                            $resp,
                            $cpf,
                            'after_429_backoff_401_refresh',
                            $rlAttempt,
                            $prazo,
                            $valorEmprestimo
                        );
                        if ($resp->status() === 403) {
                            $this->logForbidden($resp, $cpf);
                        }
                    }
                }
            }

            return $this->parseAnalisePoliticaCreditoResponse($resp);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'aprovado' => false,
                'mensagem' => 'Exceção na análise de política de crédito: ' . $e->getMessage(),
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => true,
                'http_status' => null,
                'retry_after' => null,
            ];
        }
    }

    private function parseOperacoesDisponiveisResponse(HttpResponse $resp): array
    {
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

            return [
                'ok' => false,
                'tabelas' => [],
                'mensagem' => $mensagem !== '' ? $mensagem : "HTTP {$status}",
                'retriable' => $this->isRetriableHttpStatus($status, $looksHtml),
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        $json = $resp->json();
        if (!is_array($json)) {
            return [
                'ok' => false,
                'tabelas' => [],
                'mensagem' => $this->responseMessage($resp) ?: 'Resposta inválida da FACTA em operações disponíveis.',
                'retriable' => true,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        $erro = (bool) ($json['erro'] ?? false);
        $mensagem = trim((string) ($json['mensagem'] ?? $json['message'] ?? ''));
        $tabelasRaw = $json['tabelas'] ?? [];
        $tabelas = [];

        if (is_array($tabelasRaw)) {
            foreach ($tabelasRaw as $tb) {
                if (is_array($tb)) {
                    $tabelas[] = $tb;
                }
            }
        } elseif (is_string($tabelasRaw) && $mensagem === '') {
            $mensagem = trim($tabelasRaw);
        }

        if ($erro) {
            if ($mensagem === '') {
                $mensagem = $this->isNenhumaTabelaMessage((string) $tabelasRaw)
                    ? 'nenhuma tabela disponível'
                    : 'Falha na consulta de operações disponíveis.';
            }

            return [
                'ok' => true,
                'tabelas' => [],
                'mensagem' => $mensagem,
                'retriable' => false,
                'http_status' => 200,
                'retry_after' => null,
            ];
        }

        if (!empty($tabelas)) {
            return [
                'ok' => true,
                'tabelas' => $tabelas,
                'mensagem' => $mensagem !== '' ? $mensagem : 'Operações disponíveis encontradas.',
                'retriable' => false,
                'http_status' => 200,
                'retry_after' => null,
            ];
        }

        if ($mensagem === '') {
            $mensagem = 'nenhuma tabela disponível';
        }

        return [
            'ok' => true,
            'tabelas' => [],
            'mensagem' => $mensagem,
            'retriable' => false,
            'http_status' => 200,
            'retry_after' => null,
        ];
    }

    private function parseAnalisePoliticaCreditoResponse(HttpResponse $resp): array
    {
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

            return [
                'ok' => false,
                'aprovado' => false,
                'mensagem' => $mensagem !== '' ? $mensagem : "HTTP {$status}",
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => $this->isRetriableHttpStatus($status, $looksHtml),
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        $json = $resp->json();
        if (!is_array($json)) {
            return [
                'ok' => false,
                'aprovado' => false,
                'mensagem' => $this->responseMessage($resp) ?: 'Resposta inválida da FACTA em análise de política.',
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => true,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        $aprovado = $this->factaFlagToBool($json['aprovado'] ?? null) === true;
        $mensagem = trim((string) ($json['mensagem'] ?? $json['message'] ?? ''));
        if ($mensagem === '') {
            $mensagem = $aprovado
                ? 'Aprovado pela política de crédito.'
                : 'Operação fora da política de crédito.';
        }

        $prazoRaw = $json['prazo_maximo_disponivel'] ?? null;
        $prazoMax = null;
        if ($prazoRaw !== null && trim((string) $prazoRaw) !== '') {
            $prazoMax = is_numeric($prazoRaw)
                ? (string) ((int) $prazoRaw)
                : trim((string) $prazoRaw);
        }

        return [
            'ok' => true,
            'aprovado' => $aprovado,
            'mensagem' => $mensagem,
            'valor_maximo_disponivel' => $this->toMoneyString($json['valor_maximo_disponivel'] ?? null),
            'prazo_maximo_disponivel' => $prazoMax,
            'retriable' => false,
            'http_status' => 200,
            'retry_after' => null,
        ];
    }

    private function logOperacoesDisponiveisResponse(HttpResponse $resp, string $cpf, string $stage, int $attempt): void
    {
        if (!$this->shouldLogFactaResponse($resp)) {
            return;
        }

        try {
            $decoded = null;
            try {
                $json = $resp->json();
                if (is_array($json)) {
                    $decoded = $json;
                }
            } catch (Throwable) {
                // ignore
            }

            CltLog::warning('[FACTA] /proposta/operacoes-disponiveis response', [
                'cpf' => $cpf,
                'attempt' => $attempt,
                'stage' => $stage,
                'http_status' => $resp->status(),
                'body_snippet' => $this->truncate((string) $resp->body(), 4000),
                'json' => $decoded,
            ]);
        } catch (Throwable $e) {
            CltLog::warning('[FACTA] Falha ao logar /proposta/operacoes-disponiveis: ' . $e->getMessage());
        }
    }

    private function logAnalisePoliticaCreditoResponse(
        HttpResponse $resp,
        string $cpf,
        string $stage,
        int $attempt,
        int $prazo,
        string $valorEmprestimo
    ): void {
        if (!$this->shouldLogFactaResponse($resp)) {
            return;
        }

        try {
            $decoded = null;
            try {
                $json = $resp->json();
                if (is_array($json)) {
                    $decoded = $json;
                }
            } catch (Throwable) {
                // ignore
            }

            CltLog::warning('[FACTA] /consignado-trabalhador/analise-politica-credito response', [
                'cpf' => $cpf,
                'attempt' => $attempt,
                'stage' => $stage,
                'prazo' => $prazo,
                'valorEmprestimo' => $valorEmprestimo,
                'http_status' => $resp->status(),
                'body_snippet' => $this->truncate((string) $resp->body(), 4000),
                'json' => $decoded,
            ]);
        } catch (Throwable $e) {
            CltLog::warning('[FACTA] Falha ao logar /analise-politica-credito: ' . $e->getMessage());
        }
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
        // Evita segurar worker por longos períodos dentro da camada HTTP.
        // Em retry-after alto, o job externo faz o backoff cooperativo.
        if (!$this->shouldRetry429Immediately($retryAfterSeconds)) {
            CltLog::warning('[FACTA] 429 sem retry imediato (delegado ao backoff do job)', [
                'endpoint' => $endpoint,
                'cpf' => $cpf,
                'attempt' => $attempt,
                'batch_size' => $batchSize,
                'retry_after' => $retryAfterSeconds,
            ]);
            return;
        }

        $baseMs = $retryAfterSeconds !== null
            ? max(50, min(1000, $retryAfterSeconds * 1000))
            : 120;
        $jitterMs = random_int(0, 80);
        $sleepMs = min(250, $baseMs + $jitterMs);

        CltLog::warning('[FACTA] 429 immediate backoff', [
            'endpoint' => $endpoint,
            'cpf' => $cpf,
            'attempt' => $attempt,
            'batch_size' => $batchSize,
            'retry_after' => $retryAfterSeconds,
            'sleep_ms' => $sleepMs,
        ]);

        if ($sleepMs > 0) {
            usleep($sleepMs * 1000);
        }
    }

    private function shouldRetry429Immediately(?int $retryAfterSeconds): bool
    {
        return $retryAfterSeconds === null || $retryAfterSeconds <= 1;
    }

    // App\Modules\CLT\Services\FactaApiService.php

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

    private function hasPreAuthGrant(string $cpf): bool
    {
        if ($this->preAuthCacheTtl <= 0) {
            return false;
        }

        if (!isset($this->preAuthApprovedLocal[$cpf])) {
            return false;
        }

        $expiresAt = (float) $this->preAuthApprovedLocal[$cpf];
        if ($expiresAt >= microtime(true)) {
            return true;
        }

        unset($this->preAuthApprovedLocal[$cpf]);
        return false;
    }

    private function markPreAuthGrant(string $cpf): void
    {
        if ($this->preAuthCacheTtl <= 0) {
            return;
        }

        $ttl = max(1, $this->preAuthCacheTtl);
        $this->preAuthApprovedLocal[$cpf] = microtime(true) + $ttl;
    }

    private function clearPreAuthGrantCache(): void
    {
        $this->preAuthApprovedLocal = [];
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
                        $this->clearPreAuthGrantCache();
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
                    $retryAfter = $this->getRetryAfterSeconds($resp);
                    if (!$this->shouldRetry429Immediately($retryAfter)) {
                        break;
                    }

                    $rateLimitAttempt++;
                    $this->sleepBeforeImmediate429Retry(
                        'solicita-autorizacao-consulta',
                        $retryAfter,
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
                $this->markPreAuthGrant($cpf);
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
        if (!$this->shouldLogFactaResponse($resp)) {
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

    private function shouldLogFactaResponse(HttpResponse $resp): bool
    {
        if (!$this->logFactaResponses) {
            return false;
        }

        if ($this->logFactaSuccessResponses) {
            return true;
        }

        return $resp->status() >= 400;
    }

    private function logAutorizaConsultaResponse(
        HttpResponse $resp,
        string $cpf,
        string $stage,
        int $attempt
    ): void {
        if (!$this->shouldLogFactaResponse($resp)) {
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

    private function toFactaDate($val): ?string
    {
        if ($val === null) {
            return null;
        }

        $s = trim((string) $val);
        if ($s === '') {
            return null;
        }

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $s)) {
            return $s;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            [$y, $m, $d] = explode('-', $s);
            return sprintf('%02d/%02d/%04d', (int) $d, (int) $m, (int) $y);
        }

        return null;
    }

    private function toMoneyString($val): ?string
    {
        $n = $this->toFloatSmart($val);
        if ($n === null) {
            return null;
        }

        return number_format($n, 2, '.', '');
    }

    private function toFloatSmart($val): ?float
    {
        if ($val === null) {
            return null;
        }

        if (is_float($val) || is_int($val)) {
            return (float) $val;
        }

        $s = trim((string) $val);
        if ($s === '') {
            return null;
        }

        $s = preg_replace('/[^\d,.\-+]/', '', $s);
        if ($s === '' || $s === '-' || $s === '+') {
            return null;
        }

        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');

        if ($lastComma === false && $lastDot === false) {
            return is_numeric($s) ? (float) $s : null;
        }

        $decimalSep = null;
        if ($lastComma === false) {
            $decimalSep = '.';
        } elseif ($lastDot === false) {
            $decimalSep = ',';
        } else {
            $decimalSep = ($lastComma > $lastDot) ? ',' : '.';
        }

        if ($decimalSep === ',') {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    private function factaFlagToBool($val): ?bool
    {
        if (is_bool($val)) {
            return $val;
        }

        if ($val === null) {
            return null;
        }

        if (is_int($val) || is_float($val) || (is_string($val) && is_numeric($val))) {
            $n = (int) $val;
            if ($n === 1) {
                return true;
            }
            if ($n === 0) {
                return false;
            }
        }

        $u = mb_strtoupper(trim((string) $val), 'UTF-8');
        if ($u === 'SIM' || $u === 'S' || $u === 'TRUE') {
            return true;
        }
        if ($u === 'NAO' || $u === 'NÃO' || $u === 'N' || $u === 'FALSE') {
            return false;
        }

        return null;
    }

    private function isRetriableHttpStatus(int $status, bool $looksHtml): bool
    {
        return in_array($status, [401, 403, 408, 429], true) || $status >= 500 || $looksHtml;
    }

    private function isNenhumaTabelaMessage(string $mensagem): bool
    {
        $norm = $this->normalize($mensagem);
        return str_contains($norm, 'nenhuma tabela disponivel');
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
