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
     * A conexão (token) é escolhida aleatoriamente do allowlist INOVACHAT_CONNECTION_TOKENS.
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

        // Preferir a base oficial. Se estiver vazia, cair na base padrão.
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

        Log::info('Inovachat official template send start', [
            'tracking_id' => $trackingId,
            'url'         => $url,
            'token_hint'  => $this->maskToken($token),
            'payload'     => $payload,
        ]);

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->retry($retries, $retryDelayMs, throw: false)
                ->post($url, $payload);

            $response->throw();

            return (array) $response->json();
        } catch (ConnectionException $e) {
            Log::warning('Inovachat official template connection error', [
                'tracking_id' => $trackingId,
                'url'         => $url,
                'error'       => $e->getMessage(),
            ]);
            throw $e;
        } catch (RequestException $e) {
            Log::error('Inovachat official template request failed', [
                'tracking_id' => $trackingId,
                'url'         => $url,
                'status'      => optional($e->response)->status(),
                'body'        => optional($e->response)->body(),
                'error'       => $e->getMessage(),
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

    private function maskToken(string $token): string
    {
        $len = strlen($token);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($token, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($token, -4);
    }
}
