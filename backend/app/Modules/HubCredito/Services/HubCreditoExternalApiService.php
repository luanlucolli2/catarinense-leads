<?php

namespace App\Modules\HubCredito\Services;

use App\Services\MultiConsulta\ShortLinkService;
use Illuminate\Http\Client\Response;

class HubCreditoExternalApiService
{
    public function __construct(private ShortLinkService $multiConsulta) {}

    public function createJob(string $title, string $lines, ?string $scheduledFor): array
    {
        return $this->json('POST', '/jobs', array_filter([
            'module' => 'hubcredito_clt', 'title' => $title, 'scheduled_for' => $scheduledFor,
        ]), $lines);
    }

    public function getJob(string $id): array { return $this->json('GET', "/jobs/{$id}"); }
    public function pauseJob(string $id): array { return $this->json('POST', "/jobs/{$id}/pause"); }
    public function resumeJob(string $id): array { return $this->json('POST', "/jobs/{$id}/resume"); }
    public function cancelJob(string $id): array { return $this->json('POST', "/jobs/{$id}/cancel"); }
    public function deleteJob(string $id): Response { return $this->request('DELETE', "/jobs/{$id}"); }
    public function preview(string $id): Response { return $this->request('GET', "/jobs/{$id}/preview"); }
    public function report(string $id): Response { return $this->request('GET', "/jobs/{$id}/report"); }

    private function json(string $method, string $path, array $query = [], ?string $body = null): array
    {
        $response = $this->request($method, $path, $query, $body);
        if (!$response->successful()) throw new \RuntimeException((string) ($response->json('message') ?: 'Falha na API externa Hub Crédito.'));
        $data = $response->json();
        if (!is_array($data)) throw new \RuntimeException('Resposta inválida da API externa Hub Crédito.');
        return $data;
    }

    private function request(string $method, string $path, array $query = [], ?string $body = null): Response
    {
        return $body === null ? $this->multiConsulta->request($method, $path, [], $query) : $this->multiConsulta->requestText($method, $path, $body, $query);
    }
}
