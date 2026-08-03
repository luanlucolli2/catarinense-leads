<?php

namespace App\Modules\V8Fgts\Services;

use App\Services\MultiConsulta\ShortLinkService;
use Illuminate\Http\Client\Response;

class V8FgtsExternalApiService
{
    public function __construct(private ShortLinkService $multiConsulta)
    {
    }

    public function createJob(string $title, string $cpfs): array
    {
        return $this->json('POST', '/jobs', [
            'module' => 'v8_fgts',
            'title' => $title,
        ], $cpfs);
    }

    public function getJob(string $externalJobId): array
    {
        return $this->json('GET', "/jobs/{$externalJobId}");
    }

    public function cancelJob(string $externalJobId): array
    {
        return $this->json('POST', "/jobs/{$externalJobId}/cancel");
    }

    public function deleteJob(string $externalJobId): Response
    {
        return $this->request('DELETE', "/jobs/{$externalJobId}");
    }

    public function preview(string $externalJobId): Response
    {
        return $this->request('GET', "/jobs/{$externalJobId}/preview");
    }

    public function report(string $externalJobId): Response
    {
        return $this->request('GET', "/jobs/{$externalJobId}/report");
    }

    private function json(string $method, string $path, array $query = [], ?string $body = null): array
    {
        $response = $this->request($method, $path, $query, $body);

        if (!$response->successful()) {
            throw new \RuntimeException($this->errorMessage($response));
        }

        $data = $response->json();
        if (!is_array($data)) {
            throw new \RuntimeException('Resposta inválida da API externa V8 FGTS.');
        }

        return $data;
    }

    private function request(string $method, string $path, array $query = [], ?string $body = null): Response
    {
        return $body === null
            ? $this->multiConsulta->request($method, $path, [], $query)
            : $this->multiConsulta->requestText($method, $path, $body, $query);
    }

    private function errorMessage(Response $response): string
    {
        $message = $response->json('message');

        return is_string($message) && $message !== ''
            ? $message
            : 'Falha na API externa V8 FGTS.';
    }
}
