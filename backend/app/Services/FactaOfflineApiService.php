<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Throwable;

class FactaOfflineApiService
{
    /** API / Token */
    private string $baseUrl;
    private ?string $basicAuth;
    private int $tokenTtl;
    private int $tokenLockTtl;
    private int $tokenLockWait;
    private int $tokenTtlSkew;

    /** HTTP knobs */
    private int $httpTimeout;
    private int $httpConnectTimeout;
    private int $httpRetry;
    private int $httpRetryDelayMs;

    /** Pool / Concorrência */
    private int $poolConcurrency;

    /** Token bucket (2 rps padrão; burst=2) */
    private int $rateTokensPerSecond;
    private int $rateBurst;
    private int $bucketLockTtl;
    private int $bucketLockWait;

    public function __construct()
    {
        $cfg = (array) config('facta_off', []);

        // Base/credenciais/token
        $this->baseUrl   = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        $this->basicAuth = $cfg['basic_auth'] ?? null;
        $this->tokenTtl  = (int) ($cfg['token_ttl'] ?? 3600);

        $tokenCfg = (array) ($cfg['token'] ?? []);
        $this->tokenLockTtl  = (int) ($tokenCfg['lock_ttl']  ?? 10);
        $this->tokenLockWait = (int) ($tokenCfg['lock_wait'] ?? 5);
        $this->tokenTtlSkew  = (int) ($tokenCfg['ttl_skew']  ?? 30);

        // HTTP
        $httpCfg = (array) ($cfg['http'] ?? []);
        $this->httpTimeout        = (int) ($httpCfg['timeout']          ?? 10);
        $this->httpConnectTimeout = (int) ($httpCfg['connect_timeout']  ?? 5);
        $this->httpRetry          = (int) ($httpCfg['retry']            ?? 1);
        $this->httpRetryDelayMs   = (int) ($httpCfg['retry_delay_ms']   ?? 200);

        // Pool/concorrência
        $poolCfg = (array) ($cfg['pool'] ?? []);
        $this->poolConcurrency = (int) ($poolCfg['concurrency'] ?? 2);

        // Rate limit (token bucket)
        $rateCfg = (array) ($cfg['rate'] ?? []);
        $this->rateTokensPerSecond = (int) ($rateCfg['tokens_per_second'] ?? 2); // 2 rps
        $this->rateBurst           = (int) ($rateCfg['burst']             ?? 2); // rajada de 2
        $this->bucketLockTtl       = (int) ($rateCfg['bucket_lock_ttl']   ?? 2);
        $this->bucketLockWait      = (int) ($rateCfg['bucket_lock_wait']  ?? 1);
    }

