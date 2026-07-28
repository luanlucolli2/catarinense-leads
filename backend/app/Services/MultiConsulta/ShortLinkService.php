<?php

declare(strict_types=1);

namespace App\Services\MultiConsulta;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShortLinkService
{
    private const CACHE_KEY = 'multi_consulta:short_links:token';

    public function request(string $method, string $path, array $payload = [], array $query = []): Response
    {
        $response = $this->send($method, $path, $payload, $query, $this->token());

        if ($response->status() !== 401) {
            return $response;
        }

        Cache::forget(self::CACHE_KEY);

        return $this->send($method, $path, $payload, $query, $this->token());
    }

    private function send(string $method, string $path, array $payload, array $query, string $token): Response
    {
        $client = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($token)
            ->timeout(max(1, (int) config('multi_consulta.timeout', 15)))
            ->connectTimeout(max(1, (int) config('multi_consulta.connect_timeout', 5)));

        $url = '/v1' . $path;

        return match (strtoupper($method)) {
            'GET' => $client->get($url, $query),
            'POST' => $client->post($url . $this->queryString($query), $payload),
            'PATCH' => $client->patch($url . $this->queryString($query), $payload),
            'DELETE' => $client->delete($url . $this->queryString($query), $payload),
            default => throw new RuntimeException('Método HTTP não suportado.'),
        };
    }

    private function token(): string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && is_string($cached['token'] ?? null) && $cached['token'] !== '') {
            return $cached['token'];
        }

        return Cache::lock(self::CACHE_KEY . ':lock', 15)->block(5, function (): string {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && is_string($cached['token'] ?? null) && $cached['token'] !== '') {
                return $cached['token'];
            }

            return $this->login();
        });
    }

    private function login(): string
    {
        $email = trim((string) config('multi_consulta.email'));
        $password = (string) config('multi_consulta.password');

        if ($email === '' || $password === '') {
            throw new RuntimeException('Credenciais da Multi Consulta não configuradas.');
        }

        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->timeout(max(1, (int) config('multi_consulta.timeout', 15)))
            ->connectTimeout(max(1, (int) config('multi_consulta.connect_timeout', 5)))
            ->post('/v1/auth/login', ['email' => $email, 'password' => $password]);

        $token = $response->json('token');
        $expiresAt = $response->json('expires_at');

        if (! $response->successful() || ! is_string($token) || $token === '' || ! is_string($expiresAt)) {
            throw new RuntimeException('Não foi possível autenticar na Multi Consulta.');
        }

        try {
            $seconds = CarbonImmutable::parse($expiresAt)->getTimestamp() - now()->getTimestamp()
                - max(0, (int) config('multi_consulta.token_skew_seconds', 60));
        } catch (\Throwable) {
            throw new RuntimeException('Expiração de token inválida da Multi Consulta.');
        }

        if ($seconds <= 0) {
            throw new RuntimeException('Token da Multi Consulta expirado.');
        }

        Cache::put(self::CACHE_KEY, ['token' => $token], $seconds);

        return $token;
    }

    private function baseUrl(): string
    {
        $baseUrl = (string) config('multi_consulta.base_url');

        if ($baseUrl === '') {
            throw new RuntimeException('URL da Multi Consulta não configurada.');
        }

        return $baseUrl;
    }

    private function queryString(array $query): string
    {
        $query = array_filter($query, static fn ($value): bool => $value !== null && $value !== '');

        return $query === [] ? '' : '?' . http_build_query($query);
    }
}
