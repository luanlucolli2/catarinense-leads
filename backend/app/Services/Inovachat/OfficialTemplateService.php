<?php

namespace App\Services\Inovachat;

use Illuminate\Http\Client\ConnectionException;
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
     * Regra: sucesso APENAS com HTTP 200.
     * Qualquer outra resposta => exception (para o Job retry até 3x e falhar).
     *
     * Retorna dados úteis para log:
     * - token (sem censura)
     * - status
     * - ok_200
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

        $cleanNumber = preg_replace('/\D+/', '', $number) ?: null;
        if (! $cleanNumber) {
            throw new RuntimeException('Invalid number: must contain only digits after normalization.');
        }

        $payload = [
            'number'   => $cleanNumber,
            'name'     => $templateName,
            'language' => $language,
        ];

        Log::info('INOVA_SEND_OFFICIAL_START', [
            'tracking' => $trackingId,
            'token'    => $token, // sem censura (como você pediu)
            'url'      => $url,
            'number'   => $cleanNumber,
            'template' => $templateName,
            'lang'     => $language,
        ]);

        try {
            // ✅ Uma tentativa = uma requisição HTTP (sem retry interno)
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->post($url, $payload);

            $status = $response->status();

            if ($status !== 200) {
                Log::warning('INOVA_SEND_OFFICIAL_NOT_200', [
                    'tracking' => $trackingId,
                    'token'    => $token, // sem censura
                    'status'   => $status,
                    'body'     => $this->truncate($response->body(), 800),
                ]);

                throw new RuntimeException("Inovachat sendOfficial returned status {$status} (expected 200).");
            }

            Log::info('INOVA_SEND_OFFICIAL_OK', [
                'tracking' => $trackingId,
                'token'    => $token, // sem censura
                'status'   => $status,
            ]);

            return [
                'token'  => $token,
                'status' => $status,
                'ok_200' => true,
            ];
        } catch (ConnectionException $e) {
            // Timeout/DNS/conexão: deixa o Job cuidar do retry (até 3x)
            Log::warning('INOVA_SEND_OFFICIAL_CONN_ERROR', [
                'tracking' => $trackingId,
                'token'    => $token, // sem censura
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

    private function truncate(?string $s, int $max): string
    {
        $s = (string) ($s ?? '');
        if (strlen($s) <= $max) {
            return $s;
        }
        return substr($s, 0, $max) . '...';
    }
}
