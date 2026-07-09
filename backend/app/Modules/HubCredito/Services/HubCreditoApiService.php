<?php

namespace App\Modules\HubCredito\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubCreditoApiService
{
    private string $baseUrl;
    private int $httpTimeout;
    private int $httpConnectTimeout;
    private int $httpRetry;
    private int $httpRetryDelayMs;
    private int $httpMinIntervalMs;
    private int $httpRateLimitSleepSeconds;
    private bool $logEnabled;
    private bool $logApiResponses;
    private bool $logApiResponseBody;
    private int $logApiResponseBodyMaxChars;
    private ?int $jobId = null;
    private ?int $rateLimitOverrideMs = null;
    private HubCreditoSharedAuthService $sharedAuth;

    public function __construct()
    {
        $auth = (array) config('hubcredito.auth', []);
        $http = (array) config('hubcredito.http', []);
        $logging = (array) config('hubcredito.logging', []);

        $this->baseUrl = rtrim((string) ($auth['base_url'] ?? ''), '/');
        $this->httpTimeout = (int) ($http['timeout'] ?? 30);
        $this->httpConnectTimeout = (int) ($http['connect_timeout'] ?? 10);
        $this->httpRetry = (int) ($http['retry'] ?? 1);
        $this->httpRetryDelayMs = (int) ($http['retry_delay_ms'] ?? 300);
        $this->httpMinIntervalMs = (int) ($http['min_interval_ms'] ?? 1000);
        $this->httpRateLimitSleepSeconds = (int) ($http['rate_limit_sleep_seconds'] ?? 15);
        $this->logEnabled = (bool) ($logging['enabled'] ?? false);
        $this->logApiResponses = (bool) ($logging['api_responses'] ?? false);
        $this->logApiResponseBody = (bool) ($logging['api_response_body'] ?? false);
        $this->logApiResponseBodyMaxChars = max(256, (int) ($logging['api_response_body_max_chars'] ?? 4000));
        $this->sharedAuth = new HubCreditoSharedAuthService($auth, $http);
    }

    public function setJobId(?int $jobId): void
    {
        $this->jobId = $jobId;
    }

    public function setRateLimitMs(?int $intervalMs): void
    {
        $this->rateLimitOverrideMs = $intervalMs !== null ? max(0, (int) $intervalMs) : null;
    }

    public function createPreSimulacao(array $payload): array
    {
        return $this->request('post', '/api/presimulacao', $payload);
    }

    public function listPreSimulacao(array $query): array
    {
        return $this->request('get', '/api/PreSimulacao', $query);
    }

    public function simulate(array $payload): array
    {
        return $this->request('post', '/api/Clt/simular', $payload);
    }

    private function request(string $method, string $path, array $data): array
    {
        $attempts = max(1, $this->httpRetry + 1);

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $token = $this->sharedAuth->getAccessToken();
            if (!$token) {
                return $this->errorResult('HubCredito: token ausente.', null, false, []);
            }

            $this->sharedAuth->throttleRequests($this->effectiveMinIntervalMs());

            try {
                $client = Http::withToken($token)
                    ->acceptJson()
                    ->timeout(max(1, $this->httpTimeout))
                    ->connectTimeout(max(1, $this->httpConnectTimeout));

                $response = $method === 'get'
                    ? $client->get($this->baseUrl . $path, $data)
                    : $client->asJson()->post($this->baseUrl . $path, $data);
            } catch (ConnectionException) {
                if ($attempt < $attempts - 1) {
                    if ($this->httpRetryDelayMs > 0) {
                        usleep($this->httpRetryDelayMs * 1000);
                    }
                    continue;
                }

                return $this->errorResult('HubCredito: falha de conexão.', null, true, []);
            } catch (\Throwable $e) {
                return $this->errorResult('HubCredito: erro inesperado.', null, false, [
                    'exception' => $e->getMessage(),
                ]);
            }

            $this->logResponse($method, $path, $response);

            if ($response->status() === 401 && $attempt < $attempts - 1) {
                $this->sharedAuth->forgetToken();
                continue;
            }

            if ($response->status() === 429 && $attempt < $attempts - 1) {
                $this->sharedAuth->pauseOnRateLimit($this->httpRateLimitSleepSeconds, $this->effectiveMinIntervalMs());
                continue;
            }

            if ($response->serverError() && $attempt < $attempts - 1) {
                if ($this->httpRetryDelayMs > 0) {
                    usleep($this->httpRetryDelayMs * 1000);
                }
                continue;
            }

            return $this->normalizeResponse($response);
        }

        return $this->errorResult('HubCredito: requisição falhou.', null, false, []);
    }

    private function normalizeResponse(HttpResponse $response): array
    {
        $json = $response->json();
        $body = is_array($json) ? $json : [];
        $errors = $this->extractErrors($body);
        $message = $errors !== []
            ? implode(' | ', $errors)
            : ($response->successful() ? '' : "HTTP {$response->status()}");

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => $body,
            'errors' => $errors,
            'message' => $message,
            'retriable' => $response->status() === 429 || $response->serverError(),
        ];
    }

    private function extractErrors(array $body): array
    {
        $errors = [];

        $rawErrors = $body['errors'] ?? null;
        if (is_array($rawErrors)) {
            foreach ($rawErrors as $error) {
                $error = trim((string) $error);
                if ($error !== '') {
                    $errors[] = $error;
                }
            }
        }

        $message = trim((string) ($body['message'] ?? ''));
        if ($message !== '') {
            $errors[] = $message;
        }

        $mensagemErro = trim((string) ($body['mensagemErro'] ?? ''));
        if ($mensagemErro !== '') {
            $errors[] = $mensagemErro;
        }

        return array_values(array_unique($errors));
    }

    private function errorResult(string $message, ?int $status, bool $retriable, array $body): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'body' => $body,
            'errors' => $message !== '' ? [$message] : [],
            'message' => $message,
            'retriable' => $retriable,
        ];
    }

    private function logResponse(string $method, string $path, HttpResponse $response): void
    {
        if (!$this->logEnabled || !$this->logApiResponses) {
            return;
        }

        try {
            $context = [
                'job_id' => $this->jobId,
                'method' => strtoupper($method),
                'path' => $path,
                'status' => $response->status(),
            ];

            if ($this->logApiResponseBody) {
                $body = (string) $response->body();
                $truncated = strlen($body) > $this->logApiResponseBodyMaxChars;
                $context['body'] = $truncated
                    ? substr($body, 0, $this->logApiResponseBodyMaxChars) . '...[truncated]'
                    : $body;
            }

            Log::warning('[HUBCREDITO] API response', $context);
        } catch (\Throwable) {
        }
    }

    private function effectiveMinIntervalMs(): int
    {
        return $this->rateLimitOverrideMs ?? $this->httpMinIntervalMs;
    }
}
