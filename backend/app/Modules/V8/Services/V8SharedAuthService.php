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
    private int $httpRateLimitRecoverySuccesses;

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
        $this->httpRateLimitRecoverySuccesses = max(1, (int) ($http['rate_limit_recovery_successes'] ?? 5));
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

    public function throttleRequests(?int $overrideMs = null, ?string $scope = null): void
    {
        $minInterval = $this->effectiveMinInterval(
            $overrideMs !== null ? max(0, $overrideMs) : $this->httpMinIntervalMs,
            $scope
        );
        if ($minInterval <= 0) {
            return;
        }

        $lock = Cache::lock($this->scopedKey('v8_http_rate_lock', $scope), 10);
        $lock->block(5);

        try {
            $now = (int) floor(microtime(true) * 1000);
            $readyAt = (int) Cache::get($this->scopedKey('v8_http_last_at_ms', $scope), 0);
            $delayMs = max(0, $readyAt - $now);

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
                $now = (int) floor(microtime(true) * 1000);
            }

            Cache::put($this->scopedKey('v8_http_last_at_ms', $scope), $now + $minInterval, 3600);
        } finally {
            optional($lock)->release();
        }
    }

    public function claimThrottleSlotOrDelay(?int $overrideMs = null, ?string $scope = null): int
    {
        $minInterval = $this->effectiveMinInterval(
            $overrideMs !== null ? max(0, $overrideMs) : $this->httpMinIntervalMs,
            $scope
        );
        if ($minInterval <= 0) {
            return 0;
        }

        $lock = Cache::lock($this->scopedKey('v8_http_rate_lock', $scope), 10);
        $lock->block(5);

        try {
            $now = (int) floor(microtime(true) * 1000);
            $readyAt = (int) Cache::get($this->scopedKey('v8_http_last_at_ms', $scope), 0);
            if ($readyAt > $now) {
                return $readyAt - $now;
            }

            Cache::put($this->scopedKey('v8_http_last_at_ms', $scope), $now + $minInterval, 3600);

            return 0;
        } finally {
            optional($lock)->release();
        }
    }

    public function pauseOnRateLimit(?int $sleepSeconds = null, ?int $fallbackIntervalMs = null, ?string $scope = null): void
    {
        $delayMs = $this->scheduleRateLimitCooldown($sleepSeconds, $fallbackIntervalMs, $scope);
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    public function scheduleRateLimitCooldown(?int $sleepSeconds = null, ?int $fallbackIntervalMs = null, ?string $scope = null): int
    {
        $sleepSeconds = $sleepSeconds !== null ? max(0, $sleepSeconds) : $this->httpRateLimitSleepSeconds;
        if ($sleepSeconds <= 0) {
            return $this->claimThrottleSlotOrDelay($fallbackIntervalMs, $scope);
        }

        $cooldownUntilMs = (int) floor(microtime(true) * 1000) + ($sleepSeconds * 1000);
        $lock = Cache::lock($this->scopedKey('v8_http_rate_lock', $scope), 10);
        $lock->block(5);

        try {
            $readyAt = (int) Cache::get($this->scopedKey('v8_http_last_at_ms', $scope), 0);
            $scheduledAt = max($readyAt, $cooldownUntilMs);
            Cache::put($this->scopedKey('v8_http_last_at_ms', $scope), $scheduledAt, 3600);

            return max(0, $scheduledAt - (int) floor(microtime(true) * 1000));
        } finally {
            optional($lock)->release();
        }
    }

    public function scheduleProgressiveRateLimitCooldown(?int $sleepSeconds = null, ?int $fallbackIntervalMs = null, ?string $scope = null): int
    {
        $sleepSeconds = $sleepSeconds !== null ? max(0, $sleepSeconds) : $this->httpRateLimitSleepSeconds;
        if ($sleepSeconds <= 0) {
            return $this->claimThrottleSlotOrDelay($fallbackIntervalMs, $scope);
        }

        $lock = Cache::lock($this->scopedKey('v8_http_rate_lock', $scope), 10);
        $lock->block(5);

        try {
            $streakKey = $this->scopedKey('v8_http_rate_limit_streak', $scope);
            $levelKey = $this->scopedKey('v8_http_rate_limit_level', $scope);
            $successKey = $this->scopedKey('v8_http_rate_limit_success_streak', $scope);
            $readyAtKey = $this->scopedKey('v8_http_last_at_ms', $scope);

            $streak = max(0, (int) Cache::get($streakKey, 0)) + 1;
            Cache::put($streakKey, $streak, 3600);
            Cache::put($levelKey, min(2, max(0, (int) Cache::get($levelKey, 0)) + 1), 3600);
            Cache::forget($successKey);

            $multiplier = $streak <= 1 ? 1 : ($streak === 2 ? 2 : 4);
            $cooldownMs = $sleepSeconds * $multiplier * 1000;
            $readyAt = (int) Cache::get($readyAtKey, 0);
            $scheduledAt = max($readyAt, (int) floor(microtime(true) * 1000) + $cooldownMs);
            Cache::put($readyAtKey, $scheduledAt, 3600);

            return max(0, $scheduledAt - (int) floor(microtime(true) * 1000));
        } finally {
            optional($lock)->release();
        }
    }

    public function resetRateLimitBackoff(?string $scope = null): void
    {
        Cache::forget($this->scopedKey('v8_http_rate_limit_streak', $scope));
        Cache::forget($this->scopedKey('v8_http_rate_limit_level', $scope));
        Cache::forget($this->scopedKey('v8_http_rate_limit_success_streak', $scope));
    }

    public function registerNonRateLimitedResponse(?string $scope = null): void
    {
        if ($scope === null || $scope === '') {
            $this->resetRateLimitBackoff();
            return;
        }

        $lock = Cache::lock($this->scopedKey('v8_http_rate_lock', $scope), 10);
        $lock->block(5);

        try {
            $levelKey = $this->scopedKey('v8_http_rate_limit_level', $scope);
            $streakKey = $this->scopedKey('v8_http_rate_limit_streak', $scope);
            $successKey = $this->scopedKey('v8_http_rate_limit_success_streak', $scope);
            $level = max(0, (int) Cache::get($levelKey, 0));
            if ($level <= 0) {
                Cache::forget($streakKey);
                Cache::forget($successKey);
                return;
            }

            $successes = max(0, (int) Cache::get($successKey, 0)) + 1;
            if ($successes >= $this->httpRateLimitRecoverySuccesses) {
                $nextLevel = max(0, $level - 1);
                Cache::forget($streakKey);

                if ($nextLevel <= 0) {
                    $this->resetRateLimitBackoff($scope);
                    return;
                }

                Cache::put($levelKey, $nextLevel, 3600);
                Cache::forget($successKey);
                return;
            }

            Cache::put($successKey, $successes, 3600);
        } finally {
            optional($lock)->release();
        }
    }

    private function effectiveMinInterval(int $baseMs, ?string $scope): int
    {
        $baseMs = max(0, $baseMs);
        $level = max(0, (int) Cache::get($this->scopedKey('v8_http_rate_limit_level', $scope), 0));

        if ($level <= 0) {
            return $baseMs;
        }

        if ($level === 1) {
            return max($baseMs, 15000);
        }

        return max($baseMs, 30000);
    }

    private function scopedKey(string $base, ?string $scope): string
    {
        $scope = trim((string) $scope);

        return $scope === '' ? $base : "{$base}:{$scope}";
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
