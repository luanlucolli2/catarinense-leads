<?php

namespace App\Services\Inovachat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TicketService
{
    /**
     * Atualiza um ticket no Inovachat (status, fila, atendente, etc.).
     *
     * Baseado na documentação "API Atualizar Ticket":
     * - Endpoint: {{BACKEND_URL}}/api/tickets/updateAPI
     * - Autenticação: Bearer {seutokenaqui} (token cadastrado na conexão)
     * - Campos: ticketId, status (open/pending/closed), userId, queueId, typebot_sessionId, customA, customB.
     */
    public function updateTicket(
        string $ticketId,
        ?string $status = null,
        ?string $queueId = null,
        ?string $userId = null,
        ?string $typebotSessionId = null,
        ?string $customA = null,
        ?string $customB = null
    ): array {
        $baseUrl = rtrim(config('inovachat.api.base_url'), '/');
        $token   = config('inovachat.api.connection_token');

        $endpoint = $baseUrl . '/api/tickets/updateAPI';

        $payload = [
            'ticketId' => (string) $ticketId,
            'status'   => $status,
            'userId'   => $userId,
            'queueId'  => $queueId,
            'typebot_sessionId' => $typebotSessionId,
            'customA'  => $customA,
            'customB'  => $customB,
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ])->post($endpoint, $payload);

        if (! $response->successful()) {
            Log::warning('Inovachat updateTicket failed', [
                'ticket_id' => $ticketId,
                'status'    => $status,
                'queue_id'  => $queueId,
                'http_code' => $response->status(),
                'body'      => $response->body(),
            ]);
        } else {
            Log::info('Inovachat ticket updated', [
                'ticket_id' => $ticketId,
                'status'    => $status,
                'queue_id'  => $queueId,
            ]);
        }

        return $response->json() ?? [];
    }
}
