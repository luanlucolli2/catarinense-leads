<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MultiConsulta\ShortLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ShortLinkProxyController extends Controller
{
    public function index(Request $request, ShortLinkService $service): SymfonyResponse { return $this->proxy($service, 'GET', '/links', [], $request->query()); }
    public function store(Request $request, ShortLinkService $service): SymfonyResponse { return $this->proxy($service, 'POST', '/links', $request->all()); }
    public function show(string $id, ShortLinkService $service): SymfonyResponse { return $this->proxy($service, 'GET', "/links/{$id}"); }
    public function update(Request $request, string $id, ShortLinkService $service): SymfonyResponse { return $this->proxy($service, 'PATCH', "/links/{$id}", $request->all()); }
    public function destroy(string $id, ShortLinkService $service): SymfonyResponse { return $this->proxy($service, 'DELETE', "/links/{$id}"); }
    public function disable(string $id, ShortLinkService $service): SymfonyResponse { return $this->proxy($service, 'POST', "/links/{$id}/disable"); }
    public function enable(string $id, ShortLinkService $service): SymfonyResponse { return $this->proxy($service, 'POST', "/links/{$id}/enable"); }
    public function analytics(Request $request, string $id, ShortLinkService $service): SymfonyResponse { return $this->proxy($service, 'GET', "/links/{$id}/analytics", [], $request->query()); }
    public function clicks(Request $request, string $id, ShortLinkService $service): SymfonyResponse { return $this->proxy($service, 'GET', "/links/{$id}/clicks", [], $request->query()); }

    public function export(Request $request, string $id, ShortLinkService $service): SymfonyResponse
    {
        try {
            $upstream = $service->request('GET', "/links/{$id}/export.csv", [], $request->query());
        } catch (\Throwable) {
            return $this->unavailable();
        }

        if (! $upstream->successful()) {
            return $this->errorResponse($upstream->status(), $upstream->json());
        }

        return response($upstream->body(), $upstream->status(), array_filter([
            'Content-Type' => $upstream->header('Content-Type') ?: 'text/csv; charset=UTF-8',
            'Content-Disposition' => $upstream->header('Content-Disposition'),
        ]));
    }

    private function proxy(ShortLinkService $service, string $method, string $path, array $payload = [], array $query = []): SymfonyResponse
    {
        try {
            $upstream = $service->request($method, $path, $payload, $query);
        } catch (\Throwable) {
            return $this->unavailable();
        }

        if ($upstream->status() === 204) {
            return response()->noContent();
        }

        if (! $upstream->successful()) {
            return $this->errorResponse($upstream->status(), $upstream->json());
        }

        return response()->json($upstream->json(), $upstream->status());
    }

    private function errorResponse(int $status, mixed $payload): SymfonyResponse
    {
        if ($status === 401) {
            return $this->unavailable();
        }

        $payload = is_array($payload) ? $payload : [];

        return response()->json([
            'code' => is_string($payload['code'] ?? null) ? $payload['code'] : 'shortlinks_unavailable',
            'message' => is_string($payload['message'] ?? null) ? $payload['message'] : 'Não foi possível concluir a operação.',
        ], $status);
    }

    private function unavailable(): SymfonyResponse
    {
        return response()->json([
            'code' => 'shortlinks_unavailable',
            'message' => 'Serviço de links indisponível.',
        ], 503);
    }
}
