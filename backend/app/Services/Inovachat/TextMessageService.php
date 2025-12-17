<?php

namespace App\Services\Inovachat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TextMessageService
{
    /**
     * @param string      $number
     * @param string      $body
     * @param string      $openTicket
     * @param string      $queueId
     * @param string|null $connectionToken Token da conexão que enviará a mensagem (Bearer). Se null/empty, usa fallback do config.
     */
    public function sendText(
        string $number,
        string $body,
        string $openTicket = '0',
        string $queueId = '0',
        ?string $connectionToken = null
    ): bool {
        $apiBase = rtrim((string) config('inovachat.api.base_url'), '/');

        $token = (string) ($connectionToken ?: (string) config('inovachat.api.connection_token'));

        if ($apiBase === '' || $token === '') {
            Log::warning('Inovachat text message skipped: missing base_url or connection_token', [
                'number' => $number,
                'has_base' => $apiBase !== '',
                'has_token' => $token !== '',
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

        $timeout      = (int) config('inovachat.http.timeout', 10);
        $connect      = (int) config('inovachat.http.connect_timeout', 5);
        $retries      = (int) config('inovachat.http.retry', 1);
        $retryDelayMs = (int) config('inovachat.http.retry_delay_ms', 200);

        try {
            $request = Http::withToken($token)
                ->acceptJson()
                ->timeout($timeout)
                ->connectTimeout($connect);

            $response = null;

            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                $response = $request->post($apiBase . '/api/messages/send', $payload);

                if ($response->successful()) {
                    Log::info('Inovachat text message sent', [
                        'number' => $payload['number'],
                        'status' => $response->status(),
                    ]);
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
