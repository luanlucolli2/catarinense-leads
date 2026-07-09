<?php

namespace App\Modules\HubCredito\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class HubCreditoSharedAuthService
{
    private const CACHE_KEY = 'hubcredito_auth_payload';
    private const LOCK_KEY = 'hubcredito_auth_payload_lock';
    private const RATE_LOCK_KEY = 'hubcredito_http_rate_lock';
    private const RATE_LAST_AT_KEY = 'hubcredito_http_last_at_ms';

    private string $baseUrl;
    private ?string $username;
    private ?string $password;
    private int $tokenTtlSkew;
    private int $tokenLockTtl;
    private int $tokenLockWait;
    private string $refreshGrantType;
    private int $httpTimeout;
    private int $httpConnectTimeout;
    private int $httpRetry;
    private int $httpRetryDelayMs;
    private int $httpMinIntervalMs;
    private int $httpRateLimitSleepSeconds;

    public function __construct(?array $auth = null, ?array $http = null)
    {
        $auth ??= (array) config('hubcredito.auth', []);
        $http ??= (array) config('hubcredito.http', []);

        $this->baseUrl = rtrim((string) ($auth['base_url'] ?? ''), '/');
        $this->username = $auth['username'] ?? null;
        $this->password = $auth['password'] ?? null;
        $this->tokenTtlSkew = (int) ($auth['token_ttl_skew'] ?? 60);
        $this->tokenLockTtl = (int) ($auth['token_lock_ttl'] ?? 10);
        $this->tokenLockWait = (int) ($auth['token_lock_wait'] ?? 5);
        $this->refreshGrantType = (string) ($auth['refresh_grant_type'] ?? 'refresh_token');

        $this->httpTimeout = (int) ($http['timeout'] ?? 30);
        $this->httpConnectTimeout = (int) ($http['connect_timeout'] ?? 10);
        $this->httpRetry = (int) ($http['retry'] ?? 1);
        $this->httpRetryDelayMs = (int) ($http['retry_delay_ms'] ?? 300);
        $this->httpMinIntervalMs = (int) ($http['min_interval_ms'] ?? 1000);
        $this->httpRateLimitSleepSeconds = (int) ($http['rate_limit_sleep_seconds'] ?? 15);
    }

    public function getAccessToken(?callable $responseLogger = null): ?string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if ($this->isPayloadUsable($cached)) {
            return (string) $cached['access_token'];
        }

        $lock = Cache::lock(self::LOCK_KEY, $this->tokenLockTtl);
        $lock->block($this->tokenLockWait);

