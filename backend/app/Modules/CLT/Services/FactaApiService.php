<?php

namespace App\Modules\CLT\Services;

use App\Modules\CLT\Support\CltLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
    private int $tokenRetryMaxAttempts;
    private int $tokenRetryBaseDelayMs;
    private int $tokenRetryMaxDelayMs;

    /** HTTP (1ª rodada) */
    private int $httpTimeout;
    private int $httpConnectTimeout;
    private int $httpRetry;
    private int $httpRetryDelayMs;

    /** HTTP (2ª rodada opcional em pool) */
    private bool $httpSecondTry;
    private int $httpSecondTimeout;
    private int $httpSecondConnectTimeout;
    private int $httpTransientRetryDelayMs;
    private int $httpTransientPauseSeconds;
    private bool $httpRateLimitImmediateRetry;
    private int $httpRateLimitMaxRetries;
    private int $httpRateLimitDefaultPauseSeconds;
    private int $httpRateLimitPauseCapSeconds;
    private bool $logFactaResponses;
    private bool $logFactaSuccessResponses;
    private bool $httpGlobalRateLimitEnabled;
    private int $httpGlobalRateLimitRps;
    private int $httpGlobalRateLimitRpm;
    private int $httpGlobalRateLimitSleepMs;
    private int $httpAutorizaPoolWindow;
    private int $httpPolicyPoolWindow;

    /** Pré-autorização (CLT online) */
    private string $preAuthAverbador;
    private string $preAuthNome;
    private string $preAuthTipoEnvio;
    private int $preAuthPhoneAttempts;
    private int $preAuthCacheTtl;
    private int $preAuthPersistTtlDays;
    private int $preAuthPersistBatchSize;
    private int $preAuthPostCooldownMs;
    private array $preAuthApprovedLocal = [];
    private array $preAuthLookupCheckedLocal = [];
    /** @var array<string,bool> */
    private array $preAuthPersistQueue = [];
    private ?int $runtimeJobId = null;

    /** Continuação CLT Online (Etapa 4 e Etapa 3) */
    private string $creditProduto;
    private string $creditTipoOperacao;
    private string $creditAverbador;
    private string $creditConvenio;
    private string $creditOpcaoValor;
    private int $creditPolicyBatchSize;

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
            $status = $resp->status();
            $body = (string) $resp->body();
            $context = [
                'job_id' => $this->runtimeJobId,
                'cpf' => $cpf,
                'http_status' => $status,
                'headers' => $this->compactHeadersForLog($resp->headers()),
            ];
            $context = array_merge($context, $this->compactResponseLogContext($body, null, $status, 700));

            CltLog::warning('[FACTA] 403 Forbidden', $context);
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
        $this->tokenRetryMaxAttempts = max(1, (int) ($api['token_retry_max_attempts'] ?? 8));
        $this->tokenRetryBaseDelayMs = max(0, (int) ($api['token_retry_base_delay_ms'] ?? 1000));
        $this->tokenRetryMaxDelayMs = max($this->tokenRetryBaseDelayMs, (int) ($api['token_retry_max_delay_ms'] ?? 30000));

        // HTTP (1ª)
        $this->httpTimeout = (int) ($http['timeout'] ?? 15);
        $this->httpConnectTimeout = (int) ($http['connect_timeout'] ?? 10);
        $this->httpRetry = (int) ($http['retry'] ?? 1);
        $this->httpRetryDelayMs = (int) ($http['retry_delay_ms'] ?? 200);

        // HTTP (2ª)
        $this->httpSecondTry = (bool) ($http['second_try'] ?? true);
        $this->httpSecondTimeout = (int) ($http['second_timeout'] ?? 10);
        $this->httpSecondConnectTimeout = (int) ($http['second_connect_timeout'] ?? 5);
        $this->httpTransientRetryDelayMs = max(0, (int) ($http['transient_retry_delay_ms'] ?? 3000));
        $this->httpTransientPauseSeconds = max(1, (int) ($http['transient_pause_seconds'] ?? 3));
        $this->httpRateLimitImmediateRetry = (bool) ($http['rate_limit_immediate_retry'] ?? true);
        $this->httpRateLimitMaxRetries = max(0, (int) ($http['rate_limit_max_retries'] ?? 1));
        $this->httpRateLimitDefaultPauseSeconds = max(1, (int) ($http['rate_limit_default_pause_seconds'] ?? 3));
        $this->httpRateLimitPauseCapSeconds = max(1, (int) ($http['rate_limit_pause_cap_seconds'] ?? 30));
        $this->logFactaResponses = (bool) config('cltfacta.logging.facta_log_responses', true);
        $this->logFactaSuccessResponses = (bool) config('cltfacta.logging.facta_log_success_responses', false);
        $this->httpGlobalRateLimitRps = max(0, (int) ($http['global_rate_limit_rps'] ?? 4));
        $this->httpGlobalRateLimitRpm = max(0, (int) ($http['global_rate_limit_rpm'] ?? 180));
        $this->httpGlobalRateLimitSleepMs = max(50, (int) ($http['global_rate_limit_sleep_ms'] ?? 120));
        $this->httpGlobalRateLimitEnabled = (bool) ($http['global_rate_limit_enabled'] ?? true)
            && ($this->httpGlobalRateLimitRps > 0 || $this->httpGlobalRateLimitRpm > 0);
        $this->httpAutorizaPoolWindow = max(1, (int) ($http['autoriza_pool_window'] ?? 4));
        $this->httpPolicyPoolWindow = max(1, (int) ($http['policy_pool_window'] ?? 4));

        // Pré-autorização obrigatória antes do autoriza-consulta
        $this->preAuthAverbador = (string) ($api['pre_auth_averbador'] ?? '10010');
        $this->preAuthNome = (string) ($api['pre_auth_nome'] ?? 'slkjhdsjkha asdkjhd iou');
        $this->preAuthTipoEnvio = (string) ($api['pre_auth_tipo_envio'] ?? 'WHATSAPP');
        $this->preAuthPhoneAttempts = max(1, (int) ($api['pre_auth_phone_attempts'] ?? 8));
        $this->preAuthCacheTtl = max(0, (int) ($api['pre_auth_cache_ttl'] ?? 1800));
        $this->preAuthPersistTtlDays = max(0, (int) ($api['pre_auth_persist_ttl_days'] ?? 30));
        $this->preAuthPersistBatchSize = max(1, (int) ($api['pre_auth_persist_batch_size'] ?? 100));
        $this->preAuthPostCooldownMs = max(0, (int) ($api['pre_auth_post_cooldown_ms'] ?? 3000));

        // Continuação (crédito trabalhador) - somente online
        $this->creditProduto = (string) ($credit['produto'] ?? 'D');
        $this->creditTipoOperacao = (string) ($credit['tipo_operacao'] ?? '13');
        $this->creditAverbador = (string) ($credit['averbador'] ?? '10010');
        $this->creditConvenio = (string) ($credit['convenio'] ?? '3');
        $this->creditOpcaoValor = (string) ($credit['opcao_valor'] ?? '2');
        $this->creditPolicyBatchSize = max(1, (int) ($credit['policy_batch_size'] ?? 3));
    }

    public function setRuntimeJobId(?int $jobId): self
    {
        $this->runtimeJobId = $jobId;
        return $this;
    }

    private function waitForFactaRateLimit(int $permits = 1): void
    {
        if (!$this->httpGlobalRateLimitEnabled || $permits <= 0) {
            return;
        }

        for ($i = 0; $i < $permits; $i++) {
            $this->waitForSingleFactaPermit();
        }
    }

    private function waitForSingleFactaPermit(): void
    {
        $rps = $this->httpGlobalRateLimitRps;
        $rpm = $this->httpGlobalRateLimitRpm;
        if ($rps <= 0 && $rpm <= 0) {
            return;
        }

        $bucketPrefix = 'facta:rate:' . md5($this->baseUrl);
        $sleepMicros = max(50_000, $this->httpGlobalRateLimitSleepMs * 1000);

        while (true) {
            $now = microtime(true);
            $secondBucket = (int) floor($now);
            $minuteBucket = (int) floor($now / 60);

            $secondKey = "{$bucketPrefix}:s:{$secondBucket}";
            $minuteKey = "{$bucketPrefix}:m:{$minuteBucket}";

            $secondCount = $this->incrementRateCounter($secondKey, 2);
            $minuteCount = $this->incrementRateCounter($minuteKey, 120);

            $secondAllowed = $rps <= 0 || $secondCount <= $rps;
            $minuteAllowed = $rpm <= 0 || $minuteCount <= $rpm;
            if ($secondAllowed && $minuteAllowed) {
                return;
            }

            Cache::decrement($secondKey);
            Cache::decrement($minuteKey);
            usleep($sleepMicros);
        }
    }

    private function incrementRateCounter(string $key, int $ttlSeconds): int
    {
        Cache::add($key, 0, max(1, $ttlSeconds));
        return (int) Cache::increment($key);
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

        $maxAttempts = max(1, $this->httpRetry + 1);
        $resp = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->waitForFactaRateLimit();
                $resp = Http::withHeaders([
                        'Authorization' => 'Basic ' . $this->basicAuth,
                        'Accept' => 'application/json',
                    ])
                    ->timeout(max(1, $this->httpTimeout))
                    ->connectTimeout(max(1, $this->httpConnectTimeout))
                    ->get($this->baseUrl . '/gera-token');
            } catch (Throwable $e) {
                if (
                    $attempt < $maxAttempts
                    && ($this->isTimeoutException($e) || $this->isConnectionException($e))
                ) {
                    $this->sleepBeforeTransientRetry('gera-token', null, null, $attempt, null, 'request_exception');
                    continue;
                }

                throw $e;
            }

            $retryAfter = $this->getRetryAfterSeconds($resp);
            $isTransient = $this->isTransientHttpStatus($resp->status()) || $this->isTransientBodyShape($resp);
            if ($isTransient && $attempt < $maxAttempts) {
                if ($resp->status() === 429 && !$this->shouldRetry429Immediately($retryAfter)) {
                    break;
                }

                $this->sleepBeforeTransientRetry('gera-token', $retryAfter, null, $attempt, null, 'transient_response');
                continue;
            }

            break;
        }

        if (!$resp instanceof HttpResponse) {
            throw new \RuntimeException('FACTA token error: sem resposta do /gera-token');
        }

        $this->logGeraTokenResponse($resp, 'initial');

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


    private function getTokenWithBackoff(string $context): string
    {
        $attempts = max(1, $this->tokenRetryMaxAttempts);
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $token = $this->getToken();
                if (!is_string($token) || $token === '') {
                    throw new \RuntimeException('Token FACTA ausente');
                }
                return $token;
            } catch (Throwable $e) {
                $lastError = $e;
                $contextData = [
                    'context' => $context,
                    'attempt' => $attempt,
                    'max_attempts' => $attempts,
                    'exception_class' => get_class($e),
                    'is_timeout' => $this->isTimeoutException($e),
                    'is_connection_exception' => $this->isConnectionException($e),
                    'error' => $e->getMessage(),
                ];

                if ($attempt >= $attempts) {
                    CltLog::warning('[FACTA] Falha ao obter token; tentativas esgotadas.', $contextData);
                    break;
                }

                $sleepMs = $this->tokenRetrySleepMs($attempt);
                $contextData['sleep_ms'] = $sleepMs;
                CltLog::warning('[FACTA] Falha ao obter token; aguardando retry.', $contextData);

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        $lastMsg = $lastError ? $lastError->getMessage() : 'erro desconhecido';
        throw new \RuntimeException(
            "Falha ao obter token em {$context} após {$attempts} tentativas: {$lastMsg}",
            0,
            $lastError
        );
    }

    private function tokenRetrySleepMs(int $attempt): int
    {
        $base = max(0, $this->tokenRetryBaseDelayMs);
        if ($base === 0) {
            return 0;
        }

        $cap = max($base, $this->tokenRetryMaxDelayMs);
        $factor = max(0, $attempt - 1);
        $delay = $base;

        for ($i = 0; $i < $factor; $i++) {
            if ($delay >= $cap) {
                break;
            }
            $delay = min($cap, $delay * 2);
        }

        $jitterMax = (int) floor($delay * 0.20);
        $jitter = 0;
        if ($jitterMax > 0) {
            try {
                $jitter = random_int(0, $jitterMax);
            } catch (Throwable) {
                $jitter = 0;
            }
        }

        return min($cap, $delay + $jitter);
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
            $token = $this->getTokenWithBackoff('autoriza-consulta:init');
            $latestPreAuthAt = null;

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
                $latestPreAuthAt = microtime(true);
            }

            $this->sleepPreAuthCooldown($latestPreAuthAt);

            $doRequest = function () use ($cpf, &$token) {
                $this->waitForFactaRateLimit();

                return Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ])
                    ->timeout($this->httpTimeout)
                    ->connectTimeout($this->httpConnectTimeout)
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
                $token = $this->getTokenWithBackoff('autoriza-consulta:refresh_401');
                $resp = $doRequest();
                $this->logAutorizaConsultaResponse($resp, $cpf, 'after_401_refresh', 1);
                if ($resp->status() === 403) {
                    $this->logForbidden($resp, $cpf);
                }
            }

            if ($this->httpRateLimitImmediateRetry && $this->httpRateLimitMaxRetries > 0) {
                for ($rlAttempt = 1; $rlAttempt <= $this->httpRateLimitMaxRetries; $rlAttempt++) {
                    if (!$this->isTransientAutorizaResponse($resp)) {
                        break;
                    }

                    $retryAfter = $this->getRetryAfterSeconds($resp);
                    if ($resp->status() === 429 && !$this->shouldRetry429Immediately($retryAfter)) {
                        break;
                    }

                    $this->sleepBeforeTransientRetry(
                        'autoriza-consulta',
                        $retryAfter,
                        $cpf,
                        $rlAttempt,
                        null,
                        'transient_response'
                    );

                    $resp = $doRequest();
                    $this->logAutorizaConsultaResponse($resp, $cpf, 'after_transient_backoff', $rlAttempt + 1);

                    if ($resp->status() === 403) {
                        $this->logForbidden($resp, $cpf);
                    }

                    if ($resp->status() === 401) {
                        Cache::forget('facta_token');
                        $this->clearPreAuthGrantCache();
                        $token = $this->getTokenWithBackoff('autoriza-consulta:refresh_401_after_transient');
                        $resp = $doRequest();
                        $this->logAutorizaConsultaResponse($resp, $cpf, 'after_transient_backoff_401_refresh', $rlAttempt + 1);
                        if ($resp->status() === 403) {
                            $this->logForbidden($resp, $cpf);
                        }
                    }
                }
            }

            $parsed = $this->parseAutorizaResponse($resp);
            if (!empty($parsed['ok'])) {
                $this->markPreAuthGrant($cpf, false);
            }
            return $parsed;
        } catch (Throwable $e) {
            $this->logRequestException('/consignado-trabalhador/autoriza-consulta', $e, [
                'cpf' => $cpf,
                'stage' => 'autoriza-consulta',
                'attempt' => 1,
            ]);
            return [
                'ok' => false,
                'mensagem' => 'Exceção: ' . $e->getMessage(),
                'vinculos' => null,
                'retriable' => true,
                'not_found' => false,
                'http_status' => null,
                'retry_after' => null,
            ];
        } finally {
            $this->flushPreAuthGrantPersistQueue();
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
        try {
            // ✅ PROTEGE a geração do token
            try {
                $token = $this->getTokenWithBackoff('autoriza-consulta-lote:init');
            } catch (\Throwable $e) {
                $this->logRequestException('/consignado-trabalhador/autoriza-consulta', $e, [
                    'stage' => 'autoriza-consulta-lote:init',
                    'batch_size' => count($cpfs),
                ]);
                $msg = 'Falha ao gerar token: ' . $e->getMessage();
                foreach ($cpfs as $cpf) {
                    $out[$cpf] = $this->errorResult($msg, true);
                }
                return $out;
            }

            // Pré-autorização obrigatória (endpoint /solicita-autorizacao-consulta)
            $this->warmPreAuthGrants($cpfs);
            $authorizedCpfs = [];
            $latestPreAuthAt = null;
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
                $latestPreAuthAt = microtime(true);
            }

            if (empty($authorizedCpfs)) {
                return $out;
            }

            $this->sleepPreAuthCooldown($latestPreAuthAt);

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];
        $url = $this->baseUrl . '/consignado-trabalhador/autoriza-consulta';
        /** @var array<string,HttpResponse> $responses */
        $responses = [];
        $canRunFollowUpPools = true;
        $tokenRefreshError = null;

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
            $this->logRequestException('/consignado-trabalhador/autoriza-consulta', $e, [
                'stage' => 'initial_pool',
                'attempt' => 1,
                'batch_size' => count($authorizedCpfs),
            ]);
            $this->sleepBeforeTransientRetry(
                'autoriza-consulta',
                null,
                null,
                1,
                count($authorizedCpfs),
                'initial_pool_exception'
            );
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
                $token2 = $this->getTokenWithBackoff('autoriza-consulta-lote:refresh_401');
            } catch (Throwable $e) {
                $token2 = null;
                $canRunFollowUpPools = false;
                $tokenRefreshError = 'Falha ao renovar token FACTA: ' . $e->getMessage();
                CltLog::warning('[FACTA] Refresh token falhou em autorizaConsultaLote; pulando retries subsequentes no pool.', [
                    'need_retry_401' => count($needRetry401),
                    'is_timeout' => $this->isTimeoutException($e),
                    'is_connection_exception' => $this->isConnectionException($e),
                    'exception_class' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
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
                    $this->logRequestException('/consignado-trabalhador/autoriza-consulta', $e, [
                        'stage' => 'retry_401_pool',
                        'attempt' => 1,
                        'batch_size' => count($needRetry401),
                    ]);
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
        if ($canRunFollowUpPools && !empty($missing) && $this->httpSecondTry) {
            $this->sleepBeforeTransientRetry(
                'autoriza-consulta',
                null,
                null,
                1,
                count($missing),
                'missing_pool'
            );

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
                $this->logRequestException('/consignado-trabalhador/autoriza-consulta', $e, [
                    'stage' => 'missing_pool_retry2',
                    'attempt' => 1,
                    'batch_size' => count($missing),
                ]);
                // segunda tentativa falhou → deixa missing (Job vai retriar depois)
            }
        }

        // -------- RETRY TRANSIENTE PADRONIZADO (POOL) --------
        if ($canRunFollowUpPools && $this->httpRateLimitImmediateRetry && $this->httpRateLimitMaxRetries > 0) {
            for ($transientAttempt = 1; $transientAttempt <= $this->httpRateLimitMaxRetries; $transientAttempt++) {
                $retryCpfs = [];
                $retryAfterMax = null;

                foreach ($authorizedCpfs as $cpf) {
                    $resp = $responses[$cpf] ?? null;
                    if (!$resp instanceof HttpResponse || !$this->isTransientAutorizaResponse($resp)) {
                        continue;
                    }

                    $retryCpfs[] = $cpf;
                    $retryAfter = $this->getRetryAfterSeconds($resp);
                    if ($retryAfter !== null) {
                        $retryAfterMax = $retryAfterMax === null ? $retryAfter : max($retryAfterMax, $retryAfter);
                    }
                }

                if (empty($retryCpfs)) {
                    break;
                }
                if (!$this->canRetryTransientPoolSubset($responses, $retryCpfs, $retryAfterMax)) {
                    break;
                }

                $this->sleepBeforeTransientRetry(
                    'autoriza-consulta',
                    $retryAfterMax,
                    null,
                    $transientAttempt,
                    count($retryCpfs),
                    'transient_subset'
                );

                try {
                    $retryResponses = $this->requestAutorizaPool(
                        $retryCpfs,
                        $headers,
                        $url,
                        $this->httpSecondTimeout,
                        $this->httpSecondConnectTimeout,
                        'retry_transient_pool',
                        $transientAttempt + 1
                    );
                } catch (Throwable $e) {
                    $this->logRequestException('/consignado-trabalhador/autoriza-consulta', $e, [
                        'stage' => 'retry_transient_pool',
                        'attempt' => $transientAttempt + 1,
                        'batch_size' => count($retryCpfs),
                    ]);
                    break;
                }

                $retry401AfterTransient = [];
                foreach ($retryResponses as $cpf => $resp) {
                    if ($resp instanceof HttpResponse && $resp->status() === 401) {
                        $retry401AfterTransient[] = $cpf;
                    }
                }

                if (!empty($retry401AfterTransient)) {
                    Cache::forget('facta_token');
                    $this->clearPreAuthGrantCache();
                    try {
                        $token3 = $this->getTokenWithBackoff('autoriza-consulta-lote:refresh_401_after_transient');
                        if (is_string($token3) && $token3 !== '') {
                            $token = $token3;
                            $headers = [
                                'Authorization' => 'Bearer ' . $token,
                                'Accept' => 'application/json',
                            ];

                            $retry401Responses = $this->requestAutorizaPool(
                                $retry401AfterTransient,
                                $headers,
                                $url,
                                $this->httpSecondTimeout,
                                $this->httpSecondConnectTimeout,
                                'retry_401_after_transient_pool',
                                $transientAttempt + 1
                            );

                            foreach ($retry401Responses as $cpf => $resp) {
                                $retryResponses[$cpf] = $resp;
                            }
                        }
                    } catch (Throwable $e) {
                        $canRunFollowUpPools = false;
                        CltLog::warning('[FACTA] Refresh token falhou em retry_401_after_transient_pool; encerrando retries subsequentes no pool.', [
                            'retry_401_after_transient' => count($retry401AfterTransient),
                            'attempt' => $transientAttempt + 1,
                            'is_timeout' => $this->isTimeoutException($e),
                            'is_connection_exception' => $this->isConnectionException($e),
                            'exception_class' => get_class($e),
                            'error' => $e->getMessage(),
                        ]);
                        // mantém resposta atual desses CPFs
                        break;
                    }
                }

                foreach ($retryResponses as $cpf => $resp) {
                    $responses[$cpf] = $resp;
                }
            }
        }

        // -------- Monta saída --------
        foreach ($authorizedCpfs as $cpf) {
            $resp = $responses[$cpf] ?? null;
            if (!$resp instanceof HttpResponse) {
                if (
                    $tokenRefreshError !== null
                    && !empty($needRetry401)
                    && in_array($cpf, $needRetry401, true)
                ) {
                    $out[$cpf] = $this->errorResult($tokenRefreshError, true);
                    continue;
                }
                $out[$cpf] = $this->errorResult('Sem resposta do serviço', true);
                continue;
            }

            // 👉 LOG 403 com headers + corpo (por CPF)
            if ($resp->status() === 403) {
                $this->logForbidden($resp, $cpf);
            }

            $out[$cpf] = $this->parseAutorizaResponse($resp);
            if (!empty($out[$cpf]['ok'])) {
                $this->markPreAuthGrant($cpf, false);
            }
        }

            return $out;
        } finally {
            $this->flushPreAuthGrantPersistQueue();
        }
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
            $token = $this->getTokenWithBackoff('credito-trabalhador:init');
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

        $policyCandidates = [];
        foreach ($tabelas as $tb) {
            if (!is_array($tb)) {
                continue;
            }

            $prazo = isset($tb['prazo']) && is_numeric($tb['prazo']) ? (int) $tb['prazo'] : null;
            $valorEmprestimo = $this->toMoneyString($tb['valor_liquido'] ?? null);
            if ($prazo === null || $prazo <= 0 || $valorEmprestimo === null) {
                continue;
            }

            $policyCandidates[] = [
                'prazo' => $prazo,
                'valorEmprestimo' => $valorEmprestimo,
            ];
        }

        $lastMensagem = 'Operação fora da política de crédito.';
        foreach (array_chunk($policyCandidates, max(1, $this->creditPolicyBatchSize)) as $policyChunk) {
            $policies = $this->consultaAnalisePoliticaCreditoLote(
                $cpf,
                $matricula,
                $dataNascimento,
                $dataAdmissao,
                $policyChunk,
                $token
            );

            foreach ($policyChunk as $idx => $candidate) {
                $prazo = (int) ($candidate['prazo'] ?? 0);
                $valorEmprestimo = (string) ($candidate['valorEmprestimo'] ?? '');

                $policy = $policies[$idx] ?? $this->policyErrorResult('Sem resposta na análise de política de crédito.', true);
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

                $policyMensagem = (string) ($policy['mensagem'] ?? '');
                if (
                    empty($policy['aprovado'])
                    && (
                        $this->isPrazoMinimoPolicyApprovalMessage($policyMensagem)
                        || $this->isValorMaiorPermitido4000PolicyApprovalMessage($policyMensagem)
                    )
                ) {
                    $mensagemAprovadaInternamente = $policyMensagem !== '' ? $policyMensagem : 'Aprovado pela política de crédito.';
                    if (!str_contains($this->normalize($mensagemAprovadaInternamente), 'aprovado internamente')) {
                        $mensagemAprovadaInternamente .= ' (aprovado internamente)';
                    }

                    return [
                        'attempted' => true,
                        'aprovado' => true,
                        'mensagem' => $mensagemAprovadaInternamente,
                        'valor_maximo_disponivel' => $valorEmprestimo,
                        'prazo_maximo_disponivel' => (string) $prazo,
                        'retriable' => false,
                        'http_status' => 200,
                        'retry_after' => null,
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
            $this->waitForFactaRateLimit();

            return Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
                ->timeout($this->httpTimeout)
                ->connectTimeout($this->httpConnectTimeout)
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
                $token = $this->getTokenWithBackoff('operacoes-disponiveis:refresh_401');

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
                        $token = $this->getTokenWithBackoff('operacoes-disponiveis:refresh_401_after_429');

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
            $this->waitForFactaRateLimit();

            return Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
                ->timeout($this->httpTimeout)
                ->connectTimeout($this->httpConnectTimeout)
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
                $token = $this->getTokenWithBackoff('analise-politica-credito:refresh_401');

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
                        $token = $this->getTokenWithBackoff('analise-politica-credito:refresh_401_after_429');

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

    /**
     * @param array<int,array{prazo:int,valorEmprestimo:string}> $policyChunk
     * @return array<int,array<string,mixed>>
     */
    private function consultaAnalisePoliticaCreditoLote(
        string $cpf,
        string $matricula,
        string $dataNascimento,
        string $dataAdmissao,
        array $policyChunk,
        string &$token
    ): array {
        if (empty($policyChunk)) {
            return [];
        }

        $results = [];
        $pending = $policyChunk;
        $rateLimitAttempt = 0;
        $maxRateLimitRetries = $this->httpRateLimitImmediateRetry
            ? max(0, $this->httpRateLimitMaxRetries)
            : 0;

        while (!empty($pending)) {
            $stage = $rateLimitAttempt === 0 ? 'initial_pool' : 'retry_429_pool';
            $attempt = $rateLimitAttempt + 1;

            try {
                $responses = $this->requestAnalisePoliticaCreditoPool(
                    $cpf,
                    $matricula,
                    $dataNascimento,
                    $dataAdmissao,
                    $pending,
                    $token,
                    $this->httpTimeout,
                    $this->httpConnectTimeout,
                    $stage,
                    $attempt
                );
            } catch (Throwable $e) {
                $msg = 'Exceção na análise de política de crédito (pool): ' . $e->getMessage();
                foreach ($pending as $idx => $_) {
                    $results[$idx] = $this->policyErrorResult($msg, true);
                }
                break;
            }

            $retry401 = [];
            foreach ($pending as $idx => $item) {
                $resp = $responses[$idx] ?? null;
                if ($resp instanceof HttpResponse && $resp->status() === 401) {
                    $retry401[$idx] = $item;
                }
            }

            if (!empty($retry401)) {
                Cache::forget('facta_token');
                $this->clearPreAuthGrantCache();

                try {
                    $tokenRefreshed = $this->getTokenWithBackoff('analise-politica-credito-lote:refresh_401');
                    $token = $tokenRefreshed;

                    $retry401Responses = $this->requestAnalisePoliticaCreditoPool(
                        $cpf,
                        $matricula,
                        $dataNascimento,
                        $dataAdmissao,
                        $retry401,
                        $token,
                        $this->httpSecondTimeout,
                        $this->httpSecondConnectTimeout,
                        'retry_401_pool',
                        $attempt
                    );

                    foreach ($retry401 as $idx => $_) {
                        if (isset($retry401Responses[$idx]) && $retry401Responses[$idx] instanceof HttpResponse) {
                            $responses[$idx] = $retry401Responses[$idx];
                        } else {
                            $results[$idx] = $this->policyErrorResult('Sem resposta do serviço após renovar token.', true);
                            unset($pending[$idx]);
                        }
                    }
                } catch (Throwable $e) {
                    $msg = 'Falha ao renovar token FACTA: ' . $e->getMessage();
                    foreach ($retry401 as $idx => $_) {
                        $results[$idx] = $this->policyErrorResult($msg, true);
                        unset($pending[$idx]);
                    }
                }
            }

            if (empty($pending)) {
                break;
            }

            $retry429 = [];
            $retryAfterMax = null;

            foreach ($pending as $idx => $item) {
                $resp = $responses[$idx] ?? null;
                if (!$resp instanceof HttpResponse) {
                    $results[$idx] = $this->policyErrorResult('Sem resposta na análise de política de crédito.', true);
                    unset($pending[$idx]);
                    continue;
                }

                if ($resp->status() === 429) {
                    $retry429[$idx] = $item;
                    $retryAfter = $this->getRetryAfterSeconds($resp);
                    if ($retryAfter !== null) {
                        $retryAfterMax = $retryAfterMax === null ? $retryAfter : max($retryAfterMax, $retryAfter);
                    }
                    continue;
                }

                $results[$idx] = $this->parseAnalisePoliticaCreditoResponse($resp);
                unset($pending[$idx]);
            }

            if (!empty($retry429) && $rateLimitAttempt < $maxRateLimitRetries) {
                $rateLimitAttempt++;
                $this->sleepBeforePolicy429Retry($cpf, $rateLimitAttempt, $retryAfterMax, count($retry429));
                $pending = $retry429;
                continue;
            }

            foreach ($retry429 as $idx => $_) {
                $resp = $responses[$idx] ?? null;
                if ($resp instanceof HttpResponse) {
                    $results[$idx] = $this->parseAnalisePoliticaCreditoResponse($resp);
                } else {
                    $results[$idx] = $this->policyErrorResult('Sem resposta na análise de política de crédito.', true);
                }
            }
            break;
        }

        ksort($results);
        return $results;
    }

    /**
     * @param array<int,array{prazo:int,valorEmprestimo:string}> $policyChunk
     * @return array<int,HttpResponse>
     */
    private function requestAnalisePoliticaCreditoPool(
        string $cpf,
        string $matricula,
        string $dataNascimento,
        string $dataAdmissao,
        array $policyChunk,
        string $token,
        int $timeout,
        int $connectTimeout,
        string $stage,
        int $attempt
    ): array {
        if (empty($policyChunk)) {
            return [];
        }

        $entries = [];
        foreach ($policyChunk as $idx => $item) {
            if (!is_array($item)) {
                continue;
            }

            $prazo = isset($item['prazo']) && is_numeric($item['prazo']) ? (int) $item['prazo'] : 0;
            $valorEmprestimo = (string) ($item['valorEmprestimo'] ?? '');
            if ($prazo <= 0 || $valorEmprestimo === '') {
                continue;
            }

            $entries["tb_{$idx}"] = [
                'idx' => $idx,
                'prazo' => $prazo,
                'valorEmprestimo' => $valorEmprestimo,
            ];
        }

        if (empty($entries)) {
            return [];
        }

        $out = [];
        $aliases = array_keys($entries);
        $windowSize = max(1, min($this->httpPolicyPoolWindow, count($aliases)));
        $aliasWindows = $windowSize >= count($aliases)
            ? [$aliases]
            : array_chunk($aliases, $windowSize);

        foreach ($aliasWindows as $windowAliases) {
            $this->waitForFactaRateLimit(count($windowAliases));

            $responses = Http::pool(function (Pool $pool) use (
                $windowAliases,
                $entries,
                $token,
                $timeout,
                $connectTimeout,
                $cpf,
                $matricula,
                $dataNascimento,
                $dataAdmissao
            ) {
                $reqs = [];
                foreach ($windowAliases as $alias) {
                    $entry = $entries[$alias] ?? null;
                    if (!is_array($entry)) {
                        continue;
                    }

                    $reqs[] = $pool->as($alias)
                        ->withHeaders([
                            'Authorization' => 'Bearer ' . $token,
                            'Accept' => 'application/json',
                        ])
                        ->timeout($timeout)
                        ->connectTimeout($connectTimeout)
                        ->get($this->baseUrl . '/consignado-trabalhador/analise-politica-credito', [
                            'cpf' => $cpf,
                            'matricula' => $matricula,
                            'dataNascimento' => $dataNascimento,
                            'dataAdmissao' => $dataAdmissao,
                            'prazo' => $entry['prazo'],
                            'valorEmprestimo' => $entry['valorEmprestimo'],
                        ]);
                }

                return $reqs;
            });

            foreach ($responses as $alias => $resp) {
                $entry = $entries[$alias] ?? null;
                if (!is_array($entry) || !$resp instanceof HttpResponse) {
                    continue;
                }

                $this->logAnalisePoliticaCreditoResponse(
                    $resp,
                    $cpf,
                    $stage,
                    $attempt,
                    (int) $entry['prazo'],
                    (string) $entry['valorEmprestimo']
                );

                if ($resp->status() === 403) {
                    $this->logForbidden($resp, $cpf);
                }

                $out[(int) $entry['idx']] = $resp;
            }
        }

        return $out;
    }

    private function sleepBeforePolicy429Retry(
        string $cpf,
        int $attempt,
        ?int $retryAfterSeconds,
        int $batchSize
    ): void {
        $pauseSeconds = $retryAfterSeconds !== null && $retryAfterSeconds > 0
            ? $retryAfterSeconds
            : $this->httpRateLimitDefaultPauseSeconds;

        $pauseSeconds = min($this->httpRateLimitPauseCapSeconds, max(1, $pauseSeconds));

        CltLog::warning('[FACTA] 429 backoff (analise-politica-credito pool)', [
            'cpf' => $cpf,
            'attempt' => $attempt,
            'batch_size' => $batchSize,
            'retry_after' => $retryAfterSeconds,
            'sleep_seconds' => $pauseSeconds,
        ]);

        sleep($pauseSeconds);
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
                'retriable' => $this->isRetriableHttpStatus($status, $looksHtml, $mensagem),
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
                'retriable' => $this->isRetriableHttpStatus($status, $looksHtml, $mensagem),
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
        // Requisito: logar somente /gera-token, /autoriza-consulta e /solicita-autorizacao-consulta.
        return;
    }

    private function logAnalisePoliticaCreditoResponse(
        HttpResponse $resp,
        string $cpf,
        string $stage,
        int $attempt,
        int $prazo,
        string $valorEmprestimo
    ): void {
        // Requisito: logar somente /gera-token, /autoriza-consulta e /solicita-autorizacao-consulta.
        return;
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

        $responses = [];
        $windowSize = max(1, min($this->httpAutorizaPoolWindow, count($cpfs)));
        $cpfWindows = $windowSize >= count($cpfs)
            ? [$cpfs]
            : array_chunk($cpfs, $windowSize);

        foreach ($cpfWindows as $windowCpfs) {
            $this->waitForFactaRateLimit(count($windowCpfs));
            $poolStartedAtMs = (int) round(microtime(true) * 1000);

            $windowResponses = Http::pool(function (Pool $pool) use ($windowCpfs, $headers, $url, $timeout, $connectTimeout) {
                $reqs = [];
                foreach ($windowCpfs as $cpf) {
                    $reqs[] = $pool->as($cpf)
                        ->withHeaders($headers)
                        ->timeout($timeout)
                        ->connectTimeout($connectTimeout)
                        ->get($url, ['cpf' => $cpf]);
                }
                return $reqs;
            });

            foreach ($windowResponses as $cpf => $resp) {
                if (!$resp instanceof HttpResponse) {
                    continue;
                }

                $responses[(string) $cpf] = $resp;
                if ($this->logFactaResponses) {
                    $this->logAutorizaConsultaResponse($resp, (string) $cpf, $stage, $attempt, $poolStartedAtMs);
                }
            }
        }

        return $responses;
    }

    private function isTransientHttpStatus(int $status): bool
    {
        return in_array($status, [408, 429], true) || $status >= 500;
    }

    private function isTransientBodyShape(HttpResponse $resp): bool
    {
        $status = $resp->status();

        if ($status !== 200) {
            return false;
        }

        $body = trim((string) $resp->body());
        if ($body === '') {
            return true;
        }

        if ($this->looksLikeHtml($body)) {
            return true;
        }

        try {
            $json = $resp->json();
            return !is_array($json);
        } catch (Throwable) {
            return true;
        }
    }

    private function isTransientSolicitaResponse(HttpResponse $resp): bool
    {
        return $this->isTransientHttpStatus($resp->status()) || $this->isTransientBodyShape($resp);
    }

    private function isTransientAutorizaResponse(HttpResponse $resp): bool
    {
        if ($this->isTransientHttpStatus($resp->status()) || $this->isTransientBodyShape($resp)) {
            return true;
        }

        try {
            $json = $resp->json();
            if (!is_array($json)) {
                return false;
            }

            $msgRaw = trim((string) ($json['mensagem'] ?? $json['message'] ?? ''));
            if ($msgRaw !== '' && $this->looksLikeHtml($msgRaw)) {
                return true;
            }
        } catch (Throwable) {
            // ignore
        }

        return false;
    }

    /**
     * Decide se o subset de respostas transitórias pode ser retentado no mesmo ciclo.
     *
     * @param array<string,HttpResponse> $responses
     * @param array<int,string> $retryCpfs
     */
    private function canRetryTransientPoolSubset(array $responses, array $retryCpfs, ?int $retryAfterMax): bool
    {
        $has429 = false;

        foreach ($retryCpfs as $cpf) {
            $resp = $responses[$cpf] ?? null;
            if ($resp instanceof HttpResponse && $resp->status() === 429) {
                $has429 = true;
                break;
            }
        }

        if (!$has429) {
            return true;
        }

        return $this->shouldRetry429Immediately($retryAfterMax);
    }

    private function sleepBeforeTransientRetry(
        string $endpoint,
        ?int $retryAfterSeconds,
        ?string $cpf,
        int $attempt,
        ?int $batchSize = null,
        string $reason = 'transient'
    ): void {
        $baseSeconds = max(1, $this->httpTransientPauseSeconds);
        $pauseSeconds = $baseSeconds;

        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            $pauseSeconds = min(
                $this->httpRateLimitPauseCapSeconds,
                max($baseSeconds, $retryAfterSeconds)
            );
        }

        $jitterMs = random_int(0, 400);
        CltLog::warning('[FACTA] transient backoff', [
            'endpoint' => $endpoint,
            'reason' => $reason,
            'cpf' => $cpf,
            'attempt' => $attempt,
            'batch_size' => $batchSize,
            'retry_after' => $retryAfterSeconds,
            'sleep_seconds' => $pauseSeconds,
            'jitter_ms' => $jitterMs,
        ]);

        if ($pauseSeconds > 0) {
            sleep($pauseSeconds);
        }
        if ($jitterMs > 0) {
            usleep($jitterMs * 1000);
        }
    }

    private function sleepBeforeImmediate429Retry(
        string $endpoint,
        ?int $retryAfterSeconds,
        ?string $cpf,
        int $attempt,
        ?int $batchSize = null
    ): void {
        if (!$this->shouldRetry429Immediately($retryAfterSeconds)) {
            CltLog::warning('[FACTA] 429 sem retry no mesmo ciclo (delegado ao backoff do job)', [
                'endpoint' => $endpoint,
                'cpf' => $cpf,
                'attempt' => $attempt,
                'batch_size' => $batchSize,
                'retry_after' => $retryAfterSeconds,
            ]);
            return;
        }

        $this->sleepBeforeTransientRetry(
            $endpoint,
            $retryAfterSeconds,
            $cpf,
            $attempt,
            $batchSize,
            'http_429'
        );
    }

    private function shouldRetry429Immediately(?int $retryAfterSeconds): bool
    {
        return $retryAfterSeconds === null || $retryAfterSeconds <= $this->httpRateLimitPauseCapSeconds;
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

    private function policyErrorResult(string $mensagem, bool $retriable, ?int $httpStatus = null, ?int $retryAfter = null): array
    {
        return [
            'ok' => false,
            'aprovado' => false,
            'mensagem' => $mensagem,
            'valor_maximo_disponivel' => null,
            'prazo_maximo_disponivel' => null,
            'retriable' => $retriable,
            'http_status' => $httpStatus,
            'retry_after' => $retryAfter,
        ];
    }

    private function hasPreAuthGrant(string $cpf): bool
    {
        $nowTs = microtime(true);

        if (isset($this->preAuthApprovedLocal[$cpf])) {
            $expiresAt = (float) $this->preAuthApprovedLocal[$cpf];
            if ($expiresAt >= $nowTs) {
                return true;
            }

            unset($this->preAuthApprovedLocal[$cpf]);
        }

        if ($this->preAuthPersistTtlDays <= 0) {
            return false;
        }

        if (
            array_key_exists($cpf, $this->preAuthLookupCheckedLocal)
            && $this->preAuthLookupCheckedLocal[$cpf] === false
        ) {
            return false;
        }

        try {
            $nowUtc = now('UTC')->format('Y-m-d H:i:s');
            $row = DB::table('clt_pre_authorizations')
                ->where('cpf', $cpf)
                ->where('expires_at', '>', $nowUtc)
                ->select('expires_at')
                ->first();

            if ($row === null) {
                $this->preAuthLookupCheckedLocal[$cpf] = false;
                return false;
            }

            $expiresAt = strtotime((string) ($row->expires_at ?? ''));
            if (!is_int($expiresAt) || $expiresAt <= 0) {
                $this->preAuthLookupCheckedLocal[$cpf] = false;
                return false;
            }

            $this->preAuthApprovedLocal[$cpf] = (float) $expiresAt;
            $this->preAuthLookupCheckedLocal[$cpf] = true;
            return true;
        } catch (Throwable $e) {
            $this->preAuthLookupCheckedLocal[$cpf] = false;
            CltLog::warning('[FACTA] Falha ao consultar cache persistente de pré-autorização.', [
                'cpf' => $cpf,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function markPreAuthGrant(string $cpf, bool $persist = true): void
    {
        $nowTs = microtime(true);
        $localExpiryTs = null;

        if ($this->preAuthCacheTtl > 0) {
            $localExpiryTs = $nowTs + max(1, $this->preAuthCacheTtl);
        }

        if ($persist && $this->preAuthPersistTtlDays > 0) {
            $persistExpiryTs = $nowTs + ($this->preAuthPersistTtlDays * 86400);
            $localExpiryTs = $localExpiryTs === null
                ? $persistExpiryTs
                : max($localExpiryTs, $persistExpiryTs);
            $this->queuePreAuthGrantPersist($cpf);
        }

        if ($localExpiryTs !== null) {
            $this->preAuthApprovedLocal[$cpf] = $localExpiryTs;
            $this->preAuthLookupCheckedLocal[$cpf] = true;
        }
    }

    private function clearPreAuthGrantCache(): void
    {
        $this->preAuthApprovedLocal = [];
        $this->preAuthLookupCheckedLocal = [];
    }

    private function queuePreAuthGrantPersist(string $cpf): void
    {
        if ($this->preAuthPersistTtlDays <= 0) {
            return;
        }

        $this->preAuthPersistQueue[$cpf] = true;
        if (count($this->preAuthPersistQueue) >= $this->preAuthPersistBatchSize) {
            $this->flushPreAuthGrantPersistQueue();
        }
    }

    private function flushPreAuthGrantPersistQueue(): void
    {
        if ($this->preAuthPersistTtlDays <= 0 || empty($this->preAuthPersistQueue)) {
            return;
        }

        $cpfs = array_keys($this->preAuthPersistQueue);
        $this->preAuthPersistQueue = [];
        $cpfs = array_values(array_unique(array_filter($cpfs, fn($cpf) => is_string($cpf) && strlen($cpf) === 11)));
        if (empty($cpfs)) {
            return;
        }

        try {
            $nowUtc = now('UTC');
            $nowStr = $nowUtc->format('Y-m-d H:i:s');
            $expiresStr = $nowUtc->copy()->addDays($this->preAuthPersistTtlDays)->format('Y-m-d H:i:s');

            $rows = [];
            foreach ($cpfs as $cpf) {
                $rows[] = [
                    'cpf' => $cpf,
                    'authorized_at' => $nowStr,
                    'expires_at' => $expiresStr,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ];
            }

            DB::table('clt_pre_authorizations')->upsert(
                $rows,
                ['cpf'],
                ['authorized_at', 'expires_at', 'updated_at']
            );
        } catch (Throwable $e) {
            CltLog::warning('[FACTA] Falha ao persistir cache em lote de pré-autorização.', [
                'batch_size' => count($cpfs),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<int,string> $cpfs */
    private function warmPreAuthGrants(array $cpfs): void
    {
        if ($this->preAuthPersistTtlDays <= 0 || empty($cpfs)) {
            return;
        }

        $normalized = [];
        foreach ($cpfs as $cpf) {
            $digits = preg_replace('/\D+/', '', (string) $cpf);
            if (is_string($digits) && strlen($digits) === 11) {
                $normalized[] = $digits;
            }
        }

        $normalized = array_values(array_unique($normalized));
        if (empty($normalized)) {
            return;
        }

        foreach ($normalized as $cpf) {
            $this->preAuthLookupCheckedLocal[$cpf] = false;
        }

        try {
            $nowUtc = now('UTC')->format('Y-m-d H:i:s');
            $rows = DB::table('clt_pre_authorizations')
                ->whereIn('cpf', $normalized)
                ->where('expires_at', '>', $nowUtc)
                ->select('cpf', 'expires_at')
                ->get();

            $reusedCpfs = [];
            foreach ($rows as $row) {
                $cpf = (string) ($row->cpf ?? '');
                if ($cpf === '') {
                    continue;
                }

                $expiresAt = strtotime((string) ($row->expires_at ?? ''));
                if (!is_int($expiresAt) || $expiresAt <= 0) {
                    continue;
                }

                $this->preAuthApprovedLocal[$cpf] = (float) $expiresAt;
                $this->preAuthLookupCheckedLocal[$cpf] = true;
                $reusedCpfs[] = $cpf;
            }

            if (!empty($reusedCpfs)) {
                $sample = array_slice($reusedCpfs, 0, 8);
                CltLog::warning('[FACTA] pré-autorização reaproveitada do banco (sem /solicita-autorizacao-consulta)', [
                    'job_id' => $this->runtimeJobId,
                    'reused_count' => count($reusedCpfs),
                    'sample_cpfs' => $sample,
                    'sample_extra' => max(0, count($reusedCpfs) - count($sample)),
                ]);
            }
        } catch (Throwable $e) {
            CltLog::warning('[FACTA] Falha ao carregar cache persistente de pré-autorização em lote.', [
                'batch_size' => count($normalized),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sleepPreAuthCooldown(?float $latestPreAuthAt): void
    {
        if ($latestPreAuthAt === null || $this->preAuthPostCooldownMs <= 0) {
            return;
        }

        $elapsedMs = (int) floor((microtime(true) - $latestPreAuthAt) * 1000);
        $remainingMs = $this->preAuthPostCooldownMs - max(0, $elapsedMs);

        if ($remainingMs > 0) {
            usleep($remainingMs * 1000);
        }
    }

    private function solicitaAutorizacaoConsulta(string $cpf, string &$token): array
    {
        $maxAttempts = max(1, $this->preAuthPhoneAttempts);
        $maxTransientRetries = $this->httpRateLimitImmediateRetry ? $this->httpRateLimitMaxRetries : 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $celular = $this->generateRandomCellular();

            $transientRetryAttempt = 0;
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
                        $token = $this->getTokenWithBackoff('solicita-autorizacao-consulta:refresh_401');

                        $resp = $this->postSolicitaAutorizacaoConsulta($cpf, $token, $celular);
                        $this->logSolicitaAutorizacaoResponse($resp, $cpf, $celular, $attempt, 'after_401_refresh');
                        if ($resp->status() === 403) {
                            $this->logForbidden($resp, $cpf);
                        }
                    }
                } catch (Throwable $e) {
                    if (
                        $transientRetryAttempt < $maxTransientRetries
                        && ($this->isTimeoutException($e) || $this->isConnectionException($e))
                    ) {
                        $transientRetryAttempt++;
                        $this->sleepBeforeTransientRetry(
                            'solicita-autorizacao-consulta',
                            null,
                            $cpf,
                            $transientRetryAttempt,
                            null,
                            'request_exception'
                        );
                        continue;
                    }

                    $this->logRequestException('/solicita-autorizacao-consulta', $e, [
                        'cpf' => $cpf,
                        'celular' => $celular,
                        'stage' => 'request_exception',
                        'attempt' => $attempt,
                        'rate_limit_attempt' => $transientRetryAttempt,
                    ]);
                    return [
                        'ok' => false,
                        'mensagem' => 'Pré-autorização: Exceção: ' . $e->getMessage(),
                        'retriable' => true,
                        'http_status' => null,
                        'retry_after' => null,
                    ];
                }

                if (
                    $transientRetryAttempt < $maxTransientRetries
                    && $this->isTransientSolicitaResponse($resp)
                ) {
                    $retryAfter = $this->getRetryAfterSeconds($resp);
                    if ($resp->status() === 429 && !$this->shouldRetry429Immediately($retryAfter)) {
                        break;
                    }

                    $transientRetryAttempt++;
                    $this->sleepBeforeTransientRetry(
                        'solicita-autorizacao-consulta',
                        $retryAfter,
                        $cpf,
                        $transientRetryAttempt,
                        null,
                        'transient_response'
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

                $retriable = $this->isRetriableHttpStatus($status, $looksHtml, $mensagem);

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
        if (!$this->logFactaResponses) {
            return;
        }

        try {
            $status = $resp->status();
            $body = (string) $resp->body();
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

            $elapsedMs = $this->extractElapsedMs($resp);
            $context = [
                'job_id' => $this->runtimeJobId,
                'cpf' => $cpf,
                'celular' => $celular,
                'attempt' => $attempt,
                'stage' => $stage,
                'http_status' => $status,
                'elapsed_ms' => $elapsedMs,
                'logged_at_ms' => (int) round(microtime(true) * 1000),
                'erro' => $erro,
                'mensagem' => $mensagem,
            ];
            if ($status >= 400 || $erro === true) {
                $context = array_merge($context, $this->compactResponseLogContext($body, $json, $status));
            }

            $shouldLog = ($status >= 400 || $erro === true) || $this->shouldLogFactaResponse($resp);
            if ($shouldLog) {
                CltLog::warning('[FACTA] /solicita-autorizacao-consulta response', $context);
            }
        } catch (Throwable $e) {
            CltLog::warning('[FACTA] Falha ao logar /solicita-autorizacao-consulta: ' . $e->getMessage());
        }
    }

    private function logGeraTokenResponse(HttpResponse $resp, string $stage): void
    {
        if (!$this->logFactaResponses) {
            return;
        }

        try {
            $status = $resp->status();
            $body = (string) $resp->body();
            $json = null;
            $mensagem = null;
            $erro = null;
            $isJsonArray = false;

            try {
                $decoded = $resp->json();
                if (is_array($decoded)) {
                    $isJsonArray = true;
                    $json = $decoded;
                    $mensagem = (string) ($decoded['mensagem'] ?? $decoded['message'] ?? '');
                    if (array_key_exists('erro', $decoded)) {
                        $erro = (bool) $decoded['erro'];
                    }
                }
            } catch (Throwable) {
                // mantém fallback para body bruto
            }

            $mensagemStr = is_string($mensagem) ? trim($mensagem) : '';
            $mensagemHtml = $mensagemStr !== '' && $this->looksLikeHtml($mensagemStr);
            $bodyHtml = $body !== '' && $this->looksLikeHtml($body);
            $invalidBody = (!$isJsonArray && $status === 200) || $mensagemHtml;
            if ($bodyHtml && ($status === 200 || !$isJsonArray)) {
                $invalidBody = true;
            }

            $outcome = 'success';
            if ($invalidBody) {
                $outcome = 'invalid_body';
            } elseif ($status >= 400 || $erro === true) {
                $outcome = 'error';
            }

            $context = [
                'job_id' => $this->runtimeJobId,
                'stage' => $stage,
                'http_status' => $status,
                'outcome' => $outcome,
                'erro' => $erro,
                'mensagem' => $mensagem,
            ];
            if ($outcome !== 'success') {
                $context = array_merge($context, $this->compactResponseLogContext($body, $json, $status));
            }

            $shouldLog = ($outcome !== 'success') || $this->shouldLogFactaResponse($resp);
            if ($shouldLog) {
                CltLog::warning('[FACTA] /gera-token response', $context);
            }
        } catch (Throwable $e) {
            CltLog::warning('[FACTA] Falha ao logar /gera-token: ' . $e->getMessage());
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
        int $attempt,
        ?int $poolStartedAtMs = null
    ): void {
        if (!$this->logFactaResponses) {
            return;
        }

        try {
            $status = $resp->status();
            $body = (string) $resp->body();
            $json = null;
            $mensagem = null;
            $erro = null;
            $isJsonArray = false;

            try {
                $decoded = $resp->json();
                if (is_array($decoded)) {
                    $isJsonArray = true;
                    $json = $decoded;
                    $mensagem = (string) ($decoded['mensagem'] ?? $decoded['message'] ?? '');
                    if (array_key_exists('erro', $decoded)) {
                        $erro = (bool) $decoded['erro'];
                    }
                }
            } catch (Throwable) {
                // mantém fallback para body bruto
            }

            $mensagemStr = is_string($mensagem) ? trim($mensagem) : '';
            $mensagemHtml = $mensagemStr !== '' && $this->looksLikeHtml($mensagemStr);
            $bodyHtml = $body !== '' && $this->looksLikeHtml($body);
            $invalidBody = (!$isJsonArray && $status === 200) || $mensagemHtml;
            if ($bodyHtml && ($status === 200 || !$isJsonArray)) {
                $invalidBody = true;
            }

            $outcome = 'success';
            if ($invalidBody) {
                $outcome = 'invalid_body';
            } elseif ($status >= 400 || $erro === true) {
                $outcome = 'error';
            }

            $elapsedMs = $this->extractElapsedMs($resp);
            $mensagem = is_string($mensagem) ? trim($mensagem) : $mensagem;
            $context = [
                'job_id' => $this->runtimeJobId,
                'cpf' => $cpf,
                'stage' => $stage,
                'http_status' => $status,
                'elapsed_ms' => $elapsedMs,
                'outcome' => $outcome,
            ];

            if ($attempt > 1 || $outcome !== 'success') {
                $context['attempt'] = $attempt;
            }

            if ($mensagem !== null && $mensagem !== '') {
                $context['mensagem'] = $mensagem;
            }

            if ($outcome !== 'success') {
                $context['erro'] = $erro;
                if ($poolStartedAtMs !== null) {
                    $context['pool_started_at_ms'] = $poolStartedAtMs;
                }
                $context = array_merge($context, $this->compactResponseLogContext($body, $json, $status, 320));
            }

            $shouldLog = ($outcome !== 'success') || $this->shouldLogFactaResponse($resp);
            if ($shouldLog) {
                CltLog::warning('[FACTA] /autoriza-consulta response', $context);
            }
        } catch (Throwable $e) {
            CltLog::warning('[FACTA] Falha ao logar /autoriza-consulta: ' . $e->getMessage());
        }
    }

    private function extractElapsedMs(HttpResponse $resp): ?int
    {
        try {
            $stats = $resp->handlerStats();
            if (!is_array($stats)) {
                return null;
            }

            $totalTime = $stats['total_time'] ?? null;
            if (!is_numeric($totalTime)) {
                return null;
            }

            return (int) round(((float) $totalTime) * 1000);
        } catch (Throwable) {
            return null;
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
        $this->waitForFactaRateLimit();

        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])
            ->asForm()
            ->timeout($this->httpTimeout)
            ->connectTimeout($this->httpConnectTimeout)
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
            $mensagem = $this->responseMessage($resp);

            // Se vier HTML, já tratamos como temporário; manter coerência:
            $looksHtml = false;
            try {
                $body = (string) $resp->body();
                $looksHtml = ($body !== '') && $this->looksLikeHtml($body);
            } catch (\Throwable $e) {
                // ignore
            }

            $retriable = $this->isRetriableHttpStatus($status, $looksHtml, $mensagem);

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

    /** @param array<string,mixed> $headers */
    private function compactHeadersForLog(array $headers, int $maxHeaders = 12, int $maxValueLen = 120): array
    {
        $safe = [];
        $count = 0;

        foreach ($headers as $k => $vals) {
            if ($count >= $maxHeaders) {
                break;
            }

            $key = (string) $k;
            if (stripos($key, 'authorization') === 0 || stripos($key, 'cookie') === 0 || stripos($key, 'set-cookie') === 0) {
                $safe[$key] = ['REDACTED'];
            } else {
                $safe[$key] = array_map(
                    fn ($v) => $this->truncate((string) $v, $maxValueLen),
                    (array) $vals
                );
            }

            $count++;
        }

        $extraHeaders = count($headers) - $count;
        if ($extraHeaders > 0) {
            $safe['_extra_headers'] = $extraHeaders;
        }

        return $safe;
    }

    private function compactResponseLogContext(string $body, ?array $json, int $status, int $snippetMax = 700): array
    {
        $trimmedBody = trim($body);

        if (is_array($json)) {
            $keys = array_keys($json);
            $context = [
                'body_type' => 'json',
                'json_keys' => array_slice($keys, 0, 12),
            ];

            $extraKeys = count($keys) - count($context['json_keys']);
            if ($extraKeys > 0) {
                $context['json_keys_extra'] = $extraKeys;
            }

            if ($status >= 400 && $trimmedBody !== '') {
                $context['body_snippet'] = $this->truncate($trimmedBody, $snippetMax);
            }

            return $context;
        }

        if ($trimmedBody === '') {
            return ['body_type' => 'empty'];
        }

        return [
            'body_type' => $this->looksLikeHtml($trimmedBody) ? 'html' : 'text',
            'body_snippet' => $this->truncate($trimmedBody, $snippetMax),
        ];
    }

    /** @param array<string,mixed> $context */
    private function logRequestException(string $endpoint, Throwable $e, array $context = []): void
    {
        if (!$this->logFactaResponses) {
            return;
        }

        CltLog::warning("[FACTA] {$endpoint} request exception", array_merge([
            'job_id' => $this->runtimeJobId,
            'logged_at_ms' => (int) round(microtime(true) * 1000),
            'is_timeout' => $this->isTimeoutException($e),
            'is_connection_exception' => $this->isConnectionException($e),
            'exception_class' => get_class($e),
            'error' => $e->getMessage(),
        ], $context));
    }

    private function isConnectionException(Throwable $e): bool
    {
        $current = $e;
        while ($current instanceof Throwable) {
            if ($current instanceof ConnectionException) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    private function isTimeoutException(Throwable $e): bool
    {
        $current = $e;
        while ($current instanceof Throwable) {
            $msg = mb_strtolower((string) $current->getMessage(), 'UTF-8');

            if (
                str_contains($msg, 'timeout')
                || str_contains($msg, 'timed out')
                || str_contains($msg, 'curl error 28')
                || (is_numeric($current->getCode()) && (int) $current->getCode() === 28)
            ) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
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

    private function isRetriableHttpStatus(int $status, bool $looksHtml, ?string $mensagem = null): bool
    {
        if ($status === 403) {
            return $looksHtml || $this->isRetryableForbiddenMessage((string) $mensagem);
        }

        return in_array($status, [401, 408, 429], true) || $status >= 500 || $looksHtml;
    }

    private function isRetryableForbiddenMessage(string $mensagem): bool
    {
        $norm = $this->normalize($mensagem);
        if ($norm === '') {
            return false;
        }

        return str_contains($norm, 'temporar')
            || str_contains($norm, 'rate limit')
            || str_contains($norm, 'too many')
            || str_contains($norm, 'timeout')
            || str_contains($norm, 'gateway')
            || str_contains($norm, 'cloudflare')
            || str_contains($norm, 'waf')
            || str_contains($norm, 'try again')
            || str_contains($norm, 'tente novamente');
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

    private function isPrazoMinimoPolicyApprovalMessage(string $mensagem): bool
    {
        $norm = $this->normalize($mensagem);
        if ($norm === '' || !str_contains($norm, 'politica de credito')) {
            return false;
        }

        return preg_match('/prazo minimo\D*\d+\s*parcelas?/i', $norm) === 1;
    }

    private function isValorMaiorPermitido4000PolicyApprovalMessage(string $mensagem): bool
    {
        $norm = $this->normalize($mensagem);
        if ($norm === '' || !str_contains($norm, 'politica de credito')) {
            return false;
        }
        if (!str_contains($norm, 'valor maior que o permitido para politica de credito')) {
            return false;
        }
        if (!preg_match('/valor maior que o permitido para politica de credito\s*\(([^)]*)\)/i', $norm, $m)) {
            return false;
        }

        $valor = $this->toFloatSmart($m[1] ?? null);
        if ($valor === null) {
            return false;
        }

        return abs($valor - 4000.0) < 0.00001;
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
