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
     * POST /api/messages/internal
     * Payload: { ticketId, body }
     * Auth: Bearer (token da conexão)
     */
    public function sendInternal(string $ticketId, string $body, string $connectionToken): bool
    {
        // Unifica a base com os demais services (evita config inexistente backend_url)
        $baseUrl = rtrim((string) config('inovachat.api.base_url'), '/');

        if ($baseUrl === '' || $connectionToken === '' || $ticketId === '' || $body === '') {
            return false;
        }

        $endpoint = $baseUrl . '/api/messages/internal';

        $payload = [
            'ticketId' => (string) $ticketId,
            'body'     => (string) $body,
        ];

        $timeout      = (int) config('inovachat.http.timeout', 10);
        $connect      = (int) config('inovachat.http.connect_timeout', 5);
        $retries      = (int) config('inovachat.http.retry', 1);
        $retryDelayMs = (int) config('inovachat.http.retry_delay_ms', 200);

        $logFailures = (bool) config('inovachat.logging.log_failures', true);

        try {
            $request = Http::withToken($connectionToken)
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->connectTimeout($connect);

            $response = null;

            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                $response = $request->post($endpoint, $payload);

                if ($response->successful()) {
                    return true;
                }

                if ($attempt < $retries) {
                    usleep($retryDelayMs * 1000);
                }
            }

            if ($logFailures) {
                Log::warning('InternalMessageService: request failed', [
                    'ticket_id' => $ticketId,
                    'status'    => $response?->status(),
                ]);
            }

            return false;
        } catch (Throwable $e) {
            if ($logFailures) {
                Log::error('InternalMessageService: exception while sending internal message', [
                    'ticket_id' => $ticketId,
                    'exception' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }
}