        try {
            $cached = Cache::get(self::CACHE_KEY);
            if ($this->isPayloadUsable($cached)) {
                return (string) $cached['access_token'];
            }

            if (is_array($cached) && !empty($cached['user_id']) && !empty($cached['refresh_token'])) {
                try {
                    $payload = $this->refreshToken((string) $cached['user_id'], (string) $cached['refresh_token'], $responseLogger);
                    $this->storePayload($payload);

                    return $payload['access_token'];
                } catch (\Throwable) {
                }
            }

            $payload = $this->login($responseLogger);
            $this->storePayload($payload);

            return $payload['access_token'];
        } finally {
            optional($lock)->release();
        }
    }

    public function forgetToken(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function throttleRequests(?int $overrideMs = null): void
    {
        $minInterval = $overrideMs !== null ? max(0, $overrideMs) : max(0, $this->httpMinIntervalMs);
        if ($minInterval <= 0) {
            return;
        }

        $lock = Cache::lock(self::RATE_LOCK_KEY, 10);
        $lock->block(5);

        try {
            $now = (int) floor(microtime(true) * 1000);
            $readyAt = (int) Cache::get(self::RATE_LAST_AT_KEY, 0);
            $delayMs = max(0, $readyAt - $now);

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
                $now = (int) floor(microtime(true) * 1000);
            }

            Cache::put(self::RATE_LAST_AT_KEY, $now + $minInterval, 3600);
        } finally {
            optional($lock)->release();
        }
    }

    public function pauseOnRateLimit(?int $sleepSeconds = null, ?int $fallbackIntervalMs = null): void
    {
        $sleepSeconds = $sleepSeconds !== null ? max(0, $sleepSeconds) : max(0, $this->httpRateLimitSleepSeconds);
        if ($sleepSeconds <= 0) {
            $this->throttleRequests($fallbackIntervalMs);
            return;
        }

        $delayMs = $sleepSeconds * 1000;
        $lock = Cache::lock(self::RATE_LOCK_KEY, 10);
        $lock->block(5);

        try {
            $now = (int) floor(microtime(true) * 1000);
            $readyAt = (int) Cache::get(self::RATE_LAST_AT_KEY, 0);
            $scheduledAt = max($readyAt, $now + $delayMs);
            Cache::put(self::RATE_LAST_AT_KEY, $scheduledAt, 3600);
            $delayMs = max(0, $scheduledAt - (int) floor(microtime(true) * 1000));
        } finally {
            optional($lock)->release();
        }

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function login(?callable $responseLogger = null): array
    {
        if (!$this->username || !$this->password) {
            throw new \RuntimeException('HubCredito: credenciais ausentes.');
        }

        return $this->sendAuthRequest([
            'userName' => $this->username,
            'password' => $this->password,
            'grantTypes' => 'password',
        ], $responseLogger);
    }

    private function refreshToken(string $userId, string $refreshToken, ?callable $responseLogger = null): array
    {
        return $this->sendAuthRequest([
            'userName' => $userId,
            'password' => $refreshToken,
            'grantTypes' => $this->refreshGrantType,
        ], $responseLogger);
    }

    private function sendAuthRequest(array $payload, ?callable $responseLogger = null): array
    {
        $attempts = max(1, $this->httpRetry + 1);
        $lastResponse = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $this->throttleRequests();

            try {
                $response = Http::asJson()
                    ->acceptJson()
                    ->timeout(max(1, $this->httpTimeout))
                    ->connectTimeout(max(1, $this->httpConnectTimeout))
                    ->post($this->baseUrl . '/api/Login', $payload);
            } catch (ConnectionException $e) {
                if ($attempt < $attempts - 1) {
                    if ($this->httpRetryDelayMs > 0) {
                        usleep($this->httpRetryDelayMs * 1000);
                    }
                    continue;
                }

                throw $e;
            }

            $lastResponse = $response;
            if ($responseLogger !== null) {
                $responseLogger($response);
            }

            if ($response->status() === 429 && $attempt < $attempts - 1) {
                $this->pauseOnRateLimit();
                continue;
            }

            if ($response->serverError() && $attempt < $attempts - 1) {
                if ($this->httpRetryDelayMs > 0) {
                    usleep($this->httpRetryDelayMs * 1000);
                }
                continue;
            }

            break;
        }

        if (!$lastResponse instanceof HttpResponse || !$lastResponse->ok()) {
            throw new \RuntimeException('HubCredito: falha na autenticação.');
        }

        $json = $lastResponse->json();
        if (!is_array($json)) {
            throw new \RuntimeException('HubCredito: resposta de autenticação inválida.');
        }

        return $this->parseAuthPayload($json);
    }

    private function parseAuthPayload(array $json): array
    {
        $value = is_array($json['value'] ?? null) ? $json['value'] : [];
        $token = is_array($value['token'] ?? null) ? $value['token'] : [];

        $accessToken = $token['accessToken'] ?? null;
        $refreshToken = $token['refreshToken'] ?? null;
        $userId = $value['id'] ?? null;

        if (!is_string($accessToken) || $accessToken === '') {
            throw new \RuntimeException('HubCredito: access token ausente.');
        }

        $expiration = $token['expiration'] ?? null;
        $expiresAt = $expiration ? Carbon::parse((string) $expiration) : Carbon::now()->addHour();

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($refreshToken) ? $refreshToken : '',
            'user_id' => is_string($userId) && $userId !== '' ? $userId : (string) $this->username,
            'expires_at' => $expiresAt->getTimestamp(),
        ];
    }

    private function storePayload(array $payload): void
    {
        $ttl = max(30, ((int) ($payload['expires_at'] ?? 0)) - Carbon::now()->getTimestamp() - $this->tokenTtlSkew);
        Cache::put(self::CACHE_KEY, $payload, $ttl);
    }

    private function isPayloadUsable($payload): bool
    {
        if (!is_array($payload)) {
            return false;
        }

        $accessToken = $payload['access_token'] ?? null;
        $expiresAt = (int) ($payload['expires_at'] ?? 0);

        return is_string($accessToken)
            && $accessToken !== ''
            && $expiresAt > (Carbon::now()->getTimestamp() + $this->tokenTtlSkew);
    }
}