    /**
     * Token com cache + lock (anti-thundering herd)
     */
    public function getToken(): ?string
    {
        $cached = Cache::get('facta_off_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $lock = Cache::lock('facta_off_token_lock', $this->tokenLockTtl);
        $lock->block($this->tokenLockWait);

        try {
            $cached = Cache::get('facta_off_token');
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            if (!$this->basicAuth) {
                throw new \RuntimeException('FACTA_OFF_BASIC_AUTH not configured');
            }

            // Gera token (usa timeouts/retry configurados)
            $resp = Http::withHeaders([
                    'Authorization' => 'Basic '.$this->basicAuth,
                    'Accept'        => 'application/json',
                ])
                ->timeout($this->httpTimeout)
                ->connectTimeout($this->httpConnectTimeout)
                ->retry(
                    $this->httpRetry,
                    $this->httpRetryDelayMs,
                    fn ($e) => $e instanceof ConnectionException
                )
                ->get($this->baseUrl.'/gera-token');

            if (!$resp->ok()) {
                throw new \RuntimeException("FACTA OFF token error: HTTP {$resp->status()}");
            }

            $json = $resp->json();
            if (!is_array($json) || !empty($json['erro'])) {
                throw new \RuntimeException('FACTA OFF token error: '.($json['mensagem'] ?? 'Unknown'));
            }

            $token = $json['token'] ?? null;
            if (!$token) {
                throw new \RuntimeException('FACTA OFF token error: token ausente na resposta');
            }

            $ttl = max(30, $this->tokenTtl - $this->tokenTtlSkew);
            Cache::put('facta_off_token', $token, $ttl);

            return $token;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Adquire 1 "token" do bucket global (2 rps, burst=2).
     * Bloqueia até existir crédito. Coordena entre todos os workers.
     */
    private function acquireToken(): void
    {
        // Estados no cache:
        // - fgts_off_bucket_tokens (int)
        // - fgts_off_bucket_last_ms (int, epoch ms da última recarga)
        while (true) {
            $lock = Cache::lock('fgts_off_bucket_lock', $this->bucketLockTtl);
            $lock->block($this->bucketLockWait);

            try {
                $nowMs   = (int) round(microtime(true) * 1000);
                $lastMs  = (int) (Cache::get('fgts_off_bucket_last_ms') ?? $nowMs);
                $tokens  = (int) (Cache::get('fgts_off_bucket_tokens') ?? $this->rateBurst);

                // Refill proporcional ao tempo decorrido
                $elapsedMs = max(0, $nowMs - $lastMs);
                $add = (int) floor(($elapsedMs * $this->rateTokensPerSecond) / 1000);
                if ($add > 0) {
                    $tokens = min($this->rateBurst, $tokens + $add);
                    $lastMs = $nowMs; // move o marcador
                }

                if ($tokens > 0) {
                    // consome 1 token e segue
                    $tokens--;
                    Cache::put('fgts_off_bucket_tokens', $tokens, 5);
                    Cache::put('fgts_off_bucket_last_ms', $lastMs, 5);
                    return;
                }

                // Sem créditos → calcula espera até próximo token
                // créditos fracionários acumulados:
                $credits = ($elapsedMs * $this->rateTokensPerSecond) / 1000.0;
                $need    = max(0.0, 1.0 - $credits);
                $waitMs  = (int) ceil(($need * 1000.0) / max(1, $this->rateTokensPerSecond));

            } finally {
                optional($lock)->release();
            }

            // Dorme fora do lock
            usleep(max(1, $waitMs) * 1000);
        }
    }

    /**
     * Consulta unitária no endpoint FGTS Base Offline.
     *
     * Retorna:
     *  - ok: bool
     *  - mensagem: string
     *  - authorized: bool|null
     *  - authorized_until: string|null  (dd/mm/yyyy)
     *  - retriable: bool
     *  - http_status: int|null
     *  - retry_after: int|null (segundos)
     *  - consultado_at: string (d/m/Y H:i:s, America/Sao_Paulo)
     */
    public function consultaCpf(string $cpf): array
    {
        $cpf = preg_replace('/\D+/', '', $cpf ?? '');
        if (strlen($cpf) !== 11) {
            return [
                'ok'               => false,
                'mensagem'         => 'CPF inválido',
                'authorized'       => null,
                'authorized_until' => null,
                'retriable'        => false,
                'http_status'      => null,
                'retry_after'      => null,
                'consultado_at'    => $this->nowBrString(),
            ];
        }

        $doRequest = function () use ($cpf) {
            $this->acquireToken(); // token bucket global
            $token = $this->getToken();

            return Http::withHeaders([
                    'Authorization' => 'Bearer '.$token,
                    'Accept'        => 'application/json',
                ])
                ->timeout($this->httpTimeout)
                ->connectTimeout($this->httpConnectTimeout)
                ->retry(
                    $this->httpRetry,
                    $this->httpRetryDelayMs,
                    fn ($e) => $e instanceof ConnectionException
                )
                ->get($this->baseUrl.'/fgts/base-offline', ['cpf' => $cpf]);
        };

        try {
            $resp = $doRequest();

            // 401 → renova token e tenta de novo (passando pelo bucket novamente)
            if ($resp->status() === 401) {
                Cache::forget('facta_off_token');
                $resp = $doRequest();
            }

            $parsed = $this->parseConsultaResponse($resp);
            $parsed['consultado_at'] = $this->nowBrString();
            return $parsed;

        } catch (Throwable $e) {
            return [
                'ok'               => false,
                'mensagem'         => 'Exceção: '.$e->getMessage(),
                'authorized'       => null,
                'authorized_until' => null,
                'retriable'        => true,
                'http_status'      => null,
                'retry_after'      => null,
                'consultado_at'    => $this->nowBrString(),
            ];
        }
    }

    /**
     * Versão em lote concorrente.
     * - Envia em "pools" de até $poolConcurrency (default 2 in-flight).
     * - Cada request consome 1 token do bucket (2 rps globais).
     * Retorna mapa [cpf => resultado].
     */
    public function consultaCpfLote(array $cpfs): array
    {
        // normaliza e deduplica
        $cpfs = array_values(array_unique(array_filter(array_map(function ($c) {
            $d = preg_replace('/\D+/', '', (string) $c);
            return strlen($d) === 11 ? $d : null;
        }, $cpfs))));

        if (empty($cpfs)) {
            return [];
        }

        $out = [];

        // processa em pequenos pools (concorrência máx = $this->poolConcurrency)
        $chunks = array_chunk($cpfs, max(1, $this->poolConcurrency));
        foreach ($chunks as $chunk) {
            // garante 1 token por request ANTES de montar o pool
            foreach ($chunk as $_) {
                $this->acquireToken();
            }

            // usa um único Bearer para o pool (401s serão re-tentados isoladamente)
            $token = $this->getToken();
            $headers = [
                'Authorization' => 'Bearer '.$token,
                'Accept'        => 'application/json',
            ];
            $url = $this->baseUrl.'/fgts/base-offline';

            /** @var array<string,HttpResponse> $responses */
            $responses = [];
            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk, $headers, $url) {
                    $reqs = [];
                    foreach ($chunk as $cpf) {
                        $reqs[] = $pool->as($cpf)
                            ->withHeaders($headers)
                            ->timeout($this->httpTimeout)
                            ->connectTimeout($this->httpConnectTimeout)
                            ->retry(
                                $this->httpRetry,
                                $this->httpRetryDelayMs,
                                fn ($e) => $e instanceof ConnectionException
                            )
                            ->get($url, ['cpf' => $cpf]);
                    }
                    return $reqs;
                });
            } catch (Throwable $e) {
                // se o pool falhar inteiro, marca todos como retriable
                foreach ($chunk as $cpf) {
                    $out[$cpf] = [
                        'ok'               => false,
                        'mensagem'         => 'Sem resposta (pool falhou)',
                        'authorized'       => null,
                        'authorized_until' => null,
                        'retriable'        => true,
                        'http_status'      => null,
                        'retry_after'      => null,
                        'consultado_at'    => $this->nowBrString(),
                    ];
                }
                continue;
            }

            // 401 → renova token e reenvia apenas os necessários
            $needRetry401 = [];
            foreach ($chunk as $cpf) {
                $resp = $responses[$cpf] ?? null;
                if ($resp instanceof HttpResponse && $resp->status() === 401) {
                    $needRetry401[] = $cpf;
                }
            }
            if (!empty($needRetry401)) {
                Cache::forget('facta_off_token');
                // bucket para cada novo request
                foreach ($needRetry401 as $_) {
                    $this->acquireToken();
                }
                $token2   = $this->getToken();
                $headers2 = [
                    'Authorization' => 'Bearer '.$token2,
                    'Accept'        => 'application/json',
                ];
                try {
                    $retryResp = Http::pool(function (Pool $pool) use ($needRetry401, $headers2, $url) {
                        $reqs = [];
                        foreach ($needRetry401 as $cpf) {
                            $reqs[] = $pool->as($cpf)
                                ->withHeaders($headers2)
                                ->timeout($this->httpTimeout)
                                ->connectTimeout($this->httpConnectTimeout)
                                ->retry(
                                    $this->httpRetry,
                                    $this->httpRetryDelayMs,
                                    fn ($e) => $e instanceof ConnectionException
                                )
                                ->get($url, ['cpf' => $cpf]);
                        }
                        return $reqs;
                    });
                    foreach ($retryResp as $cpf => $resp) {
                        $responses[$cpf] = $resp;
                    }
                } catch (Throwable $e) {
                    // mantém as 401 (o Job vai retriar em nova tentativa)
                }
            }

            // monta saída
            foreach ($chunk as $cpf) {
                $resp = $responses[$cpf] ?? null;
                if (!$resp instanceof HttpResponse) {
                    $out[$cpf] = [
                        'ok'               => false,
                        'mensagem'         => 'Sem resposta do serviço',
                        'authorized'       => null,
                        'authorized_until' => null,
                        'retriable'        => true,
                        'http_status'      => null,
                        'retry_after'      => null,
                        'consultado_at'    => $this->nowBrString(),
                    ];
                    continue;
                }
                $parsed = $this->parseConsultaResponse($resp);
                $parsed['consultado_at'] = $this->nowBrString();
                $out[$cpf] = $parsed;
            }
        }

        return $out;
    }

