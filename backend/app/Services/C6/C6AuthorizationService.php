<?php

namespace App\Services\C6;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class C6AuthorizationService
{
    /**
     * Cache key global para o access_token do C6.
     */
    protected const CACHE_KEY_ACCESS_TOKEN = 'c6bank:access_token';

    /**
     * Gera link de autorização (empréstimo do trabalhador) para um CPF.
     *
     * Doc: Geração de Link para Autorização de Consulta de Dados – Empréstimo do Trabalhador
     */
    public function generateLink(string $cpf, string $nome, ?string $ddd = null, ?string $numero = null): string
    {
        $baseUrl = rtrim(config('c6bank.base_url'), '/');

        // 1) Pega token (cacheado)
        $token = $this->getAccessToken();

        $payload = [
            'nome'            => $nome,
            'cpf'             => $cpf,
            'data_nascimento' => $this->fakeBirthDate(), // > 19 anos
        ];

        if ($ddd && $numero) {
            $payload['telefone'] = [
                'numero'      => $numero,
                'codigo_area' => $ddd,
            ];
        }

        $accept = config(
            'c6bank.headers.authorization_generate_accept',
            'application/vnd.c6bank_authorization_generate_liveness_v1+json'
        );

        $timeout      = (int) config('c6bank.http.timeout', 10);
        $connect      = (int) config('c6bank.http.connect_timeout', 5);
        $retries      = (int) config('c6bank.http.retry', 1);
        $retryDelayMs = (int) config('c6bank.http.retry_delay_ms', 200);

        $url = $baseUrl . '/marketplace/authorization/generate-liveness';

        try {
            $makeRequest = function (string $token) use ($accept, $timeout, $connect) {
                return Http::withHeaders([
                    'Accept'        => $accept,
                    'Content-Type'  => 'application/json',
                    'Authorization' => $token, // token puro, sem "Bearer"
                ])
                    ->timeout($timeout)
                    ->connectTimeout($connect);
            };

            $request  = $makeRequest($token);
            $response = null;

            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                $response = $request->post($url, $payload);

                if ($response->successful()) {
                    $json = $response->json();

                    $link = $json['link'] ?? null;

                    if (! is_string($link) || $link === '') {
                        throw new \RuntimeException('C6 authorization link missing in response');
                    }

                    Log::info('C6 authorization link generated', [
                        'cpf'  => $cpf,
                        'link' => $link,
                    ]);

                    return $link;
                }

                // Se der 401, força refresh do token e tenta de novo (uma vez)
                if ($response->status() === 401 && $attempt < $retries) {
                    Log::warning('C6 generate-liveness got 401, refreshing token and retrying', [
                        'cpf' => $cpf,
                    ]);

                    $token   = $this->getAccessToken(forceRefresh: true);
                    $request = $makeRequest($token);

                    usleep($retryDelayMs * 1000);
                    continue;
                }

                if ($attempt < $retries) {
                    usleep($retryDelayMs * 1000);
                }
            }

            throw new \RuntimeException('C6 generate-liveness failed: HTTP ' . $response?->status());
        } catch (\Throwable $e) {
            Log::error('C6 authorization link error', [
                'cpf'       => $cpf,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Consulta o status da autorização do cliente no C6.
     *
     * Doc: "Consulta de status da autorização do cliente"
     *
     * Path/método (manual):
     *   POST /marketplace/authorization/status
     * Body:
     *   { "cpf": "XXXXXXXXXXX" }
     *
     * @return array{
     *     status: string,
     *     raw: array|null
     * }
     */
    public function checkAuthorizationStatus(string $cpf): array
    {
        $baseUrl = rtrim(config('c6bank.base_url'), '/');

        // 1) Token (cacheado)
        $token = $this->getAccessToken();

        $url = $baseUrl . '/marketplace/authorization/status';

        $accept = config(
            'c6bank.headers.authorization_status_accept',
            'application/vnd.c6bank_authorization_status_v1+json'
        );

        $timeout      = (int) config('c6bank.http.timeout', 10);
        $connect      = (int) config('c6bank.http.connect_timeout', 5);
        $retries      = (int) config('c6bank.http.retry', 1);
        $retryDelayMs = (int) config('c6bank.http.retry_delay_ms', 200);

        try {
            $makeRequest = function (string $token) use ($accept, $timeout, $connect) {
                return Http::withHeaders([
                    'Accept'        => $accept,
                    'Content-Type'  => 'application/json',
                    'Authorization' => $token,
                ])
                    ->timeout($timeout)
                    ->connectTimeout($connect);
            };

            $request  = $makeRequest($token);
            $response = null;

            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                $response = $request->post($url, [
                    'cpf' => $cpf,
                ]);

                if ($response->successful()) {
                    $json = $response->json() ?? [];

                    // Manual: "status": "AGUARDANDO_AUTORIZACAO/AUTORIZADO/NAO_AUTORIZADO"
                    $remoteStatus = strtoupper((string) ($json['status'] ?? 'PENDING'));

                    Log::info('C6 authorization status checked', [
                        'cpf'    => $cpf,
                        'status' => $remoteStatus,
                    ]);

                    return [
                        'status' => $remoteStatus,
                        'raw'    => $json,
                    ];
                }

                // 401 => refresh token e tenta de novo
                if ($response->status() === 401 && $attempt < $retries) {
                    Log::warning('C6 authorization status got 401, refreshing token and retrying', [
                        'cpf' => $cpf,
                    ]);

                    $token   = $this->getAccessToken(forceRefresh: true);
                    $request = $makeRequest($token);

                    usleep($retryDelayMs * 1000);
                    continue;
                }

                if ($attempt < $retries) {
                    usleep($retryDelayMs * 1000);
                }
            }

            throw new \RuntimeException(
                'C6 authorization status failed: HTTP ' . $response?->status()
            );
        } catch (\Throwable $e) {
            Log::error('C6 authorization status error', [
                'cpf'       => $cpf,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Obtém access_token do C6 via /auth/token.
     *
     * - Usa cache (Redis) com TTL configurável.
     * - Se $forceRefresh = true, ignora cache e atualiza token.
     */
    protected function getAccessToken(bool $forceRefresh = false): string
    {
        $cacheKey = self::CACHE_KEY_ACCESS_TOKEN;

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $baseUrl = rtrim(config('c6bank.base_url'), '/');

        $username = config('c6bank.auth.username');
        $password = config('c6bank.auth.password');

        if (! $username || ! $password) {
            throw new \RuntimeException('C6 credentials not configured');
        }

        $timeout = (int) config('c6bank.http.timeout', 10);
        $connect = (int) config('c6bank.http.connect_timeout', 5);

        $response = Http::asForm()
            ->timeout($timeout)
            ->connectTimeout($connect)
            ->post($baseUrl . '/auth/token', [
                'username' => $username,
                'password' => $password,
            ]);

        if (! $response->successful()) {
            // Em caso de erro, limpa cache para evitar token podre
            Cache::forget($cacheKey);

            throw new \RuntimeException('C6 auth failed: HTTP ' . $response->status());
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            Cache::forget($cacheKey);

            throw new \RuntimeException('C6 auth response missing access_token');
        }

        // TTL do token (em segundos) – vindo do .env ou padrão
        $ttlSeconds = (int) config('c6bank.token.ttl_seconds', 1199);
        $skew       = (int) config('c6bank.token.skew', 60);


        // Evita expirar exatamente junto com o token real
        $cacheTtl = max(60, $ttlSeconds - $skew);

        Cache::put($cacheKey, $token, $cacheTtl);

        Log::info('C6 access_token refreshed and cached', [
            'ttl_seconds' => $cacheTtl,
        ]);

        return $token;
    }

    /**
     * Gera uma data de nascimento aleatória entre 20 e 60 anos atrás.
     */
    protected function fakeBirthDate(int $minAge = 20, int $maxAge = 60): string
    {
        $now  = Carbon::now();
        $age  = random_int($minAge, $maxAge);
        $days = random_int(0, 364);

        return $now
            ->copy()
            ->subYears($age)
            ->subDays($days)
            ->format('Y-m-d');
    }
}
