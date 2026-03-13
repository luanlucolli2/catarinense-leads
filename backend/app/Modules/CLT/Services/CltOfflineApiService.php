<?php

namespace App\Modules\CLT\Services;

use App\Modules\CLT\Support\CltLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class CltOfflineApiService
{
    /** API */
    private string $baseUrl;
    private ?string $basicAuth;
    private int $tokenTtl;
    private int $tokenLockTtl;
    private int $tokenLockWait;
    private int $tokenTtlSkew;

    /** HTTP */
    private int $httpTimeout;
    private int $httpConnectTimeout;
    private int $httpRetry;
    private int $httpRetryDelayMs;

    /** Intervalo mínimo entre requests (ms) exigido pela doc */
    private int $minIntervalMs;

    /** Pacing global (entre chamadas) */
    private float $lastCallAt = 0.0; // em memória, por processo
    private string $rateKey = 'clt_off_last_call_at'; // opcional: cross-process via cache

    public function __construct()
    {
        $api = (array) config('cltfacta.clt_off.api', []);
        $http = (array) config('cltfacta.clt_off.http', []);

        $this->baseUrl = rtrim((string) ($api['base_url'] ?? ''), '/');
        $this->basicAuth = $api['basic_auth'] ?? null;
        $this->tokenTtl = (int) ($api['token_ttl'] ?? 3600);
        $this->tokenLockTtl = (int) ($api['token_lock_ttl'] ?? 10);
        $this->tokenLockWait = (int) ($api['token_lock_wait'] ?? 5);
        $this->tokenTtlSkew = (int) ($api['token_ttl_skew'] ?? 30);

        $this->httpTimeout = (int) ($http['timeout'] ?? 10);
        $this->httpConnectTimeout = (int) ($http['connect_timeout'] ?? 5);
        $this->httpRetry = (int) ($http['retry'] ?? 1);
        $this->httpRetryDelayMs = (int) ($http['retry_delay_ms'] ?? 200);

        $this->minIntervalMs = (int) ($http['min_interval_ms'] ?? 3200);
    }

    /** GET {BASE}/gera-token */
    public function getToken(): ?string
    {
        $cached = Cache::get('clt_off_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $lock = Cache::lock('clt_off_token_lock', $this->tokenLockTtl);
        $lock->block($this->tokenLockWait);

        try {
            $cached = Cache::get('clt_off_token');
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            if (!$this->basicAuth) {
                throw new \RuntimeException('CLT-OFF token error: credencial BASIC ausente (CLT_OFF_BASIC_AUTH)');
            }

            $maxAttempts = max(1, $this->httpRetry + 1);
            $resp = null;
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                try {
                    $resp = Http::withHeaders([
                        'Authorization' => 'Basic ' . $this->basicAuth,
                        'Accept' => 'application/json',
                    ])
                        ->timeout(max(1, $this->httpTimeout))
                        ->connectTimeout(max(1, $this->httpConnectTimeout))
                        ->get($this->baseUrl . '/gera-token');
                } catch (Throwable $e) {
                    if (
                        $attempt < $maxAttempts
                        && ($e instanceof ConnectionException || $this->isTimeoutException($e))
                    ) {
                        $this->sleepTransientPauseMs();
                        continue;
                    }

                    throw $e;
                }

                $retryAfter = $this->getRetryAfterSeconds($resp);
                if ($this->isTransientHttpStatus($resp->status()) && $attempt < $maxAttempts) {
                    $this->sleepTransientPauseMs($retryAfter);
                    continue;
                }

                break;
            }

            if (!$resp instanceof HttpResponse) {
                throw new \RuntimeException('CLT-OFF token error: sem resposta do /gera-token');
            }

            if ($resp->status() === 403) {
                $this->logForbidden($resp, null);
            }

            if (!$resp->ok()) {
                $msg = $this->responseMessage($resp);
                throw new \RuntimeException("CLT-OFF token error: {$msg}");
            }

            $json = $resp->json();
            if (!is_array($json) || !empty($json['erro'])) {
                $msg = trim((string) ($json['mensagem'] ?? $json['message'] ?? 'Erro no /gera-token'));
                throw new \RuntimeException("CLT-OFF token error: {$msg}");
            }

            $token = $json['token'] ?? null;
            if (!is_string($token) || $token === '') {
                $msg = trim((string) ($json['mensagem'] ?? $json['message'] ?? 'token ausente na resposta'));
                throw new \RuntimeException("CLT-OFF token error: {$msg}");
            }

            $ttl = max(30, $this->tokenTtl - $this->tokenTtlSkew);
            Cache::put('clt_off_token', $token, $ttl);

            return $token;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Consulta sequencial com intervalo mínimo por request.
     * Endpoint: {BASE}/clt/base-offline?cpf=...
     * Retorna [cpf => resultado canônico].
     */
    public function autorizaConsultaLote(array $cpfs): array
    {
        $normalizedCpfs = [];
        foreach ($cpfs as $c) {
            $digits = preg_replace('/\D+/', '', (string) $c);
            if (strlen($digits) === 11) {
                $normalizedCpfs[] = $digits;
            }
        }
        $cpfs = $normalizedCpfs;

        if (empty($cpfs)) {
            return [];
        }

        $retryAfterDefault = $this->minIntervalMs / 1000;

        try {
            $token = $this->getToken();
        } catch (Throwable $e) {
            $msg = 'Falha ao gerar token: ' . $e->getMessage();
            $permanent = $this->isFatalAuthError($e->getMessage());
            $retryAfter = $permanent ? null : $retryAfterDefault;
            $out = [];
            foreach ($cpfs as $cpf) {
                $out[$cpf] = [
                    'ok' => false,
                    'mensagem' => $msg,
                    'vinculos' => null,
                    'retriable' => !$permanent,
                    'not_found' => false,
                    'http_status' => null,
                    'retry_after' => $retryAfter,
                ];
            }
            return $out;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];
        
        // CORREÇÃO: Removemos o /debug pois o manual v2.0 especifica /clt/base-offline
        $url = $this->baseUrl . '/clt/base-offline';

        $out = [];

        foreach ($cpfs as $cpf) {
            $maxAttempts = max(1, $this->httpRetry + 1);
            $resp = null;
            $lastError = null;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $this->respectMinInterval();
                try {
                    $resp = Http::withHeaders($headers)
                        ->timeout($this->httpTimeout)
                        ->connectTimeout($this->httpConnectTimeout)
                        ->get($url, ['cpf' => $cpf]);
                } catch (Throwable $e) {
                    $lastError = $e;
                    if (
                        $attempt < $maxAttempts
                        && ($e instanceof ConnectionException || $this->isTimeoutException($e))
                    ) {
                        $this->sleepTransientPauseMs();
                        continue;
                    }
                    break;
                } finally {
                    // Atualiza o timer APÓS cada tentativa efetiva.
                    $this->markRequestDone();
                }

                if ($resp->status() === 403) {
                    $this->logForbidden($resp, $cpf);
                }

                if ($this->isTransientHttpStatus($resp->status()) && $attempt < $maxAttempts) {
                    $this->sleepTransientPauseMs($this->getRetryAfterSeconds($resp));
                    continue;
                }

                break;
            }

            if (!$resp instanceof HttpResponse) {
                $msg = 'Sem resposta do serviço OFF';
                if ($lastError instanceof Throwable) {
                    $msg .= ': ' . $lastError->getMessage();
                }
                $out[$cpf] = [
                    'ok' => false,
                    'mensagem' => $msg,
                    'vinculos' => null,
                    'retriable' => true,
                    'not_found' => false,
                    'http_status' => null,
                    'retry_after' => $retryAfterDefault,
                ];
                continue;
            }

            $out[$cpf] = $this->parseOffResponse($resp);
        }

        return $out;
    }

    /** ------- Helpers de pacing ------- */
    private function respectMinInterval(): void
    {
        $minSecs = max(0, $this->minIntervalMs) / 1000.0;

        // obtém último instante global (cache) e local (processo)
        $lastGlobal = (float) (Cache::get($this->rateKey) ?? 0.0);
        $effectiveLast = max($lastGlobal, $this->lastCallAt);

        $now = microtime(true);
        $sleepUs = (int) max(0, ($effectiveLast + $minSecs - $now) * 1_000_000);
        if ($sleepUs > 0) {
            usleep($sleepUs);
        }
    }

    private function markRequestDone(): void
    {
        $t = microtime(true);
        $this->lastCallAt = $t;
        // TTL curto só para amortecer processos paralelos; com 1 worker já resolve.
        Cache::put($this->rateKey, $t, 300);
    }

    private function isTransientHttpStatus(int $status): bool
    {
        return in_array($status, [408, 429], true) || $status >= 500;
    }

    private function sleepTransientPauseMs(?int $retryAfterSeconds = null): void
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            sleep($retryAfterSeconds);
            return;
        }

        $pauseMs = max(3000, $this->httpRetryDelayMs);
        usleep($pauseMs * 1000);
    }

    private function isTimeoutException(Throwable $e): bool
    {
        $current = $e;
        while ($current !== null) {
            $msg = strtolower($current->getMessage());
            if (
                str_contains($msg, 'timed out')
                || str_contains($msg, 'timeout')
                || str_contains($msg, 'curl error 28')
            ) {
                return true;
            }
            $current = $current->getPrevious();
        }

        return false;
    }

    /** ------- Helpers de parsing e logging ------- */

    private function parseOffResponse(HttpResponse $resp): array
    {
        $status = $resp->status();
        $retryAfter = $this->getRetryAfterSeconds($resp);

        if (!$resp->ok()) {
            $mensagem = $this->responseMessage($resp);
            $looksHtml = false;
            try {
                $body = (string) $resp->body();
                $looksHtml = ($body !== '') && $this->looksLikeHtml($body);
            } catch (\Throwable) {
            }

            $retriable = $status === 401
                || $this->isTransientHttpStatus($status)
                || $looksHtml
                || ($status === 403 && $this->isRetryableForbiddenMessage($mensagem));

            if ($status === 404) {
                return [
                    'ok' => false,
                    'mensagem' => $mensagem ?: "HTTP 404",
                    'vinculos' => null,
                    'retriable' => false,
                    'not_found' => true,
                    'http_status' => 404,
                    'retry_after' => null,
                ];
            }

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

        if (!is_array($json)) {
            return [
                'ok' => false,
                'mensagem' => $this->responseMessage($resp) ?: 'Resposta inválida do CLT-OFF',
                'vinculos' => null,
                'retriable' => true,
                'not_found' => false,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        if (!empty($json['erro'])) {
            $mensagem = (string) ($json['mensagem'] ?? 'Falha na consulta');

            if ($this->isNotFoundMessage($mensagem)) {
                return [
                    'ok' => false,
                    'mensagem' => $mensagem,
                    'vinculos' => [],
                    'retriable' => false,
                    'not_found' => true,
                    'http_status' => 200,
                    'retry_after' => null,
                ];
            }

            if ($this->isValidationMessage($mensagem)) {
                return [
                    'ok' => false,
                    'mensagem' => $mensagem,
                    'vinculos' => null,
                    'retriable' => false,
                    'not_found' => false,
                    'http_status' => 200,
                    'retry_after' => null,
                ];
            }

            if ($this->isRetryLaterMessage($mensagem)) {
                // Manual diz 3 segundos, então forçamos um retryAfter seguro
                $retryAfter = $retryAfter ?? 4; 
                return [
                    'ok' => false,
                    'mensagem' => $mensagem,
                    'vinculos' => null,
                    'retriable' => true,
                    'not_found' => false,
                    'http_status' => 200,
                    'retry_after' => $retryAfter,
                ];
            }

            return [
                'ok' => false,
                'mensagem' => $mensagem,
                'vinculos' => null,
                'retriable' => true,
                'not_found' => false,
                'http_status' => 200,
                'retry_after' => $retryAfter,
            ];
        }

        $dados = $json['dados'] ?? null;
        if (is_array($dados) && count($dados) > 0) {
            return [
                'ok' => true,
                'mensagem' => (string) ($json['mensagem'] ?? 'OK'),
                'vinculos' => $dados,
                'retriable' => false,
                'not_found' => false,
                'http_status' => 200,
                'retry_after' => null,
            ];
        }

        return [
            'ok' => true,
            'mensagem' => (string) ($json['mensagem'] ?? 'Sem vínculos'),
            'vinculos' => [],
            'retriable' => false,
            'not_found' => false,
            'http_status' => 200,
            'retry_after' => null,
        ];
    }

    private function logForbidden(HttpResponse $resp, ?string $cpf = null): void
    {
        try {
            $all = $resp->headers();
            $safe = [];
            foreach ($all as $k => $vals) {
                $key = (string) $k;
                if (stripos($key, 'authorization') === 0 || stripos($key, 'cookie') === 0 || stripos($key, 'set-cookie') === 0) {
                    $safe[$key] = ['REDACTED'];
                } else {
                    $safe[$key] = array_map('strval', (array) $vals);
                }
            }
            $body = (string) $resp->body();
            $snippet = $this->truncate($body, 4000);

            CltLog::warning(
                '[CLT-OFF] 403 Forbidden'
                . ($cpf ? " (cpf={$cpf})" : '')
                . ' — headers=' . json_encode($safe, JSON_UNESCAPED_UNICODE)
                . ' body_snippet=' . $snippet
            );
        } catch (\Throwable $e) {
            CltLog::warning('[CLT-OFF] Falha ao logar 403: ' . $e->getMessage());
        }
    }

    private function getRetryAfterSeconds(HttpResponse $resp): ?int
    {
        $h = $resp->header('Retry-After');
        if ($h === null) return null;
        $h = trim((string) $h);
        if ($h === '') return null;
        if (ctype_digit($h)) return max(0, (int) $h);
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
                if (is_string($encoded)) {
                    return $this->truncate(trim($encoded));
                }
            }
        } catch (\Throwable) {
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

    private function looksLikeHtml(string $s): bool
    {
        $snip = mb_substr($s, 0, 2048, 'UTF-8');
        if (preg_match('/<!DOCTYPE\s+HTML/i', $snip)) return true;
        if (preg_match('/<html[\s>]/i', $snip)) return true;
        if (preg_match('/<head>|<title>|<body>/i', $snip)) return true;
        if (preg_match('/<\/html>/i', $snip)) return true;
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
            return ($title !== null ? "{$title}" : 'HTML 403 Forbidden') . ' (temporário)';
        }
        if (str_contains($lower, '503') && str_contains($lower, 'service unavailable')) {
            return ($title !== null ? "{$title}" : 'HTML 503 Service Unavailable') . ' (temporário)';
        }
        return ($title !== null ? "HTML: {$title}" : 'Resposta HTML inesperada') . ' (temporário)';
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $map = [
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    private function truncate(string $s, int $max = 500): string
    {
        if (mb_strlen($s, 'UTF-8') <= $max) return $s;
        return mb_substr($s, 0, $max, 'UTF-8') . '…';
    }

    private function isRetryLaterMessage(string $msg): bool
    {
        $n = $this->normalize($msg);
        return str_contains($n, 'indisponivel') || str_contains($n, 'volte em 3 segundos');
    }

    private function isNotFoundMessage(string $msg): bool
    {
        $n = $this->normalize($msg);
        return str_contains($n, 'nenhum dado encontrado') || str_contains($n, 'nenhum dados encontrado')
            || str_contains($n, 'nao encontrado') || str_contains($n, 'não encontrado');
    }

    private function isValidationMessage(string $msg): bool
    {
        $n = $this->normalize($msg);
        return str_contains($n, 'cpf deve seguir o modelo')
            || str_contains($n, 'deve seguir o modelo')
            || str_contains($n, 'cpf invalido')
            || str_contains($n, 'cpf inválido')
            || str_contains($n, 'formato invalido')
            || str_contains($n, 'formato inválido');
    }

    private function isRetryableForbiddenMessage(string $msg): bool
    {
        $n = $this->normalize($msg);
        if ($n === '') {
            return false;
        }

        return str_contains($n, 'temporar')
            || str_contains($n, 'rate limit')
            || str_contains($n, 'timeout')
            || str_contains($n, 'gateway')
            || str_contains($n, 'cloudflare')
            || str_contains($n, 'waf')
            || str_contains($n, 'tente novamente')
            || str_contains($n, 'try again');
    }

    private function isFatalAuthError(string $msg): bool
    {
        $n = $this->normalize($msg);
        return str_contains($n, 'usuario ou senha invalida')
            || str_contains($n, 'usuário ou senha inválida')
            || str_contains($n, 'authorization incorreto');
    }
}
