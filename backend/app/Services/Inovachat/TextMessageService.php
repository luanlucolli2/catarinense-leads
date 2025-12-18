<?php

namespace App\Services\Inovachat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TextMessageService
{
    /**
     * @param string      $number
     * @param string      $body
     * @param string      $openTicket   (somente API Básica)
     * @param string      $queueId      (somente API Básica)
     * @param string|null $connectionToken Token da conexão que enviará a mensagem (Bearer). Se null/empty, usa fallback do config.
     */
    public function sendText(
        string $number,
        string $body,
        string $openTicket = '0',
        string $queueId = '0',
        ?string $connectionToken = null
    ): bool {
        $mode = strtolower((string) config('inovachat.api.message_mode', 'basic'));

        $apiBase = rtrim((string) config('inovachat.api.base_url'), '/');
        $apiBaseOfficial = rtrim((string) config('inovachat.api.official_base_url', $apiBase), '/');

        $token = (string) ($connectionToken ?: (string) config('inovachat.api.connection_token'));

        // base necessária muda conforme o modo
        $baseToUse = $mode === 'official' ? $apiBaseOfficial : $apiBase;

        if ($baseToUse === '' || $token === '') {
            Log::warning('Inovachat text message skipped: missing base_url or connection_token', [
                'mode' => $mode,
                'has_base' => $baseToUse !== '',
                'has_token' => $token !== '',
            ]);
            return false;
        }

        $cleanNumber = preg_replace('/\D+/', '', $number) ?: null;

        if (! $cleanNumber) {
            Log::warning('Inovachat text message skipped: invalid phone format', [
                'raw' => $number,
                'mode' => $mode,
            ]);
            return false;
        }

        $timeout      = (int) config('inovachat.http.timeout', 10);
        $connect      = (int) config('inovachat.http.connect_timeout', 5);
        $retries      = (int) config('inovachat.http.retry', 1);
        $retryDelayMs = (int) config('inovachat.http.retry_delay_ms', 200);

        // Endpoint/payload conforme documentação:
        // - Oficial: /api/messages/sendOfficialData  { number, text } :contentReference[oaicite:4]{index=4} :contentReference[oaicite:5]{index=5}
        // - Básica:  /api/messages/send             { number, openTicket, queueId, body } :contentReference[oaicite:6]{index=6}
        if ($mode === 'official') {
            $url = $baseToUse . '/api/messages/sendOfficialData';
            $payload = [
                'number' => $cleanNumber,
                'text'   => $body,
            ];
        } else {
            $url = $baseToUse . '/api/messages/send';
            $payload = [
                'number'     => $cleanNumber,
                'openTicket' => (string) $openTicket,
                'queueId'    => (string) $queueId,
                'body'       => $body,
            ];
        }

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
                    Log::info('Inovachat text message sent', [
                        'mode' => $mode,
                        'number' => $cleanNumber,
                        'status' => $response->status(),
                        'endpoint' => $url,
                    ]);
                    return true;
                }

                if ($attempt < $retries) {
                    usleep($retryDelayMs * 1000);
                }
            }

            Log::warning('Inovachat text message failed after retries', [
                'mode' => $mode,
                'number' => $cleanNumber,
                'endpoint' => $url,
                'status' => $response?->status(),
                'body' => $response?->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Inovachat text message exception', [
                'mode' => $mode,
                'number' => $cleanNumber,
                'endpoint' => $url ?? null,
                'exception' => $e->getMessage(),
            ]);
        }

        return false;
    }
}
