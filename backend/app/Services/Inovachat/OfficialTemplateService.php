<?php

namespace App\Services\Inovachat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OfficialTemplateService
{
    /**
     * Envia template oficial SEM variáveis via API Oficial do Inovachat:
     * POST {inovachat.api.official_base_url}/api/messages/sendOfficial
     *
     * Retorna dados úteis para log/debug:
     * - token (sem censura)
     * - status
     * - ok_200 (status === 200)
     * - ok (2xx)
     * - json/body
     */
    public function sendOfficialTemplateWithoutVariables(
        string $number,
        string $templateName,
        string $language = 'pt_BR',
        ?string $trackingId = null,
    ): array {
        $token = $this->pickRandomConnectionToken();

        $apiBase = rtrim((string) config('inovachat.api.base_url', ''), '/');
        $apiBaseOfficial = rtrim((string) config('inovachat.api.official_base_url', $apiBase), '/');

        $baseToUse = $apiBaseOfficial !== '' ? $apiBaseOfficial : $apiBase;

        if ($baseToUse === '' || ! preg_match('#^https?://#i', $baseToUse)) {
            throw new RuntimeException("Invalid Inovachat base URL configured: '{$baseToUse}'. Check INOVACHAT_API_BASE / INOVACHAT_API_BASE_OFFICIAL.");
        }

        $url = $baseToUse . '/api/messages/sendOfficial';

        $timeout = (int) config('inovachat.http.timeout', 10);
        $connectTimeout = (int) config('inovachat.http.connect_timeout', 5);
        $retries = (int) config('inovachat.http.retry', 1);
        $retryDelayMs = (int) config('inovachat.http.retry_delay_ms', 200);

        $cleanNumber = preg_replace('/\D+/', '', $number) ?: null;
        if (! $cleanNumber) {
            throw new RuntimeException('Invalid number: must contain only digits after normalization.');
        }

        $payload = [
            'number'   => $cleanNumber,
            'name'     => $templateName,
            'language' => $language,
        ];

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->retry($retries, $retryDelayMs, throw: false)
                ->post($url, $payload);

            $status = $response->status();
            $ok = $response->successful();
            $ok200 = ($status === 200);

            // Se não for sucesso, log simples + body e joga exception
            if (! $ok) {
                Log::warning('INOVA_SEND_OFFICIAL_FAIL', [
                    'tracking' => $trackingId,
                    'status'   => $status,
                    'token'    => $token, // ✅ SEM CENSURA
                    'body'     => $response->body(),
                ]);

                $response->throw(); // dispara RequestException
            }

            $json = $response->json();

            return [
                'token'  => $token,   // ✅ SEM CENSURA
                'status' => $status,
                'ok_200' => $ok200,
                'ok'     => $ok,
                'json'   => $json,
            ];
        } catch (ConnectionException $e) {
            Log::warning('INOVA_SEND_OFFICIAL_CONN_ERROR', [
                'tracking' => $trackingId,
                'token'    => $token, // ✅ SEM CENSURA
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        } catch (RequestException $e) {
            Log::warning('INOVA_SEND_OFFICIAL_HTTP_ERROR', [
                'tracking' => $trackingId,
                'token'    => $token, // ✅ SEM CENSURA
                'status'   => optional($e->response)->status(),
                'body'     => optional($e->response)->body(),
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function pickRandomConnectionToken(): string
    {
        $tokens = (array) config('inovachat.connections.tokens', []);
        $tokens = array_values(array_filter(array_map('trim', $tokens)));

        if (empty($tokens)) {
            throw new RuntimeException('No INOVACHAT_CONNECTION_TOKENS configured (inovachat.connections.tokens is empty).');
        }

        return (string) Arr::random($tokens);
    }
}
