<?php

namespace App\Modules\V8Fgts\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class V8FgtsExternalApiService
{
    private const TOKEN_CACHE_KEY = 'v8_fgts_external_api_token';

    public function createJob(string $title, string $cpfs): array
    {
        return $this->json('POST', 'v1/jobs', [
            'module' => 'v8_fgts',
            'title' => $title,
        ], $cpfs, 'text/plain;charset=UTF-8');
    }

    public function getJob(string $externalJobId): array
    {
        return $this->json('GET', "v1/jobs/{$externalJobId}");
    }

    public function cancelJob(string $externalJobId): array
    {
        return $this->json('POST', "v1/jobs/{$externalJobId}/cancel");
    }

    public function deleteJob(string $externalJobId): Response
    {
        return $this->request('DELETE', "v1/jobs/{$externalJobId}");
    }

    public function preview(string $externalJobId): Response
    {
        return $this->request('GET', "v1/jobs/{$externalJobId}/preview");
    }

    public function report(string $externalJobId): Response
    {
        return $this->request('GET', "v1/jobs/{$externalJobId}/report");
    }

    private function json(string $method, string $path, array $query = [], ?string $body = null, ?string $contentType = null): array
    {
        $response = $this->request($method, $path, $query, $body, $contentType);

        if (!$response->successful()) {
            throw new \RuntimeException($this->errorMessage($response));
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new \RuntimeException('Resposta inválida da API externa V8 FGTS.');
        }

        return $data;
    }

    private function request(string $method, string $path, array $query = [], ?string $body = null, ?string $contentType = null): Response
    {
        $response = $this->send($method, $path, $query, $body, $contentType, $this->token());
        if ($response->status() !== 401) {
            return $response;
        }

        Cache::forget(self::TOKEN_CACHE_KEY);

        return $this->send($method, $path, $query, $body, $contentType, $this->token());
    }

    private function send(string $method, string $path, array $query, ?string $body, ?string $contentType, string $token): Response
    {
        $request = Http::baseUrl(rtrim((string) config('v8_fgts.external_api.base_url'), '/'))
            ->acceptJson()
            ->withToken($token)
            ->timeout((int) config('v8_fgts.external_api.timeout', 30))
            ->connectTimeout((int) config('v8_fgts.external_api.connect_timeout', 10));

        if ($contentType !== null) {
            $request = $request->withBody($body ?? '', $contentType);
        }

        return $request->send($method, $path, [
            'query' => $query,
        ]);
    }

    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $lock = Cache::lock(self::TOKEN_CACHE_KEY . ':lock', 30);
        if (!$lock->get()) {
            throw new \RuntimeException('Autenticação da API externa V8 FGTS em andamento. Tente novamente.');
        }

        try {
            $cached = Cache::get(self::TOKEN_CACHE_KEY);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            $response = Http::baseUrl(rtrim((string) config('v8_fgts.external_api.base_url'), '/'))
                ->acceptJson()
                ->timeout((int) config('v8_fgts.external_api.timeout', 30))
                ->connectTimeout((int) config('v8_fgts.external_api.connect_timeout', 10))
                ->post('v1/auth/login', [
                    'email' => (string) config('v8_fgts.external_api.email'),
                    'password' => (string) config('v8_fgts.external_api.password'),
                ]);

            if (!$response->successful() || !is_string($response->json('token')) || $response->json('token') === '') {
                throw new \RuntimeException($this->errorMessage($response));
            }

            $ttl = now()->addMinutes(50);
            $expiresAt = $response->json('expires_at');
            if (is_string($expiresAt)) {
                try {
                    $ttl = Carbon::parse($expiresAt)->subMinute();
                } catch (\Throwable) {
                }
            }

            $token = $response->json('token');
            Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl->isFuture() ? $ttl : now()->addMinutes(5));

            return $token;
        } finally {
            $lock->release();
        }
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('message');

        return is_string($message) && $message !== ''
            ? $message
            : 'Falha na API externa V8 FGTS.';
    }
}
