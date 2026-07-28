<?php

namespace App\Modules\V8\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class V8ApiService
{
    private string $bffBaseUrl;

    private int $httpTimeout;
    private int $httpConnectTimeout;
    private int $httpRetry;
    private int $httpRetryDelayMs;
    private int $httpMinIntervalMs;
    private int $httpRateLimitSleepSeconds;
    private ?int $jobId = null;
    private ?int $rateLimitOverrideMs = null;
    private bool $logEnabled;
    private bool $logApiResponses;
    private bool $logApiSuccessResponses;
    private bool $logApi429;
    private V8SharedAuthService $sharedAuth;

    public function __construct()
    {
        $oauth = (array) config('v8.oauth', []);
        $bff = (array) config('v8.bff', []);
        $http = (array) config('v8.http', []);
        $logging = (array) config('v8.logging', []);

        $this->bffBaseUrl = rtrim((string) ($bff['base_url'] ?? ''), '/');
        $this->httpTimeout = (int) ($http['timeout'] ?? 15);
        $this->httpConnectTimeout = (int) ($http['connect_timeout'] ?? 10);
        $this->httpRetry = (int) ($http['retry'] ?? 1);
        $this->httpRetryDelayMs = (int) ($http['retry_delay_ms'] ?? 200);
        $this->httpMinIntervalMs = (int) ($http['min_interval_ms'] ?? 2000);
        $this->httpRateLimitSleepSeconds = (int) ($http['rate_limit_sleep_seconds'] ?? 15);
        $this->logEnabled = (bool) ($logging['enabled'] ?? true);
        $this->logApiResponses = (bool) ($logging['api_log_responses'] ?? true);
        $this->logApiSuccessResponses = (bool) ($logging['api_log_success_responses'] ?? false);
        $this->logApi429 = (bool) ($logging['api_log_429'] ?? true);
        $this->sharedAuth = new V8SharedAuthService($oauth, $http);
    }

    public function setJobId(?int $jobId): void
    {
        $this->jobId = $jobId;
    }

    public function setRateLimitMs(?int $intervalMs): void
    {
        $this->rateLimitOverrideMs = $intervalMs !== null ? max(0, (int) $intervalMs) : null;
    }

    public function getToken(): ?string
    {
        return $this->sharedAuth->getToken(function (HttpResponse $resp) {
            $this->logHttpResponse('oauth', 'POST', '/oauth/token', $resp);
        });
    }

    public function createConsult(array $payload): array
    {
        return $this->postBff('/private-consignment/consult', $payload);
    }

    public function authorizeConsult(string $consultId): array
    {
        $path = '/private-consignment/consult/' . urlencode($consultId) . '/authorize';
        return $this->postBff($path, self::authorizePayload());
    }

    public function listConsults(array $query): array
    {
        return $this->getBff('/private-consignment/consult', $query);
    }

    public function simulate(array $payload): array
    {
        return $this->postBff('/private-consignment/simulation', $payload);
    }

    public function listSimulationConfigs(): array
    {
        return $this->getBff('/private-consignment/simulation/configs', []);
    }

    private function postBff(string $path, array $payload): array
    {
        return $this->request('post', $path, $payload);
    }

    private function getBff(string $path, array $query): array
    {
        return $this->request('get', $path, $query);
    }

    public static function authorizePayload(): array
    {
        return (array) config('v8.authorize_device', [
            'operationalSystem' => 'Linux',
            'deviceModel' => 'Servidor API',
            'deviceName' => 'integracao-backend',
            'deviceType' => 'desktop',
        ]);
    }

    private function request(string $method, string $path, array $data): array
    {
        try {
            $token = $this->getToken();
            if (!$token) {
                return $this->errorResult('V8 OAuth: token ausente.', null, false, null);
            }

            $url = $this->bffBaseUrl . $path;
            $resp = $this->sendWithRetry(function () use ($method, $url, $data, $token) {
                $client = Http::withToken($token)
                    ->acceptJson()
                    ->timeout(max(1, $this->httpTimeout))
                    ->connectTimeout(max(1, $this->httpConnectTimeout));

                if ($method === 'get') {
                    return $client->get($url, $data);
                }

                return $client->asJson()->post($url, $data);
            }, [
                'method' => strtoupper($method),
                'path' => $path,
            ]);
            $this->logHttpResponse('bff', strtoupper($method), $path, $resp);

            if ($resp->ok()) {
                $json = $resp->json();
                return [
                    'ok' => true,
                    'status' => $resp->status(),
                    'data' => is_array($json) ? $json : [],
                ];
            }

            [$message, $type] = $this->extractError($resp);
            return $this->errorResult($message, $type, $this->isRetriable($resp->status()), $resp->status());
        } catch (LockTimeoutException $e) {
            Log::warning('[V8] Timeout ao aguardar lock compartilhado.', [
                'job_id' => $this->jobId,
                'method' => strtoupper($method),
                'path' => $path,
            ]);
            return $this->errorResult('V8: aguardando controle de requisições.', null, true, null);
        } catch (ConnectionException $e) {
            return $this->errorResult('V8: falha de conexão.', null, true, null);
        } catch (\Throwable $e) {
            Log::warning('[V8] Erro inesperado na requisição.', [
                'job_id' => $this->jobId,
                'method' => strtoupper($method),
                'path' => $path,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception' => $e,
            ]);
            return $this->errorResult('V8: erro inesperado.', null, false, null);
        }
    }

