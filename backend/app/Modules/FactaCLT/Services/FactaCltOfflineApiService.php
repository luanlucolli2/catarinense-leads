<?php

namespace App\Modules\FactaCLT\Services;

use App\Modules\FactaCLT\Support\FactaCltLog;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class FactaCltOfflineApiService
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

    /** Rate limit global do serviço OFF */
    private bool $rateLimitEnabled;
    private int $minIntervalMs;
    private int $rateLockTtl;
    private int $rateLockWait;
    private int $retryLaterImmediateAttempts;
    private string $rateLockKey = 'clt_off_rate_lock';
    private string $rateLastAtKey = 'clt_off_rate_last_at_ms';

    public function __construct()
    {
        $api = (array) config('facta.clt_off.api', []);
        $http = (array) config('facta.clt_off.http', []);
        $rate = (array) config('facta.clt_off.rate_limit', []);

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
        $this->rateLimitEnabled = (bool) ($rate['enabled'] ?? true);
        $this->minIntervalMs = max(0, (int) ($rate['min_interval_ms'] ?? $http['min_interval_ms'] ?? 3200));
        $this->rateLockTtl = max(1, (int) ($rate['lock_ttl'] ?? 10));
        $this->rateLockWait = max(1, (int) ($rate['lock_wait'] ?? 5));
        $this->retryLaterImmediateAttempts = max(0, (int) ($rate['retry_later_attempts'] ?? 2));
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
        $cpfs = array_values(array_unique($normalizedCpfs));

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
        $url = $this->baseUrl . '/clt/base-offline';

        $out = [];

        foreach ($cpfs as $cpf) {
            $out[$cpf] = $this->consultaCpfOffline($url, $headers, $cpf, $retryAfterDefault);
        }

        return $out;
    }

    private function consultaCpfOffline(string $url, array $headers, string $cpf, float $retryAfterDefault): array
    {
        $immediateRetryBudget = $this->retryLaterImmediateAttempts;

        while (true) {
            $lastError = null;
            $resp = $this->sendOfflineRequest($url, $headers, $cpf, $lastError);

            if (!$resp instanceof HttpResponse) {
                $msg = 'Sem resposta do serviço OFF';
                if ($lastError instanceof Throwable) {
                    $msg .= ': ' . $lastError->getMessage();
                }

                return [
                    'ok' => false,
                    'mensagem' => $msg,
                    'vinculos' => null,
                    'retriable' => true,
                    'not_found' => false,
                    'http_status' => null,
                    'retry_after' => $retryAfterDefault,
                ];
            }

            $parsed = $this->parseOffResponse($resp);
            if (
                $immediateRetryBudget > 0
                && $this->shouldRetryImmediatelyForRetryLaterMessage($parsed)
            ) {
                $immediateRetryBudget--;
                $this->sleepRetryLaterPause();
                continue;
            }

            return $parsed;
        }
    }

    private function sendOfflineRequest(
        string $url,
        array $headers,
        string $cpf,
        ?Throwable &$lastError = null
    ): ?HttpResponse {
        $maxAttempts = max(1, $this->httpRetry + 1);
        $resp = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->throttleRequests();
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

                return null;
            }

            if ($resp->status() === 403) {
                $this->logForbidden($resp, $cpf);
            }

            if ($this->isTransientHttpStatus($resp->status()) && $attempt < $maxAttempts) {
                $this->sleepTransientPauseMs($this->getRetryAfterSeconds($resp));
                continue;
            }

            return $resp;
        }

        return $resp;
    }

    /** ------- Helpers de pacing ------- */
    private function throttleRequests(): void
    {
        if (!$this->rateLimitEnabled || $this->minIntervalMs <= 0) {
            return;
        }

        $lockTimeoutCount = 0;
        while (true) {
            $lock = Cache::lock($this->rateLockKey, $this->rateLockTtl);

            try {
                $lock->block($this->rateLockWait);
            } catch (LockTimeoutException $e) {
                $lockTimeoutCount++;
                if ($lockTimeoutCount >= 5) {
                    throw new \RuntimeException(
                        'Não foi possível adquirir lock de rate limit do CLT-OFF após múltiplas tentativas.',
                        0,
                        $e
                    );
                }

                usleep(200000);
                continue;
            }

            $waitMs = 0;

            try {
                $nowMs = (int) floor(microtime(true) * 1000);
                $lastAtMs = (int) Cache::get($this->rateLastAtKey, 0);

                if ($lastAtMs > 0) {
                    $elapsedMs = $nowMs - $lastAtMs;
                    if ($elapsedMs < $this->minIntervalMs) {
                        $waitMs = $this->minIntervalMs - $elapsedMs;
                    }
                }

                if ($waitMs <= 0) {
                    Cache::put($this->rateLastAtKey, $nowMs, 300);
                    return;
                }
            } finally {
                optional($lock)->release();
            }

            usleep(max(1, $waitMs) * 1000);
        }
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

    private function sleepRetryLaterPause(?int $retryAfterSeconds = null): void
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            sleep($retryAfterSeconds);
            return;
        }

        usleep(max(1, $this->minIntervalMs) * 1000);
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

            FactaCltLog::warning(
                '[CLT-OFF] 403 Forbidden'
                . ($cpf ? " (cpf={$cpf})" : '')
                . ' — headers=' . json_encode($safe, JSON_UNESCAPED_UNICODE)
                . ' body_snippet=' . $snippet
            );
        } catch (\Throwable $e) {
            FactaCltLog::warning('[CLT-OFF] Falha ao logar 403: ' . $e->getMessage());
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

    private function shouldRetryImmediatelyForRetryLaterMessage(array $res): bool
    {
        if (!($res['retriable'] ?? false)) {
            return false;
        }

        $msg = (string) ($res['mensagem'] ?? '');
        return $this->isRetryLaterMessage($msg);
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
