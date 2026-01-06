<?php

namespace App\Services\Inovachat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TicketService
{
    public function updateTicket(
        string $ticketId,
        string $status,
        ?string $queueId = null,
        ?string $userId = null,
        ?string $typebotSessionId = null,
        ?string $customA = null,
        ?string $customB = null,
        ?string $connectionToken = null
    ): bool {
        $baseUrl = rtrim((string) config('inovachat.api.base_url'), '/');
        $token   = (string) ($connectionToken ?: (string) config('inovachat.api.connection_token'));

        if ($baseUrl === '' || $token === '' || $ticketId === '' || $status === '') {
            return false;
        }

        $url = $baseUrl . '/api/tickets/updateAPI';

        $payload = [
            'ticketId' => $ticketId,
            'status'   => $status,
            'userId'   => $userId,
            'queueId'  => $queueId,
        ];

        if ($typebotSessionId !== null) $payload['typebot_sessionId'] = $typebotSessionId;
        if ($customA !== null) $payload['customA'] = $customA;
        if ($customB !== null) $payload['customB'] = $customB;

        $timeout      = (int) config('inovachat.http.timeout', 10);
        $connect      = (int) config('inovachat.http.connect_timeout', 5);
        $retries      = (int) config('inovachat.http.retry', 1);
        $retryDelayMs = (int) config('inovachat.http.retry_delay_ms', 200);

        $logFailures = (bool) config('inovachat.logging.log_failures', true);

        try {
            $request = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout($timeout)
                ->connectTimeout($connect);

            $response = null;

            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                $response = $request->post($url, $payload);

                if ($response->successful()) {
                    return true;
                }

                if ($attempt < $retries) {
                    usleep($retryDelayMs * 1000);
                }
            }

            if ($logFailures) {
                Log::warning('Inovachat ticket update failed', [
                    'ticketId' => $ticketId,
                    'status'   => $status,
                    'http'     => $response?->status(),
                ]);
            }

            return false;
        } catch (\Throwable $e) {
            if ($logFailures) {
                Log::error('Inovachat ticket update exception', [
                    'ticketId'  => $ticketId,
                    'status'    => $status,
                    'exception' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }
}
