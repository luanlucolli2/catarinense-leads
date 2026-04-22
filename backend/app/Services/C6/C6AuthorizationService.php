<?php

namespace App\Services\C6;

use App\Services\C6\Exceptions\C6ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class C6AuthorizationService
{
    /**
     * Cache key global para o access_token do C6.
     */
    protected const CACHE_KEY_ACCESS_TOKEN = 'c6bank:access_token';

    protected const AREA_CODES = [
        '11', '12', '13', '14', '15', '16', '17', '18', '19',
        '21', '22', '24',
        '27', '28',
        '31', '32', '33', '34', '35', '37', '38',
        '41', '42', '43', '44', '45', '46',
        '47', '48', '49',
        '51', '53', '54', '55',
        '61',
        '62', '64',
        '63',
        '65', '66',
        '67',
        '68',
        '69',
        '71', '73', '74', '75', '77',
        '79',
        '81', '87',
        '82',
        '83',
        '84',
        '85', '88',
        '86', '89',
        '91', '93', '94',
        '92', '97',
        '95',
        '96',
        '98', '99',
    ];

    protected const FIRST_NAMES = [
        'Ana', 'Mariana', 'Paula', 'Juliana', 'Fernanda', 'Camila', 'Patricia', 'Amanda',
        'Carlos', 'Rafael', 'Lucas', 'Bruno', 'Felipe', 'Rodrigo', 'Diego', 'Marcos',
        'Gabriel', 'Matheus', 'Thiago', 'Eduardo', 'Ricardo', 'Gustavo', 'Vinicius', 'Joao',
    ];

    protected const LAST_NAMES = [
        'Silva', 'Santos', 'Oliveira', 'Souza', 'Costa', 'Pereira', 'Rodrigues', 'Almeida',
        'Nascimento', 'Lima', 'Araujo', 'Fernandes', 'Carvalho', 'Gomes', 'Martins', 'Rocha',
        'Melo', 'Barbosa', 'Moreira', 'Ribeiro',
    ];

    /**
     * Gera link de autorização (empréstimo do trabalhador) para um CPF.
     *
     * Doc: Geração de Link para Autorização de Consulta de Dados – Empréstimo do Trabalhador
     */
    public function generateLink(string $cpf, string $nome, ?string $ddd = null, ?string $numero = null): string
    {
        $result = $this->generateAuthorizationLink(
            cpf: $cpf,
            nome: $nome,
            dataNascimento: null,
            ddd: $ddd,
            numero: $numero
        );

        return $result['link'];
    }

    /**
     * Gera link de autorização de forma síncrona para consumo direto do frontend.
     *
     * Sempre envia ao C6:
     * - nome
     * - cpf
     * - data_nascimento
     * - telefone.numero
     * - telefone.codigo_area
     *
     * Para os campos opcionais ausentes, gera valores aleatórios.
     *
     * @return array{link:string,data_expiracao:string,nome:string}
     */
    public function generateAuthorizationLink(
        string $cpf,
        ?string $nome = null,
        ?string $dataNascimento = null,
        ?string $ddd = null,
        ?string $numero = null
    ): array {
        $baseUrl = rtrim(config('c6bank.base_url'), '/');

        $payload = $this->buildGeneratePayload(
            cpf: $cpf,
            nome: $nome,
            dataNascimento: $dataNascimento,
            ddd: $ddd,
            numero: $numero
        );

        $timeout      = (int) config('c6bank.http.timeout', 10);
        $connect      = (int) config('c6bank.http.connect_timeout', 5);
        $retries      = (int) config('c6bank.http.retry', 1);
        $retryDelayMs = (int) config('c6bank.http.retry_delay_ms', 200);

        $url = $baseUrl . '/marketplace/authorization/generate-liveness';

        try {
            $token = $this->getAccessToken();

            $makeRequest = fn (string $accessToken) => $this->c6Request($accessToken, $timeout, $connect);
            $request  = $makeRequest($token);
            $response = null;

            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                $response = $request->post($url, $payload);

                if ($response->successful()) {
                    $json = $response->json();

                    $link = $json['link'] ?? null;
                    $expiresAt = $json['data_expiracao'] ?? null;

                    if (! is_string($link) || $link === '' || ! is_string($expiresAt) || $expiresAt === '') {
                        throw new C6ApiException(
                            message: 'Resposta inválida do C6 ao gerar link de autorização.',
                            httpStatus: 502,
                            upstreamStatus: $response->status(),
                            upstreamBody: $this->extractResponseBody($response),
                            error: 'c6_generate_invalid_response'
                        );
                    }

                    Log::info('C6 authorization link generated', [
                        'cpf'  => $cpf,
                        'expires_at' => $expiresAt,
                    ]);

                    return [
                        'link' => $link,
                        'data_expiracao' => $expiresAt,
                        'nome' => (string) $payload['nome'],
                    ];
                }

                // Se der 401, força refresh do token e tenta de novo (uma vez)
                if ($response->status() === 401 && $attempt < $retries) {
                    Log::warning('C6 generate-liveness got 401, refreshing token and retrying', [
                        'cpf' => $cpf,
                    ]);

                    $token   = $this->getAccessToken(forceRefresh: true);
                    $request = $makeRequest($token);

                    usleep($retryDelayMs * 1000);
                    continue;
                }

                if ($attempt < $retries) {
                    usleep($retryDelayMs * 1000);
                }
            }

            $status = (int) ($response?->status() ?: 502);

            throw new C6ApiException(
                message: 'Falha ao gerar link de autorização no C6.',
                httpStatus: $this->mapHttpStatus($status),
                upstreamStatus: $status,
                upstreamBody: $this->extractResponseBody($response),
                error: 'c6_generate_failed'
            );
        } catch (ConnectionException $e) {
            $isTimeout = str_contains(strtolower($e->getMessage()), 'timed out');

            throw new C6ApiException(
                message: $isTimeout
                    ? 'Tempo limite excedido ao gerar link de autorização no C6.'
                    : 'Falha de conexão ao gerar link de autorização no C6.',
                httpStatus: $isTimeout ? 504 : 503,
                error: $isTimeout ? 'c6_generate_timeout' : 'c6_generate_connection_error',
                previous: $e
            );
        } catch (C6ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('C6 authorization link error', [
                'cpf'       => $cpf,
                'exception' => $e->getMessage(),
            ]);

            throw new C6ApiException(
                message: 'Erro inesperado ao gerar link de autorização no C6.',
                httpStatus: 502,
                error: 'c6_generate_unexpected_error',
                previous: $e
            );
        }
    }

    /**
     * Consulta o status da autorização do cliente no C6.
     *
     * Doc: "Consulta de status da autorização do cliente"
     *
     * Path/método (manual):
     *   POST /marketplace/authorization/status
     * Body:
     *   { "cpf": "XXXXXXXXXXX" }
     *
     * @return array{
     *     status: string,
     *     raw: array|null
     * }
     */
    public function checkAuthorizationStatus(string $cpf): array
    {
        $baseUrl = rtrim(config('c6bank.base_url'), '/');

        // 1) Token (cacheado)
        $token = $this->getAccessToken();

        $url = $baseUrl . '/marketplace/authorization/status';

        $accept = config(
            'c6bank.headers.authorization_status_accept',
            'application/vnd.c6bank_authorization_status_v1+json'
        );

        $timeout      = (int) config('c6bank.http.timeout', 10);
        $connect      = (int) config('c6bank.http.connect_timeout', 5);
        $retries      = (int) config('c6bank.http.retry', 1);
        $retryDelayMs = (int) config('c6bank.http.retry_delay_ms', 200);

        try {
            $makeRequest = function (string $token) use ($accept, $timeout, $connect) {
                return Http::withHeaders([
                    'Accept'        => $accept,
                    'Content-Type'  => 'application/json',
                    'Authorization' => $token,
                ])
                    ->timeout($timeout)
                    ->connectTimeout($connect);
            };

            $request  = $makeRequest($token);
            $response = null;

            for ($attempt = 0; $attempt <= $retries; $attempt++) {
                $response = $request->post($url, [
                    'cpf' => $cpf,
                ]);

                if ($response->successful()) {
                    $json = $response->json() ?? [];

                    // Manual: "status": "AGUARDANDO_AUTORIZACAO/AUTORIZADO/NAO_AUTORIZADO"
                    $remoteStatus = strtoupper((string) ($json['status'] ?? 'PENDING'));

                    Log::info('C6 authorization status checked', [
                        'cpf'    => $cpf,
                        'status' => $remoteStatus,
                    ]);

                    return [
                        'status' => $remoteStatus,
                        'raw'    => $json,
                    ];
                }

                // 401 => refresh token e tenta de novo
                if ($response->status() === 401 && $attempt < $retries) {
                    Log::warning('C6 authorization status got 401, refreshing token and retrying', [
                        'cpf' => $cpf,
                    ]);

                    $token   = $this->getAccessToken(forceRefresh: true);
                    $request = $makeRequest($token);

                    usleep($retryDelayMs * 1000);
                    continue;
                }

                if ($attempt < $retries) {
                    usleep($retryDelayMs * 1000);
                }
            }

            throw new \RuntimeException(
                'C6 authorization status failed: HTTP ' . $response?->status()
            );
        } catch (\Throwable $e) {
            Log::error('C6 authorization status error', [
                'cpf'       => $cpf,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Obtém access_token do C6 via /auth/token.
     *
     * - Usa cache (Redis) com TTL configurável.
     * - Se $forceRefresh = true, ignora cache e atualiza token.
     */
    protected function getAccessToken(bool $forceRefresh = false): string
    {
        $cacheKey = self::CACHE_KEY_ACCESS_TOKEN;

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $baseUrl = rtrim(config('c6bank.base_url'), '/');

        $username = config('c6bank.auth.username');
        $password = config('c6bank.auth.password');

        if (! $username || ! $password) {
            throw new C6ApiException(
                message: 'Credenciais do C6 não configuradas.',
                httpStatus: 500,
                error: 'c6_auth_not_configured'
            );
        }

        $timeout = (int) config('c6bank.http.timeout', 10);
        $connect = (int) config('c6bank.http.connect_timeout', 5);

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->connectTimeout($connect)
                ->post($baseUrl . '/auth/token', [
                    'username' => $username,
                    'password' => $password,
                ]);
        } catch (ConnectionException $e) {
            $isTimeout = str_contains(strtolower($e->getMessage()), 'timed out');

            throw new C6ApiException(
                message: $isTimeout
                    ? 'Tempo limite excedido ao autenticar no C6.'
                    : 'Falha de conexão ao autenticar no C6.',
                httpStatus: $isTimeout ? 504 : 503,
                error: $isTimeout ? 'c6_auth_timeout' : 'c6_auth_connection_error',
                previous: $e
            );
        }

        if (! $response->successful()) {
            // Em caso de erro, limpa cache para evitar token podre
            Cache::forget($cacheKey);

            $status = (int) $response->status();

            throw new C6ApiException(
                message: 'Falha ao autenticar no C6.',
                httpStatus: $this->mapHttpStatus($status),
                upstreamStatus: $status,
                upstreamBody: $this->extractResponseBody($response),
                error: 'c6_auth_failed'
            );
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            Cache::forget($cacheKey);

            throw new C6ApiException(
                message: 'Resposta inválida da autenticação do C6.',
                httpStatus: 502,
                upstreamStatus: (int) $response->status(),
                upstreamBody: $this->extractResponseBody($response),
                error: 'c6_auth_invalid_response'
            );
        }

        // TTL do token usa a resposta do C6 quando disponível.
        $ttlSeconds = (int) ($response->json('expires_in_seconds') ?: config('c6bank.token.ttl_seconds', 1199));
        $skew       = (int) config('c6bank.token.skew', 60);

        // Evita expirar exatamente junto com o token real
        $cacheTtl = max(30, $ttlSeconds - max(0, $skew));

        Cache::put($cacheKey, $token, $cacheTtl);

        Log::info('C6 access_token refreshed and cached', [
            'ttl_seconds' => $cacheTtl,
        ]);

        return $token;
    }

    /**
     * Gera uma data de nascimento aleatória entre 20 e 70 anos atrás.
     */
    protected function fakeBirthDate(int $minAge = 20, int $maxAge = 70): string
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

    protected function fakeName(): string
    {
        $first = self::FIRST_NAMES[array_rand(self::FIRST_NAMES)];
        $last1 = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
        $last2 = self::LAST_NAMES[array_rand(self::LAST_NAMES)];

        return trim("{$first} {$last1} {$last2}");
    }

    protected function fakeAreaCode(): string
    {
        return self::AREA_CODES[array_rand(self::AREA_CODES)];
    }

    protected function fakePhoneNumber(): string
    {
        // Celular BR sem DDD: 9 dígitos iniciando com 9.
        return '9' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    protected function onlyDigits(?string $input): string
    {
        return preg_replace('/\D+/', '', (string) $input) ?: '';
    }

    protected function sanitizeName(?string $input): string
    {
        $name = trim((string) $input);
        $name = preg_replace('/\s+/', ' ', $name) ?: '';

        return $name;
    }

    /**
     * @return array{
     *   nome:string,
     *   cpf:string,
     *   data_nascimento:string,
     *   telefone:array{numero:string,codigo_area:string}
     * }
     */
    protected function buildGeneratePayload(
        string $cpf,
        ?string $nome = null,
        ?string $dataNascimento = null,
        ?string $ddd = null,
        ?string $numero = null
    ): array {
        $name = $this->sanitizeName($nome);
        if ($name === '') {
            $name = $this->fakeName();
        }

        $birthDate = trim((string) $dataNascimento);
        if ($birthDate === '') {
            $birthDate = $this->fakeBirthDate();
        }

        $areaCode = $this->onlyDigits($ddd);
        if (strlen($areaCode) !== 2) {
            $areaCode = $this->fakeAreaCode();
        }

        $phoneNumber = $this->onlyDigits($numero);
        if (! in_array(strlen($phoneNumber), [8, 9], true)) {
            $phoneNumber = $this->fakePhoneNumber();
        }

        return [
            'nome' => $name,
            'cpf' => $cpf,
            'data_nascimento' => $birthDate,
            'telefone' => [
                'numero' => $phoneNumber,
                'codigo_area' => $areaCode,
            ],
        ];
    }

    protected function c6Request(string $token, int $timeout, int $connectTimeout)
    {
        $accept = config(
            'c6bank.headers.authorization_generate_accept',
            'application/vnd.c6bank_authorization_generate_liveness_v1+json'
        );

        return Http::withHeaders([
            'Accept' => $accept,
            'Content-Type' => 'application/json',
            'Authorization' => $token, // token puro, sem "Bearer"
        ])
            ->timeout($timeout)
            ->connectTimeout($connectTimeout);
    }

    protected function extractResponseBody(?HttpResponse $response): array|string|null
    {
        if (! $response) {
            return null;
        }

        $json = $response->json();
        if (is_array($json)) {
            return $json;
        }

        $body = trim((string) $response->body());
        return $body !== '' ? $body : null;
    }

    protected function mapHttpStatus(int $status): int
    {
        return ($status >= 400 && $status <= 599) ? $status : 502;
    }
}
