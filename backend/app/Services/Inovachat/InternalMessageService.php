<?php

namespace App\Services\Inovachat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class InternalMessageService
{
    /**
     * Envia uma mensagem interna (whisper) para um ticket no Inovachat.
     *
     * Doc (Inovachat): POST /api/messages/internal
     * Payload: { ticketId, body }
     * Auth: Bearer (token da conexão)
     */
    public function sendInternal(string $ticketId, string $body, string $connectionToken): bool
    {
        $baseUrl = rtrim((string) config('inovachat.backend_url', config('services.inovachat.backend_url', '')), '/');

        if ($baseUrl === '') {
            Log::warning('InternalMessageService: missing backend_url config');
            return false;
        }

        if ($connectionToken === '') {
            Log::warning('InternalMessageService: missing connection token', [
                'ticket_id' => $ticketId,
            ]);
            return false;
        }

        if ($ticketId === '' || $body === '') {
            Log::warning('InternalMessageService: missing ticketId/body', [
                'ticket_id' => $ticketId,
                'has_body'  => $body !== '',
            ]);
            return false;
        }

        $endpoint = $baseUrl . '/api/messages/internal';

        $payload = [
            'ticketId' => (string) $ticketId,
            'body'     => (string) $body,
        ];

        try {
            $timeout = (int) config('inovachat.http.timeout', 15);

            $response = Http::timeout($timeout)
                ->withToken($connectionToken)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, $payload);

            if ($response->successful()) {
                return true;
            }

            Log::warning('InternalMessageService: request failed', [
                'ticket_id' => $ticketId,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);

            return false;
        } catch (Throwable $e) {
            Log::error('InternalMessageService: exception while sending internal message', [
                'ticket_id'  => $ticketId,
                'exception'  => $e->getMessage(),
            ]);

            return false;
        }
    }
}
