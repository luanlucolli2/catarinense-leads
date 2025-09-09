<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class FactaApiService
{
    /** API */
    private string $baseUrl;
    private ?string $basicAuth;
    private int $tokenTtl;
    private int $tokenLockTtl;
    private int $tokenLockWait;
    private int $tokenTtlSkew;

    /** HTTP (1ª rodada) */
    private int $httpTimeout;
    private int $httpConnectTimeout;
    private int $httpRetry;
    private int $httpRetryDelayMs;

    /** HTTP (2ª rodada opcional em pool) */
    private bool $httpSecondTry;
    private int $httpSecondTimeout;
    private int $httpSecondConnectTimeout;

    public function __construct()
    {
        $api = (array) config('cltfacta.api', []);
        $http = (array) config('cltfacta.http', []);

        // API
        $this->baseUrl = rtrim((string) ($api['base_url'] ?? ''), '/');
        $this->basicAuth = $api['basic_auth'] ?? null;
        $this->tokenTtl = (int) ($api['token_ttl'] ?? 3300);
        $this->tokenLockTtl = (int) ($api['token_lock_ttl'] ?? 10);
        $this->tokenLockWait = (int) ($api['token_lock_wait'] ?? 5);
        $this->tokenTtlSkew = (int) ($api['token_ttl_skew'] ?? 30);

        // HTTP (1ª)
        $this->httpTimeout = (int) ($http['timeout'] ?? 15);
        $this->httpConnectTimeout = (int) ($http['connect_timeout'] ?? 10);
        $this->httpRetry = (int) ($http['retry'] ?? 1);
        $this->httpRetryDelayMs = (int) ($http['retry_delay_ms'] ?? 200);

        // HTTP (2ª)
        $this->httpSecondTry = (bool) ($http['second_try'] ?? true);
        $this->httpSecondTimeout = (int) ($http['second_timeout'] ?? 10);
        $this->httpSecondConnectTimeout = (int) ($http['second_connect_timeout'] ?? 5);
    }

    /**
     * Obtém token com lock para evitar thundering herd
     */
    public function getToken(): ?string
    {
        $cached = Cache::get('facta_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $lock = Cache::lock('facta_token_lock', $this->tokenLockTtl);
        $lock->block($this->tokenLockWait);

        try {
            $cached = Cache::get('facta_token');
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            if (!$this->basicAuth) {
                throw new \RuntimeException('FACTA_BASIC_AUTH not configured');
            }

            $resp = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->basicAuth,
                'Accept' => 'application/json',
            ])
                ->timeout(20)
                ->connectTimeout(10)
                ->retry(max(2, $this->httpRetry), max(300, $this->httpRetryDelayMs), fn($e) => $e instanceof ConnectionException)

                ->get($this->baseUrl . '/gera-token');

            if (!$resp->ok()) {
                throw new \RuntimeException("FACTA token error: HTTP {$resp->status()}");
            }

            $json = $resp->json();
            if (!is_array($json) || !empty($json['erro'])) {
                throw new \RuntimeException('FACTA token error: ' . ($json['mensagem'] ?? 'Unknown'));
            }

            $token = $json['token'] ?? null;
            if (!$token) {
                throw new \RuntimeException('FACTA token error: token ausente na resposta');
            }

            $ttl = max(30, $this->tokenTtl - $this->tokenTtlSkew);
            Cache::put('facta_token', $token, $ttl);

            return $token;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Consulta unitária (fallback/compat)
     */
    public function autorizaConsulta(string $cpf): array
    {
        $cpf = preg_replace('/\D+/', '', $cpf ?? '');
        if (strlen($cpf) !== 11) {
            return [
                'ok' => false,
                'mensagem' => 'CPF inválido',
                'vinculos' => null,
                'retriable' => false,
                'not_found' => false,
                'http_status' => null,
                'retry_after' => null,
            ];
        }

        $doRequest = function () use ($cpf) {
            $token = $this->getToken();

            return Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ])
                ->timeout($this->httpTimeout)
                ->connectTimeout($this->httpConnectTimeout)
                ->retry($this->httpRetry, $this->httpRetryDelayMs, fn($e) => $e instanceof ConnectionException)
                ->get($this->baseUrl . '/consignado-trabalhador/autoriza-consulta', [
                    'cpf' => $cpf,
                ]);
        };

        try {
            $resp = $doRequest();

            if ($resp->status() === 401) {
                Cache::forget('facta_token');
                $resp = $doRequest();
            }

            return $this->parseAutorizaResponse($resp);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'mensagem' => 'Exceção: ' . $e->getMessage(),
                'vinculos' => null,
                'retriable' => true,
                'not_found' => false,
                'http_status' => null,
                'retry_after' => null,
            ];
        }
    }

    /**
     * Consulta em lote concorrente; retorna [cpf => resultado]
     */
    public function autorizaConsultaLote(array $cpfs): array
    {
        $cpfs = array_values(array_filter(array_map(function ($c) {
            $c = preg_replace('/\D+/', '', (string) $c);
            return strlen($c) === 11 ? $c : null;
        }, $cpfs)));

        if (empty($cpfs)) {
            return [];
        }

        // ✅ PROTEGE a geração do token
        try {
            $token = $this->getToken();
        } catch (\Throwable $e) {
            $msg = 'Falha ao gerar token: ' . $e->getMessage();
            $out = [];
            foreach ($cpfs as $cpf) {
                $out[$cpf] = [
                    'ok' => false,
                    'mensagem' => $msg,
                    'vinculos' => null,
                    'retriable' => true,   // <- importante: deixa o job retriar
                    'not_found' => false,
                    'http_status' => null,
                    'retry_after' => null,
                ];
            }
            return $out;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];
        $url = $this->baseUrl . '/consignado-trabalhador/autoriza-consulta';
        /** @var array<string,HttpResponse> $responses */
        $responses = [];

        // -------- 1ª TENTATIVA (POOL) --------
        try {
            $responses = Http::pool(function (Pool $pool) use ($cpfs, $headers, $url) {
                $reqs = [];
                foreach ($cpfs as $cpf) {
                    $reqs[] = $pool->as($cpf)
                        ->withHeaders($headers)
                        ->timeout($this->httpTimeout)
                        ->connectTimeout($this->httpConnectTimeout)
                        ->retry($this->httpRetry, $this->httpRetryDelayMs, fn($e) => $e instanceof ConnectionException)
                        ->get($url, ['cpf' => $cpf]);
                }
                return $reqs;
            });
        } catch (Throwable $e) {
            // Pool inteiro falhou → devolve retriable (o Job vai retriar)
            $out = [];
            foreach ($cpfs as $cpf) {
                $out[$cpf] = [
                    'ok' => false,
                    'mensagem' => 'Sem resposta (pool falhou)',
                    'vinculos' => null,
                    'retriable' => true,
                    'not_found' => false,
                    'http_status' => null,
                    'retry_after' => null,
                ];
            }
            return $out;
        }

        // -------- 401 → renova token apenas dos necessários --------
        $needRetry401 = [];
        foreach ($responses as $cpf => $resp) {
            if ($resp instanceof HttpResponse && $resp->status() === 401) {
                $needRetry401[] = $cpf;
            }
        }
        if (!empty($needRetry401)) {
            Cache::forget('facta_token');
            $token2 = $this->getToken();
            $headers2 = [
                'Authorization' => 'Bearer ' . $token2,
                'Accept' => 'application/json',
            ];
            try {
                $retryResponses = Http::pool(function (Pool $pool) use ($needRetry401, $headers2, $url) {
                    $reqs = [];
                    foreach ($needRetry401 as $cpf) {
                        $reqs[] = $pool->as($cpf)
                            ->withHeaders($headers2)
                            ->timeout($this->httpTimeout)
                            ->connectTimeout($this->httpConnectTimeout)
                            ->retry($this->httpRetry, $this->httpRetryDelayMs, fn($e) => $e instanceof ConnectionException)
                            ->get($url, ['cpf' => $cpf]);
                    }
                    return $reqs;
                });
                foreach ($retryResponses as $cpf => $resp) {
                    $responses[$cpf] = $resp;
                }
            } catch (Throwable $e) {
                // mantém as 401 (o Job tentará de novo depois)
            }
        }

        // -------- 2ª TENTATIVA (POOL) para MISSING --------
        $missing = [];
        foreach ($cpfs as $cpf) {
            if (!isset($responses[$cpf]) || !($responses[$cpf] instanceof HttpResponse)) {
                $missing[] = $cpf;
            }
        }
        if (!empty($missing) && $this->httpSecondTry) {
            try {
                $retry2 = Http::pool(function (Pool $pool) use ($missing, $headers, $url) {
                    $reqs = [];
                    foreach ($missing as $cpf) {
                        $reqs[] = $pool->as($cpf)
                            ->withHeaders($headers)
                            ->timeout($this->httpSecondTimeout)
                            ->connectTimeout($this->httpSecondConnectTimeout)
                            ->retry($this->httpRetry, $this->httpRetryDelayMs, fn($e) => $e instanceof ConnectionException)
                            ->get($url, ['cpf' => $cpf]);
                    }
                    return $reqs;
                });
                foreach ($retry2 as $cpf => $resp) {
                    $responses[$cpf] = $resp;
                }
            } catch (Throwable $e) {
                // segunda tentativa falhou → deixa missing (Job vai retriar depois)
            }
        }

        // -------- Monta saída --------
        $out = [];
        foreach ($cpfs as $cpf) {
            $resp = $responses[$cpf] ?? null;
            if (!$resp instanceof HttpResponse) {
                $out[$cpf] = [
                    'ok' => false,
                    'mensagem' => 'Sem resposta do serviço',
                    'vinculos' => null,
                    'retriable' => true,
                    'not_found' => false,
                    'http_status' => null,
                    'retry_after' => null,
                ];
                continue;
            }
            $out[$cpf] = $this->parseAutorizaResponse($resp);
        }

        return $out;
    }

    /** --------- Helpers --------- */

    private function parseAutorizaResponse(HttpResponse $resp): array
    {
        $status = $resp->status();
        $retryAfter = $this->getRetryAfterSeconds($resp);

        if (!$resp->ok()) {
            $mensagem = $this->responseMessage($resp);
            $retriable = in_array($status, [401, 408, 429], true) || $status >= 500;
            return [
                'ok' => false,
                'mensagem' => $mensagem ?: "HTTP {$status}",
                'vinculos' => null,
                'retriable' => $retriable,
                'not_found' => false,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        $json = $resp->json();

        // Resposta 200 mas não-JSON → falha temporária
        if (!is_array($json)) {
            return [
                'ok' => false,
                'mensagem' => $this->responseMessage($resp) ?: 'Resposta inválida da FACTA',
                'vinculos' => null,
                'retriable' => true,
                'not_found' => false,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        // --- 👇 NOVO: mensagem com HTML (ex.: "403 Forbidden" em uma página) → retriável
        $msgRaw = (string) ($json['mensagem'] ?? $json['message'] ?? '');
        if ($msgRaw !== '' && $this->looksLikeHtml($msgRaw)) {
            $short = $this->summarizeHtml($msgRaw);
            return [
                'ok' => false,
                'mensagem' => $short,
                'vinculos' => null,
                'retriable' => true,   // tratar como temporário (retriável)
                'not_found' => false,
                'http_status' => $status, // pode ser 200, mesmo com HTML
                'retry_after' => $retryAfter,
            ];
        }
        // --- 👆 NOVO

        if (!empty($json['erro'])) {
            $mensagem = (string) ($json['mensagem'] ?? 'Falha na consulta');
            $isNaoEncontrado = $this->isNaoEncontradoMessage($mensagem);

            return [
                'ok' => false,
                'mensagem' => $mensagem,
                'vinculos' => null,
                'retriable' => !$isNaoEncontrado,
                'not_found' => $isNaoEncontrado,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        $container =
            $json['dados_Trabalhador']
            ?? $json['dados_trabalhador']
            ?? $json['dadosTrabalhador']
            ?? null;

        $dados = is_array($container) ? ($container['dados'] ?? null) : null;

        if (is_array($dados) && count($dados) > 0) {
            return [
                'ok' => true,
                'mensagem' => $json['mensagem'] ?? ($container['mensagem'] ?? 'OK'),
                'vinculos' => $dados,
                'retriable' => false,
                'not_found' => false,
                'http_status' => 200,
                'retry_after' => null,
            ];
        }

        return [
            'ok' => true,
            'mensagem' => $json['mensagem'] ?? ($container['mensagem'] ?? 'Sem vínculos'),
            'vinculos' => [],
            'retriable' => false,
            'not_found' => false,
            'http_status' => 200,
            'retry_after' => null,
        ];
    }

    private function looksLikeHtml(string $s): bool
    {
        $snip = mb_substr($s, 0, 2048, 'UTF-8'); // checa só o início
        if (preg_match('/<!DOCTYPE\s+HTML/i', $snip))
            return true;
        if (preg_match('/<html[\s>]/i', $snip))
            return true;
        if (preg_match('/<head>|<title>|<body>/i', $snip))
            return true;
        if (preg_match('/<\/html>/i', $snip))
            return true;
        return false;
    }

    private function summarizeHtml(string $html): string
    {
        $title = null;
        if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(strip_tags($m[1] ?? ''));
        }
        $lower = mb_strtolower($html, 'UTF-8');
        if (str_contains($lower, '403') && str_contains($lower, 'forbidden')) {
            return ($title !== null ? "{$title}" : 'HTML 403 Forbidden') . ' (tratado como temporário)';
        }
        if (str_contains($lower, '503') && str_contains($lower, 'service unavailable')) {
            return ($title !== null ? "{$title}" : 'HTML 503 Service Unavailable') . ' (tratado como temporário)';
        }
        return ($title !== null ? "HTML: {$title}" : 'Resposta HTML inesperada') . ' (tratado como temporário)';
    }

    private function getRetryAfterSeconds(HttpResponse $resp): ?int
    {
        $h = $resp->header('Retry-After');
        if ($h === null)
            return null;
        $h = trim((string) $h);
        if ($h === '')
            return null;

        if (ctype_digit($h)) {
            return max(0, (int) $h);
        }
        $ts = strtotime($h);
        if ($ts !== false) {
            $delta = $ts - time();
            return $delta > 0 ? $delta : 0;
        }
        return null;
    }

    private function responseMessage(HttpResponse $resp): string
    {
        $status = $resp->status();
        try {
            $json = $resp->json();
            if (is_array($json)) {
                $msg = $json['mensagem'] ?? $json['message'] ?? null;
                if (is_string($msg) && trim($msg) !== '') {
                    if ($this->looksLikeHtml($msg)) {
                        return $this->summarizeHtml($msg);
                    }
                    return trim($msg);
                }
                $encoded = json_encode($json, JSON_UNESCAPED_UNICODE);
                if (is_string($encoded))
                    return $this->truncate(trim($encoded));
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $body = (string) $resp->body();
        if (trim($body) !== '') {
            if ($this->looksLikeHtml($body)) {
                return $this->summarizeHtml($body);
            }
            return $this->truncate(trim($body));
        }

        return "HTTP {$status}";
    }

    private function truncate(string $s, int $max = 500): string
    {
        if (mb_strlen($s, 'UTF-8') <= $max)
            return $s;
        return mb_substr($s, 0, $max, 'UTF-8') . '…';
    }

    private function isNaoEncontradoMessage(string $mensagem): bool
    {
        $msg = trim($mensagem);

        if (strcasecmp($msg, 'CPF não encontrado na base') === 0)
            return true;
        if (strcasecmp($msg, 'CPF nao encontrado na base') === 0)
            return true;

        $norm = $this->normalize($msg);
        if ($norm === 'cpf nao encontrado na base')
            return true;

        return str_contains($norm, 'nao encontrado na base')
            || str_contains($norm, 'não encontrado na base');
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $map = [
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ç' => 'c',
            'Á' => 'a',
            'À' => 'a',
            'Â' => 'a',
            'Ã' => 'a',
            'Ä' => 'a',
            'É' => 'e',
            'È' => 'e',
            'Ê' => 'e',
            'Ë' => 'e',
            'Í' => 'i',
            'Ì' => 'i',
            'Î' => 'i',
            'Ï' => 'i',
            'Ó' => 'o',
            'Ò' => 'o',
            'Ô' => 'o',
            'Õ' => 'o',
            'Ö' => 'o',
            'Ú' => 'u',
            'Ù' => 'u',
            'Û' => 'u',
            'Ü' => 'u',
            'Ç' => 'c',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }
}
