<?php

namespace App\Modules\CLT\Services;

use App\Modules\CLT\Services\Exceptions\FactaFatalAuthException;
use App\Modules\CLT\Support\CltLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

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
    private int $tokenRetryBaseDelayMs;
    private int $tokenRetryMaxDelayMs;

    /** HTTP (1ª rodada) */
    private int $httpTimeout;
    private int $httpConnectTimeout;
    private bool $logFactaResponses;
    private bool $logFactaSuccessResponses;
    private bool $httpGlobalRateLimitEnabled;
    private int $httpGlobalRateLimitRps;
    private int $httpGlobalRateLimitRpm;
    private int $httpGlobalRateLimitSleepMs;
    private int $httpTransientPauseSeconds;
    private int $httpRateLimitDefaultPauseSeconds;
    private int $httpRateLimitPauseCapSeconds;
    private int $httpAutorizaTransientRetryAttempts;
    private int $httpAutorizaPoolWindow;
    private int $httpPolicyPoolWindow;

    /** Pré-autorização (CLT online) */
    private string $preAuthAverbador;
    private string $preAuthNome;
    private string $preAuthTipoEnvio;
    private int $preAuthCacheTtl;
    private int $preAuthPersistTtlDays;
    private int $preAuthPersistBatchSize;
    private int $preAuthPostCooldownMs;
    private int $preAuthPhoneRetryAttempts;
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
    private string $creditPolicySourceMode;
    private string $creditPolicyFixedValorEmprestimo;
    /** @var array<int,int> */
    private array $creditPolicyFixedPrazos = [];
    private bool $phase2CpfValidationAuditLogEnabled;
    private bool $phase2OperacoesRequestLogEnabled;
    private bool $jobHttpCountersEnabled;
    private int $jobHttpCountersFlushEvery;
    private int $jobHttpCountersFlushIntervalMs;
    private int $jobHttpCountersBuffered = 0;
    private int $jobHttpCountersLastFlushMs = 0;
    private bool $jobHttpCountersSchemaChecked = false;
    private bool $jobHttpCountersSchemaAvailable = false;
    /** @var array<string,array<string,int>> */
    private array $jobHttpCounters = [];

    private const JOB_HTTP_COUNTER_TABLE = 'clt_job_http_counters';
    /** @var array<int,string> */
    private const JOB_HTTP_COUNTER_FIELDS = [
        'request_count',
        'response_count',
        'status_2xx_count',
        'status_4xx_count',
        'status_5xx_count',
        'status_other_count',
        'exception_count',
        'timeout_count',
        'connection_exception_count',
        'no_response_count',
    ];
    private const TOKEN_TRANSIENT_MAX_ATTEMPTS = 8;
    private const TOKEN_ABORT_MSG_FATAL_IMMEDIATE = 'Processamento abortado: Usuário ou senha inválida.';
    private const TOKEN_ABORT_MSG_TRANSIENT_EXHAUSTED = 'Processamento abortado: Não foi possível obter token FACTA após múltiplas tentativas.';
    private const CREDIT_POLICY_INTERNAL_APPROVAL_MIN_VALUE = 500.0;
    private const CREDIT_POLICY_SOURCE_MODE_OPERACOES = 'operacoes';
    private const CREDIT_POLICY_SOURCE_MODE_EXPERIMENTAL = 'experimental';
    private const CREDIT_POLICY_SOURCE_MODE_FIXED = 'fixed';
    /** @var array<int,int> */
    private const CREDIT_POLICY_FIXED_PRAZOS_DEFAULT = [6, 8, 10, 12, 14, 15, 18, 20, 24, 30, 36, 42, 48];

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
        $this->tokenRetryBaseDelayMs = max(0, (int) ($api['token_retry_base_delay_ms'] ?? 1000));
        $this->tokenRetryMaxDelayMs = max($this->tokenRetryBaseDelayMs, (int) ($api['token_retry_max_delay_ms'] ?? 30000));

        // HTTP (1ª)
        $this->httpTimeout = (int) ($http['timeout'] ?? 30);
        $this->httpConnectTimeout = (int) ($http['connect_timeout'] ?? 10);

        $this->logFactaResponses = (bool) config('cltfacta.logging.facta_log_responses', true);
        $this->logFactaSuccessResponses = (bool) config('cltfacta.logging.facta_log_success_responses', false);
        $this->httpGlobalRateLimitRps = max(0, (int) ($http['global_rate_limit_rps'] ?? 4));
        $this->httpGlobalRateLimitRpm = max(0, (int) ($http['global_rate_limit_rpm'] ?? 180));
        $this->httpGlobalRateLimitSleepMs = max(50, (int) ($http['global_rate_limit_sleep_ms'] ?? 120));
        $this->httpTransientPauseSeconds = max(1, (int) ($http['transient_pause_seconds'] ?? 3));
        $this->httpRateLimitDefaultPauseSeconds = max(1, (int) ($http['rate_limit_default_pause_seconds'] ?? 3));
        $this->httpRateLimitPauseCapSeconds = max($this->httpRateLimitDefaultPauseSeconds, (int) ($http['rate_limit_pause_cap_seconds'] ?? 30));
        $this->httpAutorizaTransientRetryAttempts = max(0, (int) ($http['autoriza_transient_retry_attempts'] ?? 1));
        $this->httpGlobalRateLimitEnabled = (bool) ($http['global_rate_limit_enabled'] ?? true)
            && ($this->httpGlobalRateLimitRps > 0 || $this->httpGlobalRateLimitRpm > 0);
        $this->httpAutorizaPoolWindow = max(1, (int) ($http['autoriza_pool_window'] ?? 4));
        $this->httpPolicyPoolWindow = max(1, (int) ($http['policy_pool_window'] ?? 4));

        // Pré-autorização obrigatória antes do autoriza-consulta
        $this->preAuthAverbador = (string) ($api['pre_auth_averbador'] ?? '10010');
        $this->preAuthNome = (string) ($api['pre_auth_nome'] ?? 'slkjhdsjkha asdkjhd iou');
        $this->preAuthTipoEnvio = (string) ($api['pre_auth_tipo_envio'] ?? 'WHATSAPP');
        $this->preAuthCacheTtl = max(0, (int) ($api['pre_auth_cache_ttl'] ?? 1800));
        $this->preAuthPersistTtlDays = max(0, (int) ($api['pre_auth_persist_ttl_days'] ?? 30));
        $this->preAuthPersistBatchSize = max(1, (int) ($api['pre_auth_persist_batch_size'] ?? 100));
        $this->preAuthPostCooldownMs = max(0, (int) ($api['pre_auth_post_cooldown_ms'] ?? 3000));
        $this->preAuthPhoneRetryAttempts = max(1, (int) ($api['pre_auth_phone_retry_attempts'] ?? 3));

        // Continuação (crédito trabalhador) - somente online
        $this->creditProduto = (string) ($credit['produto'] ?? 'D');
        $this->creditTipoOperacao = (string) ($credit['tipo_operacao'] ?? '13');
        $this->creditAverbador = (string) ($credit['averbador'] ?? '10010');
        $this->creditConvenio = (string) ($credit['convenio'] ?? '3');
        $this->creditOpcaoValor = (string) ($credit['opcao_valor'] ?? '2');
        $this->creditPolicyBatchSize = max(1, (int) ($credit['policy_batch_size'] ?? 3));
        $this->creditPolicySourceMode = $this->normalizeCreditPolicySourceMode((string) ($credit['policy_source_mode'] ?? self::CREDIT_POLICY_SOURCE_MODE_OPERACOES));
        $fixedValorEmprestimo = $this->toMoneyString($credit['policy_fixed_valor_emprestimo'] ?? 500);
        $this->creditPolicyFixedValorEmprestimo = $fixedValorEmprestimo ?? '500.00';
        $this->creditPolicyFixedPrazos = $this->normalizeCreditPolicyFixedPrazos($credit['policy_fixed_prazos'] ?? self::CREDIT_POLICY_FIXED_PRAZOS_DEFAULT);
        if (empty($this->creditPolicyFixedPrazos)) {
            $this->creditPolicyFixedPrazos = self::CREDIT_POLICY_FIXED_PRAZOS_DEFAULT;
        }
        $this->phase2CpfValidationAuditLogEnabled = (bool) config('cltfacta.logging.phase2_cpf_validation_audit_log_enabled', false);
        $this->phase2OperacoesRequestLogEnabled = (bool) config('cltfacta.logging.phase2_operacoes_request_log_enabled', false);
        $this->jobHttpCountersEnabled = (bool) config('cltfacta.logging.facta_job_http_counters_enabled', true);
        $this->jobHttpCountersFlushEvery = max(1, (int) config('cltfacta.logging.facta_job_http_counters_flush_every', 120));
        $this->jobHttpCountersFlushIntervalMs = max(500, (int) config('cltfacta.logging.facta_job_http_counters_flush_interval_ms', 10000));
        $this->jobHttpCountersLastFlushMs = (int) round(microtime(true) * 1000);
    }

    public function setRuntimeJobId(?int $jobId): self
    {
        if ($this->runtimeJobId !== null && $jobId !== $this->runtimeJobId) {
            $this->flushRuntimeHttpCounters();
            $this->jobHttpCounters = [];
            $this->jobHttpCountersBuffered = 0;
            $this->jobHttpCountersLastFlushMs = (int) round(microtime(true) * 1000);
        }

        $this->runtimeJobId = $jobId;
        return $this;
    }

    public function flushRuntimeHttpCounters(): void
    {
        $this->flushJobHttpCounters(true);
    }

    private function isJobHttpCounterActive(): bool
    {
        return $this->jobHttpCountersEnabled
            && is_int($this->runtimeJobId)
            && $this->runtimeJobId > 0;
    }

    private function normalizeEndpointCounterKey(string $endpoint): string
    {
        $key = trim($endpoint);
        if ($key === '') {
            return 'unknown';
        }

        $key = preg_replace('/^https?:\/\/[^\/]+/i', '', $key) ?? $key;
        $qPos = strpos($key, '?');
        if ($qPos !== false) {
            $key = substr($key, 0, $qPos);
        }
        $key = ltrim($key, '/');

        return $key !== '' ? $key : 'root';
    }

    /** @return array<string,int> */
    private function newJobHttpCounterRow(): array
    {
        return array_fill_keys(self::JOB_HTTP_COUNTER_FIELDS, 0);
    }

    /** @param array<string,int> $delta */
    private function addJobHttpCounter(string $endpoint, array $delta): void
    {
        if (!$this->isJobHttpCounterActive()) {
            return;
        }

        $key = $this->normalizeEndpointCounterKey($endpoint);
        if (!isset($this->jobHttpCounters[$key])) {
            $this->jobHttpCounters[$key] = $this->newJobHttpCounterRow();
        }

        $hasIncrement = false;
        foreach (self::JOB_HTTP_COUNTER_FIELDS as $field) {
            $value = (int) ($delta[$field] ?? 0);
            if ($value <= 0) {
                continue;
            }

            $this->jobHttpCounters[$key][$field] += $value;
            $hasIncrement = true;
        }

        if (!$hasIncrement) {
            return;
        }

        // Buffer por evento (request/response/exception), não por quantidade de colunas incrementadas.
        // Isso evita flush prematuro em alto volume.
        $this->jobHttpCountersBuffered++;
        $this->flushJobHttpCounters(false);
    }

    private function trackHttpRequest(string $endpoint, int $count = 1): void
    {
        if ($count <= 0) {
            return;
        }

        $this->addJobHttpCounter($endpoint, [
            'request_count' => $count,
        ]);
    }

    private function trackHttpResponse(string $endpoint, HttpResponse $resp): void
    {
        $status = $resp->status();
        $delta = [
            'response_count' => 1,
        ];

        if ($status >= 200 && $status < 300) {
            $delta['status_2xx_count'] = 1;
        } elseif ($status >= 400 && $status < 500) {
            $delta['status_4xx_count'] = 1;
        } elseif ($status >= 500 && $status < 600) {
            $delta['status_5xx_count'] = 1;
        } else {
            $delta['status_other_count'] = 1;
        }

        $this->addJobHttpCounter($endpoint, $delta);
    }

    private function trackNoResponse(string $endpoint, int $count = 1): void
    {
        if ($count <= 0) {
            return;
        }

        $this->addJobHttpCounter($endpoint, [
            'no_response_count' => $count,
        ]);
    }

    private function trackHttpException(string $endpoint, Throwable $e): void
    {
        $delta = [
            'exception_count' => 1,
        ];

        $isTimeout = $this->isTimeoutException($e);
        $isConnection = $this->isConnectionException($e);

        if ($isTimeout) {
            $delta['timeout_count'] = 1;
        } elseif ($isConnection) {
            $delta['connection_exception_count'] = 1;
        }

        $this->addJobHttpCounter($endpoint, $delta);
    }

    private function ensureJobHttpCounterTableAvailable(): bool
    {
        if ($this->jobHttpCountersSchemaChecked) {
            return $this->jobHttpCountersSchemaAvailable;
        }

        $this->jobHttpCountersSchemaChecked = true;
        try {
            $this->jobHttpCountersSchemaAvailable = Schema::hasTable(self::JOB_HTTP_COUNTER_TABLE);
            if (!$this->jobHttpCountersSchemaAvailable) {
                CltLog::warning('[FACTA] Tabela de contadores HTTP por job não encontrada; contadores desabilitados nesta execução.', [
                    'table' => self::JOB_HTTP_COUNTER_TABLE,
                ]);
            }
        } catch (Throwable $e) {
            $this->jobHttpCountersSchemaAvailable = false;
            CltLog::warning('[FACTA] Falha ao verificar tabela de contadores HTTP por job.', [
                'table' => self::JOB_HTTP_COUNTER_TABLE,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->jobHttpCountersSchemaAvailable;
    }

    /** @param array<string,int> $counts */
    private function buildJobHttpCounterIncrementUpdate(array $counts, string $updatedAt): array
    {
        $updates = [
            'updated_at' => $updatedAt,
        ];

        foreach (self::JOB_HTTP_COUNTER_FIELDS as $field) {
            $value = (int) ($counts[$field] ?? 0);
            if ($value <= 0) {
                continue;
            }

            $updates[$field] = DB::raw("{$field} + {$value}");
        }

        return $updates;
    }

    /** @param array<string,int> $counts */
    private function buildJobHttpCounterInsertRow(int $jobId, string $endpoint, array $counts, string $now): array
    {
        $row = [
            'job_id' => $jobId,
            'endpoint' => $endpoint,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach (self::JOB_HTTP_COUNTER_FIELDS as $field) {
            $row[$field] = max(0, (int) ($counts[$field] ?? 0));
        }

        return $row;
    }

    private function flushJobHttpCounters(bool $force): void
    {
        if (!$this->isJobHttpCounterActive() || empty($this->jobHttpCounters)) {
            return;
        }

        $nowMs = (int) round(microtime(true) * 1000);
        if (!$force) {
            $elapsedMs = $nowMs - $this->jobHttpCountersLastFlushMs;
            if (
                $this->jobHttpCountersBuffered < $this->jobHttpCountersFlushEvery
                && $elapsedMs < $this->jobHttpCountersFlushIntervalMs
            ) {
                return;
            }
        }

        if (!$this->ensureJobHttpCounterTableAvailable()) {
            $this->jobHttpCounters = [];
            $this->jobHttpCountersBuffered = 0;
            $this->jobHttpCountersEnabled = false;
            return;
        }

        $jobId = (int) $this->runtimeJobId;
        if ($jobId <= 0) {
            return;
        }

        $now = now('UTC')->format('Y-m-d H:i:s');
        foreach ($this->jobHttpCounters as $endpoint => $counts) {
            $sum = 0;
            foreach (self::JOB_HTTP_COUNTER_FIELDS as $field) {
                $sum += max(0, (int) ($counts[$field] ?? 0));
            }
            if ($sum <= 0) {
                unset($this->jobHttpCounters[$endpoint]);
                continue;
            }

            try {
                $updates = $this->buildJobHttpCounterIncrementUpdate($counts, $now);
                $affected = DB::table(self::JOB_HTTP_COUNTER_TABLE)
                    ->where('job_id', $jobId)
                    ->where('endpoint', $endpoint)
                    ->update($updates);

                if ($affected === 0) {
                    $row = $this->buildJobHttpCounterInsertRow($jobId, $endpoint, $counts, $now);
                    try {
                        DB::table(self::JOB_HTTP_COUNTER_TABLE)->insert($row);
                    } catch (Throwable) {
                        DB::table(self::JOB_HTTP_COUNTER_TABLE)
                            ->where('job_id', $jobId)
                            ->where('endpoint', $endpoint)
                            ->update($updates);
                    }
                }

                unset($this->jobHttpCounters[$endpoint]);
            } catch (Throwable $e) {
                CltLog::warning('[FACTA] Falha ao persistir contador HTTP por job.', [
                    'job_id' => $jobId,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        if (empty($this->jobHttpCounters)) {
            $this->jobHttpCountersBuffered = 0;
            $this->jobHttpCountersLastFlushMs = $nowMs;
        } else {
            // Em caso de falha parcial de flush, garante próximo flush por tempo sem inflar o contador.
            $this->jobHttpCountersBuffered = max(1, count($this->jobHttpCounters));
        }
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

            // CLT ON: sem retry imediato dentro do ciclo; falhas seguem para teimosinha do job.
            $resp = null;
            try {
                $this->waitForFactaRateLimit();
                $this->trackHttpRequest('/gera-token');
                $resp = Http::withHeaders([
                    'Authorization' => 'Basic ' . $this->basicAuth,
                    'Accept' => 'application/json',
                ])
                    ->timeout(max(1, $this->httpTimeout))
                    ->connectTimeout(max(1, $this->httpConnectTimeout))
                    ->get($this->baseUrl . '/gera-token');
                $this->trackHttpResponse('/gera-token', $resp);
            } catch (Throwable $e) {
                $this->trackHttpException('/gera-token', $e);
                $this->trackNoResponse('/gera-token', 1);
                throw $e;
            }

            if (!$resp instanceof HttpResponse) {
                throw new \RuntimeException('FACTA token error: sem resposta do /gera-token');
            }

            $this->logGeraTokenResponse($resp, 'initial');

            if ($resp->status() === 403) {
                $this->logForbidden($resp, null);
            }

            if (!$resp->ok()) {
                $status = $resp->status();
                $msg = $this->responseMessage($resp); // pega message/mensagem ou resume HTML
                if ($status === 401 || $status === 402 || $this->isFatalGeraTokenAuthMessage($msg)) {
                    throw new FactaFatalAuthException(
                        "FACTA token fatal auth error: {$msg}",
                        [],
                        null,
                        self::TOKEN_ABORT_MSG_FATAL_IMMEDIATE
                    );
                }
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
                if ($this->isFatalGeraTokenAuthMessage($msg)) {
                    throw new FactaFatalAuthException(
                        "FACTA token fatal auth error: {$msg}",
                        [],
                        null,
                        self::TOKEN_ABORT_MSG_FATAL_IMMEDIATE
                    );
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
        // Sem token não há como avançar no job: tenta até o limite e aborta.
        $attempts = self::TOKEN_TRANSIENT_MAX_ATTEMPTS;
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $token = $this->getToken();
                if (!is_string($token) || $token === '') {
                    throw new \RuntimeException('Token FACTA ausente');
                }
                return $token;
            } catch (Throwable $e) {
                if ($e instanceof FactaFatalAuthException) {
                    throw $e;
                }

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
        throw new FactaFatalAuthException(
            "Falha ao obter token em {$context} após {$attempts} tentativas: {$lastMsg}",
            [],
            $lastError,
            self::TOKEN_ABORT_MSG_TRANSIENT_EXHAUSTED
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
                $this->trackHttpRequest('/consignado-trabalhador/autoriza-consulta');
                try {
                    $resp = Http::withHeaders([
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                    ])
                        ->timeout($this->httpTimeout)
                        ->connectTimeout($this->httpConnectTimeout)
                        ->get($this->baseUrl . '/consignado-trabalhador/autoriza-consulta', [
                            'cpf' => $cpf,
                        ]);
                    $this->trackHttpResponse('/consignado-trabalhador/autoriza-consulta', $resp);

                    return $resp;
                } catch (Throwable $e) {
                    $this->trackHttpException('/consignado-trabalhador/autoriza-consulta', $e);
                    $this->trackNoResponse('/consignado-trabalhador/autoriza-consulta', 1);
                    throw $e;
                }
            };

            $parsed = null;
            for ($attempt = 1; $attempt <= ($this->httpAutorizaTransientRetryAttempts + 1); $attempt++) {
                try {
                    $resp = $doRequest();
                    $stage = $attempt === 1 ? 'initial' : 'transient_retry_unit';
                    $this->logAutorizaConsultaResponse($resp, $cpf, $stage, $attempt);
                    $parsed = $this->buildAutorizaConsultaResult($cpf, $resp, $token, 'autoriza_consulta_unit');
                } catch (Throwable $e) {
                    $this->logRequestException('/consignado-trabalhador/autoriza-consulta', $e, [
                        'cpf' => $cpf,
                        'stage' => 'autoriza-consulta',
                        'attempt' => $attempt,
                    ]);
                    $parsed = [
                        'ok' => false,
                        'mensagem' => 'Exceção: ' . $e->getMessage(),
                        'vinculos' => null,
                        'retriable' => true,
                        'not_found' => false,
                        'http_status' => null,
                        'retry_after' => null,
                    ];
                }

                if ($attempt >= ($this->httpAutorizaTransientRetryAttempts + 1)) {
                    break;
                }

                if (!$this->shouldRetryAutorizaTransientImmediately($parsed)) {
                    break;
                }

                $this->sleepAutorizaTransientRetryPause($parsed);
            }

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
            } catch (FactaFatalAuthException $e) {
                throw $e;
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

            // Segunda passada imediata só para subconjunto técnico transitório,
            // preservando o ganho de pool sem serializar o chunk.
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
                foreach ($authorizedCpfs as $cpf) {
                    $out[$cpf] = $this->errorResult('Sem resposta (pool falhou)', true);
                }
                return $out;
            }

            foreach ($authorizedCpfs as $cpf) {
                $resp = $responses[$cpf] ?? null;
                $out[$cpf] = $this->buildAutorizaConsultaResult($cpf, $resp, $token, 'autoriza_consulta_lote');
            }

            $retryCpfs = $this->pickAutorizaTransientRetryCpfs($out, $authorizedCpfs);
            for ($attempt = 2; !empty($retryCpfs) && $attempt <= ($this->httpAutorizaTransientRetryAttempts + 1); $attempt++) {
                $this->sleepAutorizaTransientRetryPauseForBatch($out, $retryCpfs);

                try {
                    $retryResponses = $this->requestAutorizaPool(
                        $retryCpfs,
                        $headers,
                        $url,
                        $this->httpTimeout,
                        $this->httpConnectTimeout,
                        'transient_retry_pool',
                        $attempt
                    );
                } catch (Throwable $e) {
                    $this->logRequestException('/consignado-trabalhador/autoriza-consulta', $e, [
                        'stage' => 'transient_retry_pool',
                        'attempt' => $attempt,
                        'batch_size' => count($retryCpfs),
                    ]);
                    break;
                }

                foreach ($retryCpfs as $cpf) {
                    $retryResp = $retryResponses[$cpf] ?? null;
                    $out[$cpf] = $this->buildAutorizaConsultaResult($cpf, $retryResp, $token, 'autoriza_consulta_lote');
                }

                $retryCpfs = $this->pickAutorizaTransientRetryCpfs($out, $retryCpfs);
            }

            foreach ($authorizedCpfs as $cpf) {
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
     * Continuação do fluxo para CPF elegível (CLT online), alternável por configuração:
     * - operacoes (legado): /proposta/operacoes-disponiveis + /consignado-trabalhador/analise-politica-credito
     * - experimental: somente /consignado-trabalhador/analise-politica-credito com valor/prazos fixos
     */
    public function continuarCreditoTrabalhadorElegivel(array $ctx): array
    {
        $cpf = preg_replace('/\D+/', '', (string) ($ctx['cpf'] ?? ''));
        $matricula = trim((string) ($ctx['matricula'] ?? ''));
        $dataNascimento = $this->toFactaDate($ctx['dataNascimento'] ?? null);
        $dataAdmissao = $this->toFactaDate($ctx['dataAdmissao'] ?? null);
        $useExperimentalPolicySource = $this->shouldUseExperimentalCreditPolicySource();
        $valorParcela = $useExperimentalPolicySource ? null : $this->toMoneyString($ctx['valorParcela'] ?? null);
        $valorRenda = $useExperimentalPolicySource ? null : $this->toMoneyString($ctx['valorRenda'] ?? null);
        $operacoesReqCount = 0;
        $politicaReqCount = 0;
        $attachCreditReqMeta = function (array $result, int $opReqs, int $policyReqs, ?array $approvedTable = null, ?string $approvedTableName = null): array {
            $opSafe = max(0, $opReqs);
            $policySafe = max(0, $policyReqs);
            $result['phase2_operacoes_request_count'] = $opSafe;
            $result['phase2_politica_request_count'] = $policySafe;
            $result['phase2_request_count'] = $opSafe + $policySafe;
            $result['phase2_approved_table_name'] = $approvedTableName !== null && trim($approvedTableName) !== ''
                ? trim($approvedTableName)
                : null;

            if ($this->phase2CpfValidationAuditLogEnabled) {
                $result['phase2_approved_table'] = is_array($approvedTable) ? $approvedTable : null;
            }

            return $result;
        };

        if (strlen($cpf) !== 11) {
            return $attachCreditReqMeta([
                'attempted' => true,
                'aprovado' => false,
                'mensagem' => 'CPF inválido para continuação da análise de crédito.',
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => false,
                'http_status' => null,
                'retry_after' => null,
            ], $operacoesReqCount, $politicaReqCount);
        }

        if (
            $matricula === ''
            || $dataNascimento === null
            || $dataAdmissao === null
            || (!$useExperimentalPolicySource && ($valorParcela === null || $valorRenda === null))
        ) {
            return $attachCreditReqMeta([
                'attempted' => true,
                'aprovado' => false,
                'mensagem' => 'Dados insuficientes para continuação da análise de crédito.',
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => false,
                'http_status' => null,
                'retry_after' => null,
            ], $operacoesReqCount, $politicaReqCount);
        }

        if (!$useExperimentalPolicySource) {
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
                return $attachCreditReqMeta([
                    'attempted' => true,
                    'aprovado' => false,
                    'mensagem' => implode(' ', $validationMessages),
                    'valor_maximo_disponivel' => null,
                    'prazo_maximo_disponivel' => null,
                    'retriable' => false,
                    'http_status' => null,
                    'retry_after' => null,
                ], $operacoesReqCount, $politicaReqCount);
            }
        }

        $token = null;
        try {
            $token = $this->getTokenWithBackoff('credito-trabalhador:init');
        } catch (FactaFatalAuthException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $attachCreditReqMeta([
                'attempted' => true,
                'aprovado' => false,
                'mensagem' => 'Falha ao gerar token: ' . $e->getMessage(),
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => true,
                'http_status' => null,
                'retry_after' => null,
            ], $operacoesReqCount, $politicaReqCount);
        }

        $policyCandidates = [];
        $policyCandidatesByPrazo = [];
        if ($useExperimentalPolicySource) {
            // Modo experimental: consulta direta /consignado-trabalhador/analise-politica-credito
            // com valor fixo e prazos configuráveis, sem chamar operacoes-disponiveis.
            [$policyCandidates, $policyCandidatesByPrazo] = $this->buildFixedCreditPolicyCandidates();
            if (empty($policyCandidates)) {
                return $attachCreditReqMeta([
                    'attempted' => true,
                    'aprovado' => false,
                    'mensagem' => 'Nenhum prazo configurado para análise experimental de política de crédito.',
                    'valor_maximo_disponivel' => null,
                    'prazo_maximo_disponivel' => null,
                    'retriable' => false,
                    'http_status' => 200,
                    'retry_after' => null,
                ], $operacoesReqCount, $politicaReqCount);
            }
        } else {
            $operacoesReqCount++;
            $op = $this->consultaOperacoesDisponiveis($cpf, $matricula, $dataNascimento, (string) $valorRenda, (string) $valorParcela, $token);
            if (!($op['ok'] ?? false)) {
                return $attachCreditReqMeta([
                    'attempted' => true,
                    'aprovado' => false,
                    'mensagem' => (string) ($op['mensagem'] ?? 'Falha na consulta de operações disponíveis.'),
                    'valor_maximo_disponivel' => null,
                    'prazo_maximo_disponivel' => null,
                    'retriable' => (bool) ($op['retriable'] ?? true),
                    'http_status' => $op['http_status'] ?? null,
                    'retry_after' => $op['retry_after'] ?? null,
                ], $operacoesReqCount, $politicaReqCount);
            }

            $tabelas = is_array($op['tabelas'] ?? null) ? $op['tabelas'] : [];
            if (empty($tabelas)) {
                return $attachCreditReqMeta([
                    'attempted' => true,
                    'aprovado' => false,
                    'mensagem' => (string) ($op['mensagem'] ?? 'Nenhuma tabela disponível.'),
                    'valor_maximo_disponivel' => null,
                    'prazo_maximo_disponivel' => null,
                    'retriable' => false,
                    'http_status' => 200,
                    'retry_after' => null,
                ], $operacoesReqCount, $politicaReqCount);
            }

            $seenPolicyCandidates = [];
            foreach ($tabelas as $tb) {
                if (!is_array($tb)) {
                    continue;
                }

                $tableName = trim((string) ($tb['tabela'] ?? ''));
                if (!$this->isAllowedCreditPolicyTableName($tableName)) {
                    continue;
                }

                $prazo = isset($tb['prazo']) && is_numeric($tb['prazo']) ? (int) $tb['prazo'] : null;
                $valorEmprestimo = $this->toMoneyString($tb['valor_liquido'] ?? null);
                if ($prazo === null || $prazo <= 0 || $valorEmprestimo === null) {
                    continue;
                }

                $candidateKey = $prazo . '|' . $valorEmprestimo;
                if (isset($seenPolicyCandidates[$candidateKey])) {
                    continue;
                }
                $seenPolicyCandidates[$candidateKey] = true;

                $policyCandidates[] = [
                    'prazo' => $prazo,
                    'valorEmprestimo' => $valorEmprestimo,
                    'sourceTableName' => $tableName !== '' ? $tableName : null,
                    'sourceTable' => $this->phase2CpfValidationAuditLogEnabled ? $tb : null,
                ];
                $policyCandidatesByPrazo[$prazo][] = [
                    'prazo' => $prazo,
                    'valorEmprestimo' => $valorEmprestimo,
                    'sourceTableName' => $tableName !== '' ? $tableName : null,
                    'sourceTable' => $this->phase2CpfValidationAuditLogEnabled ? $tb : null,
                ];
            }

            if (empty($policyCandidates)) {
                return $attachCreditReqMeta([
                    'attempted' => true,
                    'aprovado' => false,
                    'mensagem' => 'Nenhuma tabela elegível para política (CLT NOVO GOLD 2PMT SB/3PMT SB).',
                    'valor_maximo_disponivel' => null,
                    'prazo_maximo_disponivel' => null,
                    'retriable' => false,
                    'http_status' => 200,
                    'retry_after' => null,
                ], $operacoesReqCount, $politicaReqCount);
            }
        }

        $policyQueue = $policyCandidates;
        $testedPolicyCandidates = [];
        $lockedPrazoFromMessage = null;
        $firstPolicyFailure = null;
        $lastMensagem = 'Operação fora da política de crédito.';
        while (!empty($policyQueue)) {
            $policyChunk = array_splice($policyQueue, 0, max(1, $this->creditPolicyBatchSize));
            $politicaReqCount += count($policyChunk);
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
                $sourceTableName = is_string($candidate['sourceTableName'] ?? null) ? $candidate['sourceTableName'] : null;
                $candidateKey = $prazo . '|' . $valorEmprestimo;
                $testedPolicyCandidates[$candidateKey] = true;

                if ($lockedPrazoFromMessage !== null && $prazo !== $lockedPrazoFromMessage) {
                    continue;
                }

                $policy = $policies[$idx] ?? $this->policyErrorResult('Sem resposta na análise de política de crédito.', true);
                if (!($policy['ok'] ?? false)) {
                    if ($useExperimentalPolicySource) {
                        if ($firstPolicyFailure === null) {
                            $firstPolicyFailure = $policy;
                        }
                        $lastMensagem = (string) ($policy['mensagem'] ?? $lastMensagem);
                        continue;
                    }

                    return $attachCreditReqMeta([
                        'attempted' => true,
                        'aprovado' => false,
                        'mensagem' => (string) ($policy['mensagem'] ?? 'Falha na análise de política de crédito.'),
                        'valor_maximo_disponivel' => null,
                        'prazo_maximo_disponivel' => null,
                        'retriable' => (bool) ($policy['retriable'] ?? true),
                        'http_status' => $policy['http_status'] ?? null,
                        'retry_after' => $policy['retry_after'] ?? null,
                    ], $operacoesReqCount, $politicaReqCount);
                }

                $policyMensagem = (string) ($policy['mensagem'] ?? '');
                if (
                    empty($policy['aprovado'])
                    && $this->isValorMaiorPermitidoPolicyApprovalMessage($policyMensagem)
                ) {
                    $mensagemAprovadaInternamente = $policyMensagem !== '' ? $policyMensagem : 'Aprovado pela política de crédito.';
                    if (!str_contains($this->normalize($mensagemAprovadaInternamente), 'aprovado internamente')) {
                        $mensagemAprovadaInternamente .= ' (aprovado internamente)';
                    }

                    return $attachCreditReqMeta([
                        'attempted' => true,
                        'aprovado' => true,
                        'mensagem' => $mensagemAprovadaInternamente,
                        'valor_maximo_disponivel' => $valorEmprestimo,
                        'prazo_maximo_disponivel' => (string) $prazo,
                        'retriable' => false,
                        'http_status' => 200,
                        'retry_after' => null,
                    ], $operacoesReqCount, $politicaReqCount, is_array($candidate['sourceTable'] ?? null) ? $candidate['sourceTable'] : null, $useExperimentalPolicySource ? null : $sourceTableName);
                }

                if (!empty($policy['aprovado'])) {
                    $approvedValorMaximo = $policy['valor_maximo_disponivel'] ?? ($useExperimentalPolicySource ? $valorEmprestimo : null);
                    $approvedPrazoMaximo = $policy['prazo_maximo_disponivel'] ?? ($useExperimentalPolicySource ? (string) $prazo : null);

                    return $attachCreditReqMeta([
                        'attempted' => true,
                        'aprovado' => true,
                        'mensagem' => (string) ($policy['mensagem'] ?? 'Aprovado pela política de crédito.'),
                        'valor_maximo_disponivel' => $approvedValorMaximo,
                        'prazo_maximo_disponivel' => $approvedPrazoMaximo,
                        'retriable' => false,
                        'http_status' => 200,
                        'retry_after' => null,
                    ], $operacoesReqCount, $politicaReqCount, is_array($candidate['sourceTable'] ?? null) ? $candidate['sourceTable'] : null, $useExperimentalPolicySource ? null : $sourceTableName);
                }

                if ($lockedPrazoFromMessage === null && empty($policy['aprovado'])) {
                    $hintPrazo = $this->extractPrazoFromPolicyMessage($policyMensagem);
                    if ($hintPrazo !== null) {
                        $lockedPrazoFromMessage = $hintPrazo;
                        $filteredQueue = [];
                        foreach (($policyCandidatesByPrazo[$hintPrazo] ?? []) as $hintCandidate) {
                            $hintKey = (int) ($hintCandidate['prazo'] ?? 0) . '|' . (string) ($hintCandidate['valorEmprestimo'] ?? '');
                            if (isset($testedPolicyCandidates[$hintKey])) {
                                continue;
                            }
                            $filteredQueue[] = $hintCandidate;
                        }
                        if ($useExperimentalPolicySource && empty($filteredQueue)) {
                            $hintKey = $hintPrazo . '|' . $this->creditPolicyFixedValorEmprestimo;
                            if (!isset($testedPolicyCandidates[$hintKey])) {
                                $filteredQueue[] = [
                                    'prazo' => $hintPrazo,
                                    'valorEmprestimo' => $this->creditPolicyFixedValorEmprestimo,
                                    'sourceTableName' => null,
                                    'sourceTable' => null,
                                ];
                            }
                        }
                        $policyQueue = $filteredQueue;
                    }
                }

                $lastMensagem = (string) ($policy['mensagem'] ?? $lastMensagem);
            }
        }

        if ($firstPolicyFailure !== null) {
            return $attachCreditReqMeta([
                'attempted' => true,
                'aprovado' => false,
                'mensagem' => (string) ($firstPolicyFailure['mensagem'] ?? 'Falha na análise de política de crédito.'),
                'valor_maximo_disponivel' => null,
                'prazo_maximo_disponivel' => null,
                'retriable' => (bool) ($firstPolicyFailure['retriable'] ?? true),
                'http_status' => $firstPolicyFailure['http_status'] ?? null,
                'retry_after' => $firstPolicyFailure['retry_after'] ?? null,
            ], $operacoesReqCount, $politicaReqCount);
        }

        return $attachCreditReqMeta([
            'attempted' => true,
            'aprovado' => false,
            'mensagem' => $lastMensagem,
            'valor_maximo_disponivel' => null,
            'prazo_maximo_disponivel' => null,
            'retriable' => false,
            'http_status' => 200,
            'retry_after' => null,
        ], $operacoesReqCount, $politicaReqCount);
    }

    private function consultaOperacoesDisponiveis(
        string $cpf,
        string $matricula,
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
            'matricula' => $matricula,
            'data_nascimento' => $dataNascimento,
            'valor_renda' => $valorRenda,
        ];

        $doRequest = function () use (&$token, $params, $cpf) {
            $this->waitForFactaRateLimit();
            $this->trackHttpRequest('/proposta/operacoes-disponiveis');
            $this->logPhase2OperacoesDisponiveisRequest($cpf, $params);
            try {
                $resp = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ])
                    ->timeout($this->httpTimeout)
                    ->connectTimeout($this->httpConnectTimeout)
                    ->get($this->baseUrl . '/proposta/operacoes-disponiveis', $params);
                $this->trackHttpResponse('/proposta/operacoes-disponiveis', $resp);

                return $resp;
            } catch (Throwable $e) {
                $this->trackHttpException('/proposta/operacoes-disponiveis', $e);
                $this->trackNoResponse('/proposta/operacoes-disponiveis', 1);
                throw $e;
            }
        };

        try {
            $resp = $doRequest();

            return $this->parseOperacoesDisponiveisResponse($resp);
        } catch (Throwable $e) {
            $this->logRequestException('/proposta/operacoes-disponiveis', $e, [
                'cpf' => $cpf,
                'stage' => 'request_exception',
                'attempt' => 1,
            ]);
            return [
                'ok' => false,
                'tabelas' => [],
                'mensagem' => 'Exceção em operações disponíveis: ' . $e->getMessage(),
                'retriable' => true,
                'http_status' => null,
                'retry_after' => null,
            ];
        } finally {
            $this->flushPreAuthGrantPersistQueue();
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
        try {
            $responses = $this->requestAnalisePoliticaCreditoPool(
                $cpf,
                $matricula,
                $dataNascimento,
                $dataAdmissao,
                $policyChunk,
                $token,
                $this->httpTimeout,
                $this->httpConnectTimeout,
                'initial_pool',
                1
            );
        } catch (Throwable $e) {
            $this->logRequestException('/consignado-trabalhador/analise-politica-credito', $e, [
                'cpf' => $cpf,
                'stage' => 'initial_pool',
                'attempt' => 1,
                'batch_size' => count($policyChunk),
            ]);
            $msg = 'Exceção na análise de política de crédito (pool): ' . $e->getMessage();
            foreach ($policyChunk as $idx => $_) {
                $results[$idx] = $this->policyErrorResult($msg, true);
            }
            ksort($results);
            return $results;
        }

        foreach ($policyChunk as $idx => $item) {
            $resp = $responses[$idx] ?? null;
            if (!$resp instanceof HttpResponse) {
                $results[$idx] = $this->policyErrorResult('Sem resposta na análise de política de crédito.', true);
                continue;
            }

            $results[$idx] = $this->parseAnalisePoliticaCreditoResponse($resp);
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
            $windowSizeCount = count($windowAliases);
            $this->waitForFactaRateLimit(count($windowAliases));
            $this->trackHttpRequest('/consignado-trabalhador/analise-politica-credito', $windowSizeCount);
            try {
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
            } catch (Throwable $e) {
                // Janela falhou: classifica por tentativa da janela para evitar agregação ambígua.
                foreach ($windowAliases as $_alias) {
                    $this->trackHttpException('/consignado-trabalhador/analise-politica-credito', $e);
                    $this->trackNoResponse('/consignado-trabalhador/analise-politica-credito', 1);
                }
                $this->logRequestException('/consignado-trabalhador/analise-politica-credito', $e, [
                    'cpf' => $cpf,
                    'stage' => $stage,
                    'attempt' => $attempt,
                    'batch_size' => $windowSizeCount,
                    'scope' => 'pool_window',
                ]);
                continue;
            }

            foreach ($windowAliases as $alias) {
                $entry = $entries[$alias] ?? null;
                if (!is_array($entry)) {
                    continue;
                }

                if (!array_key_exists($alias, $responses)) {
                    $this->trackNoResponse('/consignado-trabalhador/analise-politica-credito', 1);
                    continue;
                }

                $resp = $responses[$alias];
                if ($resp instanceof HttpResponse) {
                    $this->trackHttpResponse('/consignado-trabalhador/analise-politica-credito', $resp);

                    $out[(int) $entry['idx']] = $resp;
                    continue;
                }

                if ($resp instanceof Throwable) {
                    $this->trackHttpException('/consignado-trabalhador/analise-politica-credito', $resp);
                }

                $this->trackNoResponse('/consignado-trabalhador/analise-politica-credito', 1);
            }
        }

        return $out;
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
                    ? 'Nenhuma tabela disponível'
                    : 'Falha na consulta de operações disponíveis.';
            }
            $mensagem = $this->normalizeNenhumaTabelaMessage($mensagem);

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
            $mensagem = 'Nenhuma tabela disponível';
        }
        $mensagem = $this->normalizeNenhumaTabelaMessage($mensagem);

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
            $windowSizeCount = count($windowCpfs);
            $this->waitForFactaRateLimit(count($windowCpfs));
            $this->trackHttpRequest('/consignado-trabalhador/autoriza-consulta', $windowSizeCount);
            $poolStartedAtMs = (int) round(microtime(true) * 1000);
            try {
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
            } catch (Throwable $e) {
                // Janela falhou: classifica por tentativa da janela para evitar agregação ambígua.
                foreach ($windowCpfs as $_cpf) {
                    $this->trackHttpException('/consignado-trabalhador/autoriza-consulta', $e);
                    $this->trackNoResponse('/consignado-trabalhador/autoriza-consulta', 1);
                }
                $this->logRequestException('/consignado-trabalhador/autoriza-consulta', $e, [
                    'stage' => $stage,
                    'attempt' => $attempt,
                    'batch_size' => $windowSizeCount,
                    'scope' => 'pool_window',
                ]);
                continue;
            }

            foreach ($windowCpfs as $windowCpf) {
                $key = (string) $windowCpf;
                if (!array_key_exists($key, $windowResponses)) {
                    $this->trackNoResponse('/consignado-trabalhador/autoriza-consulta', 1);
                    continue;
                }

                $resp = $windowResponses[$key];
                if ($resp instanceof HttpResponse) {
                    $responses[$key] = $resp;
                    $this->trackHttpResponse('/consignado-trabalhador/autoriza-consulta', $resp);
                    if ($this->logFactaResponses) {
                        $this->logAutorizaConsultaResponse($resp, $key, $stage, $attempt, $poolStartedAtMs);
                    }
                    continue;
                }

                if ($resp instanceof Throwable) {
                    $this->trackHttpException('/consignado-trabalhador/autoriza-consulta', $resp);
                }

                $this->trackNoResponse('/consignado-trabalhador/autoriza-consulta', 1);
            }
        }

        return $responses;
    }

    private function isPhaseTwoEndpoint(string $endpoint): bool
    {
        $normalized = strtolower(ltrim(trim($endpoint), '/'));
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'proposta/operacoes-disponiveis')
            || str_contains($normalized, 'consignado-trabalhador/analise-politica-credito')
            || str_contains($normalized, 'analise-politica-credito');
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

    private function buildAutorizaConsultaResult(string $cpf, $resp, string &$token, string $origin): array
    {
        if (!$resp instanceof HttpResponse) {
            return $this->errorResult('Sem resposta do serviço', true);
        }

        if ($resp->status() === 403) {
            $this->logForbidden($resp, $cpf);
        }

        $parsed = $this->parseAutorizaResponse($resp);
        if (
            empty($parsed['ok'])
            && $this->isTokenExpiradoSolicitaRequiredMessage((string) ($parsed['mensagem'] ?? ''))
        ) {
            $this->refreshPreAuthorizationAfterTokenExpired($cpf, $token, $origin);
        }

        return $parsed;
    }

    /**
     * @param array<string,array<string,mixed>> $results
     * @param array<int,string> $cpfs
     * @return array<int,string>
     */
    private function pickAutorizaTransientRetryCpfs(array $results, array $cpfs): array
    {
        $retryCpfs = [];

        foreach ($cpfs as $cpf) {
            $result = $results[$cpf] ?? null;
            if (!is_array($result) || !$this->shouldRetryAutorizaTransientImmediately($result)) {
                continue;
            }

            $retryCpfs[] = $cpf;
        }

        return $retryCpfs;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function shouldRetryAutorizaTransientImmediately(array $result): bool
    {
        if (!($result['retriable'] ?? false) || !empty($result['not_found'])) {
            return false;
        }

        $mensagem = (string) ($result['mensagem'] ?? '');
        if ($this->isTokenExpiradoSolicitaRequiredMessage($mensagem)) {
            return false;
        }

        $httpStatus = $result['http_status'] ?? null;
        if ($httpStatus === null) {
            return true;
        }

        if ($httpStatus === 403 || $httpStatus === 408 || $httpStatus === 429 || $httpStatus >= 500) {
            return true;
        }

        return $this->isAutorizaTransientRetryMessage($mensagem);
    }

    /**
     * @param array<string,mixed> $result
     */
    private function sleepAutorizaTransientRetryPause(array $result): void
    {
        $retryAfter = isset($result['retry_after']) ? (int) $result['retry_after'] : null;
        $httpStatus = isset($result['http_status']) ? (int) $result['http_status'] : null;
        $sleepSeconds = $this->autorizaTransientRetryPauseSeconds($retryAfter, $httpStatus);

        if ($sleepSeconds > 0) {
            sleep($sleepSeconds);
        }
    }

    /**
     * @param array<string,array<string,mixed>> $results
     * @param array<int,string> $cpfs
     */
    private function sleepAutorizaTransientRetryPauseForBatch(array $results, array $cpfs): void
    {
        $sleepSeconds = 0;

        foreach ($cpfs as $cpf) {
            $result = $results[$cpf] ?? null;
            if (!is_array($result)) {
                continue;
            }

            $retryAfter = isset($result['retry_after']) ? (int) $result['retry_after'] : null;
            $httpStatus = isset($result['http_status']) ? (int) $result['http_status'] : null;
            $sleepSeconds = max($sleepSeconds, $this->autorizaTransientRetryPauseSeconds($retryAfter, $httpStatus));
        }

        if ($sleepSeconds > 0) {
            sleep($sleepSeconds);
        }
    }

    private function autorizaTransientRetryPauseSeconds(?int $retryAfter, ?int $httpStatus): int
    {
        if ($retryAfter !== null && $retryAfter > 0) {
            return min($retryAfter, $this->httpRateLimitPauseCapSeconds);
        }

        if ($httpStatus === 429) {
            return min($this->httpRateLimitDefaultPauseSeconds, $this->httpRateLimitPauseCapSeconds);
        }

        return max(1, $this->httpTransientPauseSeconds);
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
        $maxAttempts = $this->preAuthPhoneRetryAttempts;
        $usedCellulars = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $celular = $this->generateRandomCellular();
            $tryGuard = 0;
            while (isset($usedCellulars[$celular]) && $tryGuard < 5) {
                $celular = $this->generateRandomCellular();
                $tryGuard++;
            }
            $usedCellulars[$celular] = true;

            try {
                $resp = $this->postSolicitaAutorizacaoConsulta($cpf, $token, $celular);
                $this->logSolicitaAutorizacaoResponse($resp, $cpf, $celular, $attempt, 'initial');

                if ($resp->status() === 403) {
                    $this->logForbidden($resp, $cpf);
                }
            } catch (Throwable $e) {
                $this->logRequestException('/solicita-autorizacao-consulta', $e, [
                    'cpf' => $cpf,
                    'celular' => $celular,
                    'stage' => 'request_exception',
                    'attempt' => $attempt,
                ]);
                return [
                    'ok' => false,
                    'mensagem' => 'Pré-autorização: Exceção: ' . $e->getMessage(),
                    'retriable' => true,
                    'http_status' => null,
                    'retry_after' => null,
                ];
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
                    'mensagem' => 'Pré-autorização: Telefone já informado para outro cpf!',
                    'retriable' => false,
                    'http_status' => $status,
                    'retry_after' => $retryAfter,
                ];
            }

            if ($this->isDddInvalidoMessage($mensagem)) {
                return [
                    'ok' => false,
                    'mensagem' => 'Pré-autorização: celular sem DDD válido',
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

    /** @param array<string,mixed> $params */
    private function logPhase2OperacoesDisponiveisRequest(string $cpf, array $params): void
    {
        if (!$this->phase2OperacoesRequestLogEnabled) {
            return;
        }

        try {
            CltLog::warning('[FACTA] /proposta/operacoes-disponiveis request', [
                'job_id' => $this->runtimeJobId,
                'cpf' => $cpf,
                'params' => $params,
            ]);
        } catch (Throwable $e) {
            CltLog::warning('[FACTA] Falha ao logar request /proposta/operacoes-disponiveis: ' . $e->getMessage());
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
        $this->trackHttpRequest('/solicita-autorizacao-consulta');
        try {
            $resp = Http::withHeaders([
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
            $this->trackHttpResponse('/solicita-autorizacao-consulta', $resp);

            return $resp;
        } catch (Throwable $e) {
            $this->trackHttpException('/solicita-autorizacao-consulta', $e);
            $this->trackNoResponse('/solicita-autorizacao-consulta', 1);
            throw $e;
        }
    }

    private function generateRandomCellular(): string
    {
        $ddd = self::VALID_BR_DDDS[array_rand(self::VALID_BR_DDDS)];
        $suffix = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        return $ddd . '9' . $suffix;
    }

    private function isTokenExpiradoSolicitaRequiredMessage(string $mensagem): bool
    {
        $norm = $this->normalize($mensagem);
        if ($norm === '') {
            return false;
        }

        return str_contains($norm, 'token expirado')
            && str_contains($norm, 'solicita-autorizacao-consulta');
    }

    private function isFatalGeraTokenAuthMessage(string $mensagem): bool
    {
        $norm = $this->normalize($mensagem);
        if ($norm === '') {
            return false;
        }

        if (str_contains($norm, 'usuario ou senha invalida')) {
            return true;
        }

        if (str_contains($norm, 'authorization incorreto') || str_contains($norm, 'authorization incorreta')) {
            return true;
        }

        return str_contains($norm, 'autorizacao incorreto') || str_contains($norm, 'autorizacao incorreta');
    }

    private function refreshPreAuthorizationAfterTokenExpired(string $cpf, string &$token, string $origin): void
    {
        $cpf = preg_replace('/\D+/', '', $cpf ?? '');
        if (strlen($cpf) !== 11) {
            return;
        }

        // Força nova pré-autorização neste ciclo para evitar reutilizar grant antigo.
        unset($this->preAuthApprovedLocal[$cpf], $this->preAuthLookupCheckedLocal[$cpf]);

        $preAuth = $this->solicitaAutorizacaoConsulta($cpf, $token);

        CltLog::warning('[FACTA] token expirado em /autoriza-consulta; /solicita-autorizacao-consulta disparado para próxima rodada', [
            'job_id' => $this->runtimeJobId,
            'cpf' => $cpf,
            'origin' => $origin,
            'pre_auth_ok' => (bool) ($preAuth['ok'] ?? false),
            'pre_auth_http_status' => $preAuth['http_status'] ?? null,
            'pre_auth_retriable' => (bool) ($preAuth['retriable'] ?? false),
            'pre_auth_mensagem' => $preAuth['mensagem'] ?? null,
        ]);
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

    private function isAutorizaTransientRetryMessage(string $mensagem): bool
    {
        $norm = $this->normalize($mensagem);
        if ($norm === '') {
            return false;
        }

        return str_contains($norm, 'resposta invalida da facta')
            || str_contains($norm, 'resposta html inesperada')
            || str_contains($norm, 'html ')
            || str_contains($norm, 'temporar')
            || str_contains($norm, 'service unavailable')
            || str_contains($norm, 'gateway')
            || str_contains($norm, 'cloudflare')
            || str_contains($norm, 'waf');
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
        if ($this->isPhaseTwoEndpoint($endpoint)) {
            return;
        }

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

    private function normalizeNenhumaTabelaMessage(string $mensagem): string
    {
        return $this->isNenhumaTabelaMessage($mensagem)
            ? 'Nenhuma tabela disponível'
            : $mensagem;
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

    private function extractPrazoFromPolicyMessage(string $mensagem): ?int
    {
        $norm = $this->normalize($mensagem);
        if ($norm === '' || !str_contains($norm, 'politica de credito')) {
            return null;
        }

        if (!preg_match('/prazo\s+(?:maximo|minimo)\D*(\d+)\s*parcelas?/i', $norm, $matches)) {
            return null;
        }

        $prazo = (int) ($matches[1] ?? 0);
        return $prazo > 0 ? $prazo : null;
    }

    private function shouldUseExperimentalCreditPolicySource(): bool
    {
        return $this->creditPolicySourceMode === self::CREDIT_POLICY_SOURCE_MODE_EXPERIMENTAL;
    }

    private function normalizeCreditPolicySourceMode(string $mode): string
    {
        $norm = $this->normalize($mode);
        if (
            $norm === self::CREDIT_POLICY_SOURCE_MODE_EXPERIMENTAL
            || $norm === self::CREDIT_POLICY_SOURCE_MODE_FIXED
            || $norm === 'fixo'
            || $norm === 'teste'
            || $norm === 'test'
            || $norm === 'direto'
            || $norm === 'direct'
        ) {
            return self::CREDIT_POLICY_SOURCE_MODE_EXPERIMENTAL;
        }

        return self::CREDIT_POLICY_SOURCE_MODE_OPERACOES;
    }

    /**
     * @param mixed $raw
     * @return array<int,int>
     */
    private function normalizeCreditPolicyFixedPrazos($raw): array
    {
        $items = [];
        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw)) {
            $items = preg_split('/[,\s;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_int($raw) || is_float($raw)) {
            $items = [$raw];
        }

        $normalized = [];
        $seen = [];
        foreach ($items as $item) {
            if (!is_numeric($item)) {
                continue;
            }

            $prazo = (int) $item;
            if ($prazo <= 0 || isset($seen[$prazo])) {
                continue;
            }

            $seen[$prazo] = true;
            $normalized[] = $prazo;
        }

        return $normalized;
    }

    /**
     * @return array{0:array<int,array{prazo:int,valorEmprestimo:string,sourceTableName:null,sourceTable:null}>,1:array<int,array<int,array{prazo:int,valorEmprestimo:string,sourceTableName:null,sourceTable:null}>>}
     */
    private function buildFixedCreditPolicyCandidates(): array
    {
        $policyCandidates = [];
        $policyCandidatesByPrazo = [];
        foreach ($this->creditPolicyFixedPrazos as $prazo) {
            if ($prazo <= 0) {
                continue;
            }

            $candidate = [
                'prazo' => $prazo,
                'valorEmprestimo' => $this->creditPolicyFixedValorEmprestimo,
                'sourceTableName' => null,
                'sourceTable' => null,
            ];
            $policyCandidates[] = $candidate;
            $policyCandidatesByPrazo[$prazo][] = $candidate;
        }

        return [$policyCandidates, $policyCandidatesByPrazo];
    }

    private function isValorMaiorPermitidoPolicyApprovalMessage(string $mensagem): bool
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

        return $valor > self::CREDIT_POLICY_INTERNAL_APPROVAL_MIN_VALUE;
    }

    private function isAllowedCreditPolicyTableName(string $tableName): bool
    {
        $normalizedTableName = $this->normalize($tableName);
        if ($normalizedTableName === '') {
            return false;
        }

        return str_ends_with($normalizedTableName, 'clt novo gold 2pmt sb')
            || str_ends_with($normalizedTableName, 'clt novo gold 3pmt sb');
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