    /** ---------------- Helpers ---------------- */

    private function parseConsultaResponse(HttpResponse $resp): array
    {
        $status     = $resp->status();
        $retryAfter = $this->getRetryAfterSeconds($resp);

        if (!$resp->ok()) {
            $mensagem  = $this->responseMessage($resp);
            $retriable = in_array($status, [401, 408, 429], true) || $status >= 500;

            // mensagens do tipo “volte em X segundos”
            $fromMsg   = $this->parseRetryAfterFromMessage($mensagem);
            $retryAfter = max((int) ($retryAfter ?? 0), (int) ($fromMsg ?? 0)) ?: null;

            return [
                'ok'               => false,
                'mensagem'         => $mensagem ?: "HTTP {$status}",
                'authorized'       => null,
                'authorized_until' => null,
                'retriable'        => $retriable,
                'http_status'      => $status,
                'retry_after'      => $retryAfter,
            ];
        }

        $json = $resp->json();
        if (!is_array($json)) {
            return [
                'ok'               => false,
                'mensagem'         => $this->responseMessage($resp) ?: 'Resposta inválida da FACTA OFF',
                'authorized'       => null,
                'authorized_until' => null,
                'retriable'        => true,
                'http_status'      => $status,
                'retry_after'      => $retryAfter,
            ];
        }

        // documentação pode apontar erro=true mesmo com sucesso → confiar no 'mensagem'
        $mensagem = trim((string) ($json['mensagem'] ?? $json['message'] ?? ''));
        $norm     = $this->normalize($mensagem);

        // "autorizado até 26/09/2025"
        $autUntil = $this->extractAuthorizedUntil($mensagem);
        if ($autUntil !== null) {
            return [
                'ok'               => true,
                'mensagem'         => $mensagem !== '' ? $mensagem : 'CPF autorizado',
                'authorized'       => true,
                'authorized_until' => $autUntil,
                'retriable'        => false,
                'http_status'      => 200,
                'retry_after'      => null,
            ];
        }

        // "não autorizado"
        if (str_contains($norm, 'nao autorizado') || str_contains($norm, 'não autorizado')) {
            return [
                'ok'               => true,
                'mensagem'         => $mensagem !== '' ? $mensagem : 'CPF não autorizado',
                'authorized'       => false,
                'authorized_until' => null,
                'retriable'        => false,
                'http_status'      => 200,
                'retry_after'      => null,
            ];
        }

        // "sem permissão" → erro terminal sem retry
        if (str_contains($norm, 'sem permissao') || str_contains($norm, 'sem permissão')) {
            return [
                'ok'               => false,
                'mensagem'         => $mensagem !== '' ? $mensagem : 'Sem permissão',
                'authorized'       => null,
                'authorized_until' => null,
                'retriable'        => false,
                'http_status'      => 200, // HTTP 200 mas sem permissão na semântica
                'retry_after'      => null,
            ];
        }

        // Rate limit por mensagem "volte em X segundos"
        $fromMsg = $this->parseRetryAfterFromMessage($mensagem);
        if ($fromMsg !== null && $fromMsg > 0) {
            return [
                'ok'               => false,
                'mensagem'         => $mensagem,
                'authorized'       => null,
                'authorized_until' => null,
                'retriable'        => true,
                'http_status'      => 200,
                'retry_after'      => $fromMsg,
            ];
        }

        // Desconhecido → retriable
        return [
            'ok'               => false,
            'mensagem'         => $mensagem !== '' ? $mensagem : 'Resposta não reconhecida',
            'authorized'       => null,
            'authorized_until' => null,
            'retriable'        => true,
            'http_status'      => 200,
            'retry_after'      => null,
        ];
    }

