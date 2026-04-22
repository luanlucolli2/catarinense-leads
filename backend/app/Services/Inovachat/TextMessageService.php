<?php

namespace App\Services\Inovachat;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TextMessageService
{
    /**
     * @param string      $number
     * @param string      $body
     * @param string      $openTicket   (somente modo basic)
     * @param string      $queueId      (somente modo basic)
     * @param string|null $connectionToken Token da conexão (Bearer). Se null/empty, usa fallback do config.
     */
    public function sendText(
        string $number,
        string $body,
        string $openTicket = '0',
        string $queueId = '0',
        ?string $connectionToken = null
    ): bool {
        $apiBase         = rtrim((string) config('inovachat.api.base_url'), '/');
        $apiBaseOfficial = rtrim((string) config('inovachat.api.official_base_url', $apiBase), '/');

        $token = (string) ($connectionToken ?: (string) config('inovachat.api.connection_token'));
        if ($token === '' || $number === '' || $body === '') {
            return false;
        }

        $cleanNumber = preg_replace('/\D+/', '', $number) ?: '';
        if ($cleanNumber === '') {
            return false;
        }

        $mode = $this->resolveModeForToken($token); // ✅ decide por token

        $baseToUse = ($mode === 'official') ? $apiBaseOfficial : $apiBase;
        if ($baseToUse === '') {
            return false;
        }

        $timeout      = (int) config('inovachat.http.timeout', 10);
        $connect      = (int) config('inovachat.http.connect_timeout', 5);
        $retries      = (int) config('inovachat.http.retry', 1);
        $retryDelayMs = (int) config('inovachat.http.retry_delay_ms', 200);

        $logFailures = (bool) config('inovachat.logging.log_failures', true);

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
                    return true;
                }

                if ($attempt < $retries) {
                    usleep($retryDelayMs * 1000);
                }
            }

            if ($logFailures) {
                Log::warning('Inovachat text message failed', [
                    'mode'   => $mode,
                    'status' => $response?->status(),
                ]);
            }

            return false;
        } catch (\Throwable $e) {
            if ($logFailures) {
                Log::error('Inovachat text message exception', [
                    'mode'      => $mode,
                    'exception' => $e->getMessage(),
                ]);
            }

            return false;
        }
    }

    /**
     * Decide 'basic'|'official' por token (fonte única: connections.map).
     * Fallback: inovachat.api.message_mode
     */
    private function resolveModeForToken(string $token): string
    {
        $map = config('inovachat.connections.map');
        $map = is_array($map) ? $map : [];

        $mode = $map[$token] ?? null;
        if (is_string($mode)) {
            $mode = strtolower(trim($mode));
            if (in_array($mode, ['basic', 'official'], true)) {
                return $mode;
            }
        }

        $fallback = strtolower((string) config('inovachat.api.message_mode', 'basic'));
        return in_array($fallback, ['basic', 'official'], true) ? $fallback : 'basic';
    }
}
