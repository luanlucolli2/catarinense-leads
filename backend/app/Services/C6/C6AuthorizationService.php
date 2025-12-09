<?php

namespace App\Services\C6;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class C6AuthorizationService
{
    /**
     * Gera link de autorização (empréstimo do trabalhador) para um CPF.
     *
     * Doc: Geração de Link para Autorização de Consulta de Dados – Empréstimo do Trabalhador:contentReference[oaicite:0]{index=0}
     *
     * Campos enviados:
     * - nome (string) – obrigatório
     * - cpf (string) – obrigatório
     * - data_nascimento (YYYY-MM-DD) – obrigatório
     * - telefone (objeto opcional) { numero, codigo_area }
     *
     * @param string      $cpf   Somente dígitos.
     * @param string      $nome  Nome ou primeiro nome do cliente.
     * @param string|null $ddd   DDD do celular (2 dígitos), opcional.
     * @param string|null $numero Número do celular sem DDD, opcional.
     *
     * @return string URL de autorização retornada pelo C6.
     */
    public function generateLink(string $cpf, string $nome, ?string $ddd = null, ?string $numero = null): string
    {
        $baseUrl = rtrim(config('c6bank.base_url'), '/');
        $token   = $this->getAccessToken();

        $payload = [
            'nome'            => $nome,
            'cpf'             => $cpf,
            'data_nascimento' => $this->fakeBirthDate(), // > 19 anos
        ];

        if ($ddd && $numero) {
            $payload['telefone'] = [
                'numero'      => $numero,
                'codigo_area' => $ddd,
            ];
        }

        $accept = config(
            'c6bank.headers.authorization_generate_accept',
            'application/vnd.c6bank_authorization_generate_liveness_v1+json'
        );

        $timeout      = (int) config('c6bank.http.timeout', 10);
        $connect      = (int) config('c6bank.http.connect_timeout', 5);
        $retries      = (int) config('c6bank.http.retry', 1);
        $retryDelayMs = (int) config('c6bank.http.retry_delay_ms', 200);

        $url = $baseUrl . '/marketplace/authorization/generate-liveness';

        try {
            $request = Http::withHeaders([
                'Accept'        => $accept,
                'Content-Type'  => 'application/json',
                'Authorization' => $token, // doc: token puro, sem "Bearer":contentReference[oaicite:1]{index=1}
            ])
                ->timeout($timeout)
                ->connectTimeout($connect);

            $response = null;

            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                $response = $request->post($url, $payload);

                if ($response->successful()) {
                    $json = $response->json();

                    // Exemplo de response (manual):
                    // { "link": "http://web.c6consig.com.br/EPKwPMmSkcyvkIPN", "data_expiracao": "yyyy-MM-dd" }:contentReference[oaicite:2]{index=2}
                    $link = $json['link'] ?? null;

                    if (! is_string($link) || $link === '') {
                        throw new \RuntimeException('C6 authorization link missing in response');
                    }

                    Log::info('C6 authorization link generated', [
                        'cpf'  => $cpf,
                        'link' => $link,
                    ]);

                    return $link;
                }

                if ($attempt < $retries) {
                    usleep($retryDelayMs * 1000);
                }
            }

            throw new \RuntimeException('C6 generate-liveness failed: HTTP ' . $response?->status());
        } catch (\Throwable $e) {
            Log::error('C6 authorization link error', [
                'cpf'       => $cpf,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Obtém access_token do C6 via /auth/token (x-www-form-urlencoded).:contentReference[oaicite:3]{index=3}
     */
    protected function getAccessToken(): string
    {
        $baseUrl = rtrim(config('c6bank.base_url'), '/');

        $username = config('c6bank.auth.username');
        $password = config('c6bank.auth.password');

        if (! $username || ! $password) {
            throw new \RuntimeException('C6 credentials not configured');
        }

        $timeout = (int) config('c6bank.http.timeout', 10);
        $connect = (int) config('c6bank.http.connect_timeout', 5);

        $response = Http::asForm()
            ->timeout($timeout)
            ->connectTimeout($connect)
            ->post($baseUrl . '/auth/token', [
                'username' => $username,
                'password' => $password,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('C6 auth failed: HTTP ' . $response->status());
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('C6 auth response missing access_token');
        }

        return $token;
    }

    /**
     * Gera uma data de nascimento aleatória entre 20 e 60 anos atrás.
     */
    protected function fakeBirthDate(int $minAge = 20, int $maxAge = 60): string
    {
        $now  = Carbon::now();
        $age  = random_int($minAge, $maxAge);
        $days = random_int(0, 364);

        return $now
            ->copy()
            ->subYears($age)
            ->subDays($days)
            ->format('Y-m-d');
    }
}