    private function getRetryAfterSeconds(HttpResponse $resp): ?int
    {
        $h = $resp->header('Retry-After');
        if ($h === null) return null;
        $h = trim((string) $h);
        if ($h === '') return null;

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
                if (is_string($msg) && trim($msg) !== '') return trim($msg);
                $encoded = json_encode($json, JSON_UNESCAPED_UNICODE);
                if (is_string($encoded)) return $this->truncate(trim($encoded));
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $body = (string) $resp->body();
        if (trim($body) !== '') return $this->truncate(trim($body));

        return "HTTP {$status}";
    }

    private function truncate(string $s, int $max = 500): string
    {
        if (mb_strlen($s, 'UTF-8') <= $max) return $s;
        return mb_substr($s, 0, $max, 'UTF-8').'…';
    }

    private function extractAuthorizedUntil(string $mensagem): ?string
    {
        // captura dd/mm/yyyy após "autorizado até"
        if (preg_match('/autorizad[oa].*?ate\\s+(\\d{2}\\/\\d{2}\\/\\d{4})/iu', $mensagem, $m)) {
            return $m[1];
        }
        $norm = $this->normalize($mensagem);
        if (preg_match('/autorizad[oa].*?ate\\s+(\\d{2}\\/\\d{2}\\/\\d{4})/i', $norm, $m2)) {
            return $m2[1];
        }
        return null;
    }

    private function parseRetryAfterFromMessage(string $mensagem): ?int
    {
        // "volte em 30 segundos", "tente novamente em 15 segundos", etc.
        if (preg_match('/(volte|tente).*?em\\s+(\\d{1,4})\\s+seg/iu', $mensagem, $m)) {
            return max(0, (int) $m[2]);
        }
        $norm = $this->normalize($mensagem);
        if (preg_match('/(volte|tente).*?em\\s+(\\d{1,4})\\s+seg/i', $norm, $m2)) {
            return max(0, (int) $m2[2]);
        }
        return null;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $map = [
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
            'ç'=>'c',
            'Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a',
            'É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
            'Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
            'Ó'=>'o','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o',
            'Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
            'Ç'=>'c',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }

    /** Horário de Brasília formatado (para carimbar cada resposta). */
    private function nowBrString(): string
    {
        return Carbon::now('America/Sao_Paulo')->format('d/m/Y H:i:s');
    }
}