    private function isRetriable(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function sendWithRetry(callable $caller, array $context = []): HttpResponse
    {
        $attempts = max(1, $this->httpRetry + 1);
        $lastResponse = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $this->throttleRequests();

            try {
                $resp = $caller();
            } catch (ConnectionException $e) {
                if ($attempt < $attempts - 1) {
                    continue;
                }
                throw $e;
            }

            if ($resp->status() === 429) {
                $this->logRateLimit($context, $attempt + 1);
                try {
                    $this->pauseOnRateLimit($context);
                    $resp = $caller();
                } catch (ConnectionException $e) {
                    if ($attempt < $attempts - 1) {
                        continue;
                    }
                    throw $e;
                }
            }

            $lastResponse = $resp;
            $status = $resp->status();
            if ($status === 429) {
                $this->logRateLimit($context, $attempt + 1);
            }
            if (($status === 429 || $status >= 500) && $attempt < $attempts - 1) {
                continue;
            }

            return $resp;
        }

        if ($lastResponse) {
            return $lastResponse;
        }

        throw new \RuntimeException('V8: requisição falhou.');
    }

    private function throttleRequests(): void
    {
        $this->sharedAuth->throttleRequests($this->rateLimitOverrideMs ?? $this->httpMinIntervalMs);
    }

    private function logRateLimit(array $context, int $attempt): void
    {
        if (!$this->shouldLogApi429Event()) {
            return;
        }

        try {
            Log::warning('[V8] HTTP 429 recebido', array_merge([
                'attempt' => $attempt,
                'job_id' => $this->jobId,
            ], $context));
        } catch (\Throwable) {
        }
    }

    private function pauseOnRateLimit(array $context): void
    {
        $sleepSeconds = max(0, $this->httpRateLimitSleepSeconds);

        if ($this->shouldLogApi429Event()) {
            try {
                Log::warning('[V8] Pausa após 429 iniciada', array_merge([
                    'seconds' => $sleepSeconds,
                    'job_id' => $this->jobId,
                ], $context));
            } catch (\Throwable) {
            }
        }

        $this->sharedAuth->pauseOnRateLimit($sleepSeconds, $this->rateLimitOverrideMs ?? $this->httpMinIntervalMs);

        if ($this->shouldLogApi429Event()) {
            try {
                Log::warning('[V8] Pausa após 429 finalizada', array_merge([
                    'seconds' => $sleepSeconds,
                    'job_id' => $this->jobId,
                ], $context));
            } catch (\Throwable) {
            }
        }
    }

    private function shouldLogApi429Event(): bool
    {
        return $this->logEnabled && $this->logApi429;
    }

    private function shouldLogApiResponse(HttpResponse $resp): bool
    {
        if (!$this->logEnabled || !$this->logApiResponses) {
            return false;
        }

        if ($this->logApiSuccessResponses) {
            return true;
        }

        $status = $resp->status();
        if ($status === 429 && !$this->logApi429) {
            return false;
        }

        return $status < 200 || $status >= 300;
    }

    private function logHttpResponse(string $source, string $method, string $path, HttpResponse $resp): void
    {
        if (!$this->shouldLogApiResponse($resp)) {
            return;
        }

        try {
            $status = $resp->status();
            $context = [
                'job_id' => $this->jobId,
                'source' => $source,
                'method' => strtoupper($method),
                'path' => $path,
                'http_status' => $status,
                'elapsed_ms' => $this->extractElapsedMs($resp),
                'outcome' => ($status < 200 || $status >= 300) ? 'error' : 'success',
            ];

            if ($status < 200 || $status >= 300) {
                $context = array_merge($context, $this->compactResponseLogContext($resp, 320));
            }

            Log::warning('[V8] HTTP response', $context);
        } catch (\Throwable) {
        }
    }

    private function compactResponseLogContext(HttpResponse $resp, int $bodyLimit = 320): array
    {
        $json = null;
        $type = null;
        $message = null;

        try {
            $decoded = $resp->json();
            if (is_array($decoded)) {
                $json = $decoded;
                $type = $decoded['type'] ?? null;
                $message = $decoded['detail'] ?? $decoded['message'] ?? $decoded['mensagem'] ?? $decoded['title'] ?? null;
            }
        } catch (\Throwable) {
        }

        $body = trim((string) $resp->body());
        $bodyText = trim(strip_tags($body));
        if ($bodyText === '') {
            $bodyText = Str::limit($body, $bodyLimit);
        }

        $context = [
            'type' => is_string($type) && $type !== '' ? $type : null,
            'message' => is_string($message) && $message !== '' ? Str::limit($message, 200) : null,
            'body_excerpt' => $bodyText !== '' ? Str::limit($bodyText, $bodyLimit) : null,
        ];

        if (is_array($json)) {
            $context['json_keys'] = array_slice(array_keys($json), 0, 20);
        }

        return $context;
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
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractError(HttpResponse $resp): array
    {
        $json = $resp->json();
        if (is_array($json)) {
            $detail = (string) ($json['detail'] ?? $json['message'] ?? $json['mensagem'] ?? $json['title'] ?? 'Erro na API V8');
            $type = $json['type'] ?? null;
            return [$detail, $type];
        }

        $body = (string) $resp->body();
        $detail = trim(strip_tags($body));
        if ($detail === '') {
            $detail = 'Erro na API V8';
        }
        return [Str::limit($detail, 200), null];
    }

    private function responseMessage(HttpResponse $resp): string
    {
        $json = $resp->json();
        if (is_array($json)) {
            $msg = $json['detail'] ?? $json['message'] ?? $json['mensagem'] ?? $json['title'] ?? null;
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
        }

        $body = trim(strip_tags((string) $resp->body()));
        return $body !== '' ? Str::limit($body, 200) : 'Erro HTTP ' . $resp->status();
    }

    private function errorResult(string $message, ?string $type, bool $retriable, ?int $status): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'data' => null,
            'error' => $message,
            'type' => $type,
            'retriable' => $retriable,
        ];
    }
}
