<?php

namespace App\Modules\V8Fgts\Services;

use App\Modules\V8\Services\V8SharedAuthService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class V8FgtsApiService
{
    private string $bffBaseUrl;
    private string $provider;
    private int $httpTimeout;
    private int $httpConnectTimeout;
    private int $httpRetry;
    private int $httpRateLimitSleepSeconds;
    private ?int $jobId = null;
    private ?int $rateLimitOverrideMs = null;
    private bool $nonBlockingRateLimit = false;
    private bool $rateLimitSlotReserved = false;
    private ?int $lastSuggestedDelayMs = null;
    private V8SharedAuthService $sharedAuth;

    public function __construct()
    {
        $bff = (array) config('v8_fgts.bff', []);
        $http = (array) config('v8_fgts.http', []);

        $this->bffBaseUrl = rtrim((string) ($bff['base_url'] ?? ''), '/');
        $this->provider = strtolower(trim((string) ($bff['provider'] ?? 'bms')));
        $this->httpTimeout = (int) ($http['timeout'] ?? 15);
        $this->httpConnectTimeout = (int) ($http['connect_timeout'] ?? 10);
        $this->httpRetry = (int) ($http['retry'] ?? 1);
        $this->httpRateLimitSleepSeconds = (int) ($http['rate_limit_sleep_seconds'] ?? 15);
        $this->sharedAuth = new V8SharedAuthService((array) config('v8.oauth', []), (array) config('v8.http', []));
    }

    public function setJobId(?int $jobId): void
    {
        $this->jobId = $jobId;
    }

    public function setRateLimitMs(?int $intervalMs): void
    {
        $this->rateLimitOverrideMs = $intervalMs !== null ? max(0, (int) $intervalMs) : null;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function setNonBlockingRateLimit(bool $enabled): void
    {
        $this->nonBlockingRateLimit = $enabled;
    }

    public function lastSuggestedDelayMs(): ?int
    {
        return $this->lastSuggestedDelayMs;
    }

    public function reserveRateLimitSlotOrDelay(): int
    {
        $delayMs = $this->sharedAuth->claimThrottleSlotOrDelay($this->rateLimitOverrideMs);
        $this->lastSuggestedDelayMs = $delayMs > 0 ? $delayMs : null;
        $this->rateLimitSlotReserved = $delayMs === 0;

        return $delayMs;
    }

    public function startBalance(string $cpf): array
    {
        return $this->post('/fgts/balance', [
            'documentNumber' => $cpf,
            'provider' => $this->provider,
        ]);
    }

    public function listBalances(array $query): array
    {
        return $this->get('/fgts/balance', $query);
    }

    public function getSimulationFees(): array
    {
        return $this->get('/fgts/simulations/fees/new', []);
    }

    public function createSimulation(array $payload): array
    {
        return $this->post('/fgts/simulations', $payload);
    }

    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    private function get(string $path, array $query): array
    {
        return $this->request('GET', $path, $query);
    }

    private function request(string $method, string $path, array $data): array
    {
        $rateLimitScope = $this->rateLimitScope($method, $path);

        try {
            $token = $this->sharedAuth->getToken();
            if (!is_string($token) || $token === '') {
                return $this->errorResult('V8 OAuth: token ausente.', null, false, false, null, null, null);
            }

            $resp = $this->sendWithRetry(function () use ($method, $path, $data, $token) {
                $url = $this->bffBaseUrl . $path;
                $client = Http::withToken($token)
                    ->acceptJson()
                    ->timeout(max(1, $this->httpTimeout))
                    ->connectTimeout(max(1, $this->httpConnectTimeout));

                if ($method === 'GET') {
                    return $client->get($url, $data);
                }

                return $client->asJson()->post($url, $data);
            }, $method, $path, $rateLimitScope);

            if ($resp->status() === 401) {
                $this->sharedAuth->forgetToken();
                $token = $this->sharedAuth->getToken();
                if (!is_string($token) || $token === '') {
                    return $this->errorResult('V8 OAuth: token ausente.', null, false, false, 401, null, null);
                }

                $resp = $this->sendWithRetry(function () use ($method, $path, $data, $token) {
                    $url = $this->bffBaseUrl . $path;
                    $client = Http::withToken($token)
                        ->acceptJson()
                        ->timeout(max(1, $this->httpTimeout))
                        ->connectTimeout(max(1, $this->httpConnectTimeout));

                    if ($method === 'GET') {
                        return $client->get($url, $data);
                    }

                    return $client->asJson()->post($url, $data);
                }, $method, $path, $rateLimitScope);
            }

            if ($resp->ok()) {
                $this->sharedAuth->registerNonRateLimitedResponse($rateLimitScope);

                return [
                    'ok' => true,
                    'status' => $resp->status(),
                    'data' => $resp->json(),
                ];
            }

            [$message, $title] = $this->extractError($resp);
            $isRateLimitMessage = $this->isRateLimitMessage($message);
            $isRateLimited = ($resp->status() === 429) || $isRateLimitMessage;

            if ($isRateLimitMessage) {
                $this->logRateLimitMessage($method, $path, $message);
                $this->applyRateLimitCooldown($rateLimitScope);
            } else {
                $this->sharedAuth->registerNonRateLimitedResponse($rateLimitScope);
            }

            return $this->errorResult(
                $message,
                $title,
                $this->isRetriable($resp->status()) || $isRateLimitMessage,
                $isRateLimited,
                $resp->status(),
                $resp->json(),
                $this->extractRawBody($resp)
            );
        } catch (ConnectionException $e) {
            return $this->errorResult('V8: falha de conexão.', null, true, false, null, null, null);
        } catch (\Throwable $e) {
            Log::warning('[V8-FGTS] Erro inesperado na requisição: ' . $e->getMessage(), [
                'job_id' => $this->jobId,
                'path' => $path,
            ]);

            return $this->errorResult('V8: erro inesperado.', null, false, false, null, null, null);
        }
    }

    private function extractRawBody(HttpResponse $resp): ?string
    {
        $body = trim((string) $resp->body());

        return $body !== '' ? $body : null;
    }

    private function sendWithRetry(callable $caller, string $method, string $path, ?string $rateLimitScope = null): HttpResponse
    {
        $attempts = max(1, $this->httpRetry + 1);
        $lastResponse = null;
        $this->lastSuggestedDelayMs = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($attempt === 0 && $this->rateLimitSlotReserved) {
                $this->rateLimitSlotReserved = false;
            } else {
                $this->sharedAuth->throttleRequests($this->rateLimitOverrideMs, $rateLimitScope);
            }

            $this->logRequestSent($method, $path);

            try {
                $resp = $caller();
            } catch (ConnectionException $e) {
                if ($attempt < $attempts - 1) {
                    continue;
                }

                throw $e;
            }

            if ($resp->status() === 429) {
                Log::warning('[V8-FGTS] HTTP 429 recebido', [
                    'job_id' => $this->jobId,
                    'method' => $method,
                    'path' => $path,
                ]);

                $this->applyRateLimitCooldown($rateLimitScope);
            }

            $lastResponse = $resp;
            $status = $resp->status();
            if ($status === 429 && $this->nonBlockingRateLimit) {
                return $resp;
            }
            if (($status === 429 || $status >= 500) && $attempt < $attempts - 1) {
                continue;
            }

            return $resp;
        }

        if ($lastResponse instanceof HttpResponse) {
            return $lastResponse;
        }

        throw new \RuntimeException('V8-FGTS: requisição falhou.');
    }

    private function extractError(HttpResponse $resp): array
    {
        $json = $resp->json();
        if (is_array($json)) {
            $message = $json['detail'] ?? $json['message'] ?? $json['mensagem'] ?? $json['title'] ?? null;
            $title = $json['title'] ?? $json['type'] ?? null;

            return [
                is_string($message) && trim($message) !== '' ? trim($message) : 'Erro na API V8 FGTS',
                is_string($title) && trim($title) !== '' ? trim($title) : null,
            ];
        }

        $body = trim(strip_tags((string) $resp->body()));

        return [$body !== '' ? Str::limit($body, 200) : 'Erro na API V8 FGTS', null];
    }

    private function isRetriable(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function isRateLimitMessage(?string $message): bool
    {
        return is_string($message) && str_contains(mb_strtolower($message), 'limite de requisições excedido');
    }

    private function applyRateLimitCooldown(?string $rateLimitScope = null): void
    {
        $delayMs = $this->sharedAuth->scheduleProgressiveRateLimitCooldown($this->httpRateLimitSleepSeconds, $this->rateLimitOverrideMs, $rateLimitScope);
        $this->lastSuggestedDelayMs = $delayMs > 0 ? $delayMs : null;

        if (!$this->nonBlockingRateLimit && $delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function rateLimitScope(string $method, string $path): ?string
    {
        if ($method === 'POST' && $path === '/fgts/balance') {
            return 'v8_fgts_post_balance';
        }

        return null;
    }

    private function logRequestSent(string $method, string $path): void
    {
        Log::warning('[V8-FGTS] Requisicao enviada', [
            'job_id' => $this->jobId,
            'method' => $method,
            'path' => $path,
        ]);
    }

    private function logRateLimitMessage(string $method, string $path, string $message): void
    {
        Log::warning('[V8-FGTS] Rate limit por mensagem recebido', [
            'job_id' => $this->jobId,
            'method' => $method,
            'path' => $path,
            'message' => $message,
        ]);
    }

    private function errorResult(string $message, ?string $title, bool $retriable, bool $rateLimited, ?int $status, mixed $data, ?string $rawBody): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'data' => $data,
            'raw_body' => $rawBody,
            'error' => $message,
            'title' => $title,
            'retriable' => $retriable,
            'rate_limited' => $rateLimited,
        ];
    }
}
