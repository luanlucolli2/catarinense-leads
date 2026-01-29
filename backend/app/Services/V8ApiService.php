<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class V8ApiService
{
    private string $oauthBaseUrl;
    private ?string $username;
    private ?string $password;
    private ?string $audience;
    private ?string $clientId;
    private string $scope;
    private int $tokenTtlSkew;
    private int $tokenLockTtl = 10;
    private int $tokenLockWait = 5;

    private string $bffBaseUrl;

    private int $httpTimeout;
    private int $httpConnectTimeout;
    private int $httpRetry;
    private int $httpRetryDelayMs;
    private int $httpMinIntervalMs;
    private int $httpRateLimitSleepSeconds;
    private ?int $jobId = null;
    private ?int $rateLimitOverrideMs = null;

    public function __construct()
    {
        $oauth = (array) config('v8.oauth', []);
        $bff = (array) config('v8.bff', []);
        $http = (array) config('v8.http', []);

        $this->oauthBaseUrl = rtrim((string) ($oauth['base_url'] ?? ''), '/');
        $this->username = $oauth['username'] ?? null;
        $this->password = $oauth['password'] ?? null;
        $this->audience = $oauth['audience'] ?? null;
        $this->clientId = $oauth['client_id'] ?? null;
        $this->scope = (string) ($oauth['scope'] ?? 'offline_access');
        $this->tokenTtlSkew = (int) ($oauth['token_ttl_skew'] ?? 60);

        $this->bffBaseUrl = rtrim((string) ($bff['base_url'] ?? ''), '/');

        $this->httpTimeout = (int) ($http['timeout'] ?? 15);
        $this->httpConnectTimeout = (int) ($http['connect_timeout'] ?? 10);
        $this->httpRetry = (int) ($http['retry'] ?? 1);
        $this->httpRetryDelayMs = (int) ($http['retry_delay_ms'] ?? 200);
        $this->httpMinIntervalMs = (int) ($http['min_interval_ms'] ?? 2000);
        $this->httpRateLimitSleepSeconds = (int) ($http['rate_limit_sleep_seconds'] ?? 15);
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
        $cached = Cache::get('v8_oauth_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $lock = Cache::lock('v8_oauth_token_lock', $this->tokenLockTtl);
        $lock->block($this->tokenLockWait);

        try {
            $cached = Cache::get('v8_oauth_token');
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            if (!$this->username || !$this->password || !$this->audience || !$this->clientId) {
                throw new \RuntimeException('V8 OAuth: credenciais ausentes (username/password/audience/client_id).');
            }

            $resp = $this->sendWithRetry(function () {
                return Http::asForm()
                    ->acceptJson()
                    ->timeout(max(1, $this->httpTimeout))
                    ->connectTimeout(max(1, $this->httpConnectTimeout))
                    ->post($this->oauthBaseUrl . '/oauth/token', [
                        'grant_type' => 'password',
                        'username' => $this->username,
                        'password' => $this->password,
                        'audience' => $this->audience,
                        'scope' => $this->scope,
                        'client_id' => $this->clientId,
                    ]);
            }, [
                'method' => 'POST',
                'path' => '/oauth/token',
            ]);

            if (!$resp->ok()) {
                $msg = $this->responseMessage($resp);
                throw new \RuntimeException("V8 OAuth: {$msg}");
            }

            $json = $resp->json();
            if (!is_array($json)) {
                $msg = $this->responseMessage($resp);
                throw new \RuntimeException("V8 OAuth: resposta inválida ({$msg})");
            }

            $token = $json['access_token'] ?? null;
            $expires = (int) ($json['expires_in'] ?? 0);
            if (!is_string($token) || $token === '') {
                throw new \RuntimeException('V8 OAuth: access_token ausente.');
            }

            $ttl = max(30, $expires - $this->tokenTtlSkew);
            Cache::put('v8_oauth_token', $token, $ttl);

            return $token;
        } finally {
            optional($lock)->release();
        }
    }

    public function createConsult(array $payload): array
    {
        return $this->postBff('/private-consignment/consult', $payload);
    }

    public function authorizeConsult(string $consultId): array
    {
        $path = '/private-consignment/consult/' . urlencode($consultId) . '/authorize';
        return $this->postBff($path, []);
    }

    public function listConsults(array $query): array
    {
        return $this->getBff('/private-consignment/consult', $query);
    }

    public function simulate(array $payload): array
    {
        return $this->postBff('/private-consignment/simulation', $payload);
    }

    private function postBff(string $path, array $payload): array
    {
        return $this->request('post', $path, $payload);
    }

    private function getBff(string $path, array $query): array
    {
        return $this->request('get', $path, $query);
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
        } catch (ConnectionException $e) {
            return $this->errorResult('V8: falha de conexão.', null, true, null);
        } catch (\Throwable $e) {
            Log::warning('[V8] Erro inesperado na requisição: ' . $e->getMessage(), [
                'job_id' => $this->jobId,
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
        $minInterval = $this->rateLimitOverrideMs ?? $this->httpMinIntervalMs;
        if ($minInterval <= 0) {
            return;
        }

        $lock = Cache::lock('v8_http_rate_lock', 10);
        $lock->block(5);

        try {
            $now = (int) floor(microtime(true) * 1000);
            $last = (int) Cache::get('v8_http_last_at_ms', 0);
            $elapsed = $now - $last;
            if ($elapsed < $minInterval) {
                usleep((int) max(0, $minInterval - $elapsed) * 1000);
                $now = (int) floor(microtime(true) * 1000);
            }

            Cache::put('v8_http_last_at_ms', $now, 3600);
        } finally {
            optional($lock)->release();
        }
    }

    private function logRateLimit(array $context, int $attempt): void
    {
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
        if ($sleepSeconds <= 0) {
            $this->throttleRequests();
            return;
        }

        try {
            Log::warning('[V8] Pausa após 429 iniciada', array_merge([
                'seconds' => $sleepSeconds,
                'job_id' => $this->jobId,
            ], $context));
        } catch (\Throwable) {
        }

        sleep($sleepSeconds);

        try {
            Log::warning('[V8] Pausa após 429 finalizada', array_merge([
                'seconds' => $sleepSeconds,
                'job_id' => $this->jobId,
            ], $context));
        } catch (\Throwable) {
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
