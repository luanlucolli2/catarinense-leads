<?php

namespace App\Services\Inovachat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OfficialTemplateService
{
    /**
     * Cache por processo (worker). Evita recomputar tokens e reduz CPU.
     */
    private static ?array $uraTokensCache = null;

    /**
     * Índice round-robin por processo.
     */
    private static int $rrPos = 0;

    /**
     * Envia template oficial SEM variáveis via API Oficial do Inovachat:
     * POST {inovachat.api.official_base_url}/api/messages/sendOfficial
     *
     * Observação: você afirmou que a API do Inova sempre retorna 200.
     * Logo, o "erro" relevante aqui costuma ser: timeout/DNS/conexão.
     *
     * Retorna apenas o necessário para log do Job (mínimo custo):
     * - token
     * - status
     * - ok_200
     */
    public function sendOfficialTemplateWithoutVariables(
        string $number,
        string $templateName,
        string $language = 'pt_BR',
        ?string $trackingId = null,
    ): array {
        $token = $this->pickUraConnectionToken();

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

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->post($url, $payload);

            // Como você disse que o Inova sempre responde 200,
            // isso aqui é mais um "guard rail" (não custa praticamente nada).
            $status = $response->status();
            if ($status !== 200) {
                Log::warning('URA_INOVA_SEND_NON_200', [
                    'tracking' => $trackingId,
                    'token'    => $token, // sem censura (você pediu)
                    'status'   => $status,
                ]);

                throw new RuntimeException("Inovachat sendOfficial returned status {$status} (expected 200).");
            }

            return [
                'token'  => $token,
                'status' => $status,
                'ok_200' => true,
            ];
        } catch (ConnectionException $e) {
            // Log mínimo e direto (evita logs duplicados no Job)
            Log::warning('URA_INOVA_CONN_FAIL', [
                'tracking' => $trackingId,
                'token'    => $token, // sem censura
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Seleção de token para URA:
     * - tokens vêm de INOVACHAT_URA_CONNECTION_TOKENS (opcional)
     * - fallback para INOVACHAT_CONNECTION_TOKENS
     *
     * Algoritmo:
     * - cache + shuffle 1x por processo
     * - round-robin em memória (baixo custo / ótimo para 1 worker)
     */
    private function pickUraConnectionToken(): string
    {
        $tokens = $this->getUraTokens();

        $count = count($tokens);
        if ($count === 0) {
            throw new RuntimeException('No INOVACHAT_URA_CONNECTION_TOKENS / INOVACHAT_CONNECTION_TOKENS configured.');
        }

        if ($count === 1) {
            return (string) $tokens[0];
        }

        $pos = self::$rrPos;
        self::$rrPos = ($pos + 1) % $count;

        return (string) $tokens[$pos];
    }

    private function getUraTokens(): array
    {
        if (self::$uraTokensCache !== null) {
            return self::$uraTokensCache;
        }

        $tokens = (array) config('inovachat.connections.ura_tokens', []);
        if (empty($tokens)) {
            $tokens = (array) config('inovachat.connections.tokens', []);
        }

        // garante strings não vazias (config já faz trim, mas isso blinda)
        $tokens = array_values(array_filter($tokens, static function ($t) {
            return is_string($t) && $t !== '';
        }));

        // embaralha 1x por processo para não iniciar sempre no mesmo token
        if (count($tokens) > 1) {
            shuffle($tokens);
        }

        self::$uraTokensCache = $tokens;
        self::$rrPos = 0;

        return self::$uraTokensCache;
    }
}
