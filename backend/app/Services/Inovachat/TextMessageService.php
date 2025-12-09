<?php

namespace App\Services\Inovachat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TextMessageService
{
    /**
     * Envia mensagem de texto via API /api/messages/send do Inovachat.
     *
     * @param string $number     Número em qualquer formato (será normalizado para dígitos).
     * @param string $body       Texto da mensagem.
     * @param string $openTicket "0" para não abrir ticket, "1" para abrir; conforme doc Inovachat.
     * @param string $queueId    ID da fila (obrigatório quando openTicket = "1").
     *
     * @return bool true se HTTP 200 e sem erro grosseiro; false caso contrário.
     */
    public function sendText(
        string $number,
        string $body,
        string $openTicket = '0',
        string $queueId = '0'
    ): bool {
        $apiBase         = rtrim(config('inovachat.api.base_url'), '/');
        $connectionToken = config('inovachat.api.connection_token');

        if (empty($apiBase) || empty($connectionToken)) {
            Log::warning('Inovachat text message skipped: missing base_url or connection_token', [
                'number' => $number,
            ]);

            return false;
        }

        $cleanNumber = preg_replace('/\D+/', '', $number) ?: null;

        if (! $cleanNumber) {
            Log::warning('Inovachat text message skipped: invalid phone format', [
                'raw' => $number,
            ]);

            return false;
        }

        $payload = [
            'number'     => $cleanNumber,
            'openTicket' => (string) $openTicket,
            'queueId'    => (string) $queueId,
            'body'       => $body,
        ];

        $timeout       = (int) config('inovachat.http.timeout', 10);
        $connect       = (int) config('inovachat.http.connect_timeout', 5);
        $retries       = (int) config('inovachat.http.retry', 1);
        $retryDelayMs  = (int) config('inovachat.http.retry_delay_ms', 200);

        try {
            $request = Http::withToken($connectionToken)
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout($connect);

            $response = null;

            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                $response = $request->post($apiBase . '/api/messages/send', $payload);

                if ($response->successful()) {
                    $json = $response->json();

                    Log::info('Inovachat text message sent', [
                        'number'   => $payload['number'],
                        'status'   => $response->status(),
                        'response' => $json,
                    ]);

                    // A doc indica algo como:
                    // { "mensagem": "Mensagem enviada SEM TICKET", "ticket": null }
                    return true;
                }

                if ($attempt < $retries) {
                    usleep($retryDelayMs * 1000);
                }
            }

            Log::warning('Inovachat text message failed after retries', [
                'number' => $payload['number'],
                'status' => $response?->status(),
                'body'   => $response?->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Inovachat text message exception', [
                'number'    => $cleanNumber,
                'exception' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
