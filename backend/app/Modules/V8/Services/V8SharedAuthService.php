<?php

namespace App\Modules\V8\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class V8SharedAuthService
{
    private string $oauthBaseUrl;
    private ?string $username;
    private ?string $password;
    private ?string $audience;
    private ?string $clientId;
    private string $scope;
    private int $tokenTtlSkew;
    private int $tokenLockTtl;
    private int $tokenLockWait;
    private int $httpTimeout;
    private int $httpConnectTimeout;
    private int $httpRetry;
    private int $httpMinIntervalMs;
    private int $httpRateLimitSleepSeconds;

    public function __construct(?array $oauth = null, ?array $http = null)
    {
        $oauth ??= (array) config('v8.oauth', []);
        $http ??= (array) config('v8.http', []);

        $this->oauthBaseUrl = rtrim((string) ($oauth['base_url'] ?? ''), '/');
        $this->username = $oauth['username'] ?? null;
        $this->password = $oauth['password'] ?? null;
        $this->audience = $oauth['audience'] ?? null;
        $this->clientId = $oauth['client_id'] ?? null;
        $this->scope = (string) ($oauth['scope'] ?? 'offline_access');
        $this->tokenTtlSkew = (int) ($oauth['token_ttl_skew'] ?? 60);
        $this->tokenLockTtl = (int) ($oauth['token_lock_ttl'] ?? 10);
        $this->tokenLockWait = (int) ($oauth['token_lock_wait'] ?? 5);

        $this->httpTimeout = (int) ($http['timeout'] ?? 15);
        $this->httpConnectTimeout = (int) ($http['connect_timeout'] ?? 10);
        $this->httpRetry = (int) ($http['retry'] ?? 1);
        $this->httpMinIntervalMs = (int) ($http['min_interval_ms'] ?? 2000);
        $this->httpRateLimitSleepSeconds = (int) ($http['rate_limit_sleep_seconds'] ?? 15);
    }

    public function getToken(?callable $responseLogger = null): ?string
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
            });

            if ($responseLogger !== null) {
                $responseLogger($resp);
            }

            if (!$resp->ok()) {
                throw new \RuntimeException('V8 OAuth: erro HTTP ' . $resp->status());
            }

            $json = $resp->json();
            if (!is_array($json)) {
                throw new \RuntimeException('V8 OAuth: resposta inválida.');
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

    public function forgetToken(): void
    {
        Cache::forget('v8_oauth_token');
    }

    public function throttleRequests(?int $overrideMs = null): void
    {
        $minInterval = $overrideMs !== null ? max(0, $overrideMs) : $this->httpMinIntervalMs;
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

    public function pauseOnRateLimit(?int $sleepSeconds = null, ?int $fallbackIntervalMs = null): void
    {
        $sleepSeconds = $sleepSeconds !== null ? max(0, $sleepSeconds) : $this->httpRateLimitSleepSeconds;
        if ($sleepSeconds <= 0) {
            $this->throttleRequests($fallbackIntervalMs);
            return;
        }

        $cooldownUntilMs = (int) floor(microtime(true) * 1000) + ($sleepSeconds * 1000);
        $lock = Cache::lock('v8_http_rate_lock', 10);
        $lock->block(5);

        try {
            $last = (int) Cache::get('v8_http_last_at_ms', 0);
            Cache::put('v8_http_last_at_ms', max($last, $cooldownUntilMs), 3600);
        } finally {
            optional($lock)->release();
        }

        sleep($sleepSeconds);
    }

    private function sendWithRetry(callable $caller): HttpResponse
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
                $this->pauseOnRateLimit();

                try {
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
            if (($status === 429 || $status >= 500) && $attempt < $attempts - 1) {
                continue;
            }

            return $resp;
        }

        if ($lastResponse instanceof HttpResponse) {
            return $lastResponse;
        }

        throw new \RuntimeException('V8 OAuth: requisição falhou.');
    }
}
