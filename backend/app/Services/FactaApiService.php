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
    private string $baseUrl;
    private ?string $basicAuth;
    private int $tokenTtl;

    /** Timeouts por requisição */
    private int $httpTimeout;
    private int $httpConnectTimeout;

    public function __construct()
    {
        $cfg = config('facta');
        $this->baseUrl   = rtrim($cfg['base_url'] ?? '', '/');
        $this->basicAuth = $cfg['basic_auth'] ?? null;
        $this->tokenTtl  = (int) ($cfg['token_ttl'] ?? 3300);

        $this->httpTimeout        = (int) env('CLT_HTTP_TIMEOUT', 15);
        $this->httpConnectTimeout = (int) env('CLT_HTTP_CONNECT_TIMEOUT', 10);
    }

    public function getToken(): ?string
    {
        return Cache::remember('facta_token', $this->tokenTtl, function () {
            if (!$this->basicAuth) {
                throw new \RuntimeException('FACTA_BASIC_AUTH not configured');
            }

            $resp = Http::withHeaders([
                'Authorization' => 'Basic '.$this->basicAuth,
                'Accept'        => 'application/json',
            ])->timeout(10)->get($this->baseUrl.'/gera-token');

            if (!$resp->ok()) {
                throw new \RuntimeException("FACTA token error: HTTP {$resp->status()}");
            }

            $json = $resp->json();
            if (!is_array($json) || !empty($json['erro'])) {
                throw new \RuntimeException('FACTA token error: '.($json['mensagem'] ?? 'Unknown'));
            }

            $token = $json['token'] ?? null;
            if (!$token) {
                throw new \RuntimeException('FACTA token error: token ausente na resposta');
            }

            return $token;
        });
    }

    /**
     * Consulta unitária (mantida para fallback/compatibilidade).
     * Retorna:
     *  - ok: bool
     *  - mensagem: string
     *  - vinculos: array|null
     *  - retriable: bool
     *  - not_found: bool
     *  - http_status: int|null
     *  - retry_after: int|null (segundos)
     */
    public function autorizaConsulta(string $cpf): array
    {
        $cpf = preg_replace('/\D+/', '', $cpf ?? '');
        if (strlen($cpf) !== 11) {
            return [
                'ok'          => false,
                'mensagem'    => 'CPF inválido',
                'vinculos'    => null,
                'retriable'   => false,
                'not_found'   => false,
                'http_status' => null,
                'retry_after' => null,
            ];
        }

        $doRequest = function () use ($cpf) {
            $token = $this->getToken();

            return Http::withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Accept'        => 'application/json',
            ])
            ->timeout($this->httpTimeout)
            ->connectTimeout($this->httpConnectTimeout)
            ->retry(
                (int) env('CLT_HTTP_RETRY', 1),
                (int) env('CLT_HTTP_RETRY_DELAY_MS', 200),
                function ($exception) {
                    return $exception instanceof ConnectionException;
                }
            )
            ->get($this->baseUrl.'/consignado-trabalhador/autoriza-consulta', [
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
                'ok'          => false,
                'mensagem'    => 'Exceção: '.$e->getMessage(),
                'vinculos'    => null,
                'retriable'   => true,
                'not_found'   => false,
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

        $token   = $this->getToken();
        $headers = [
            'Authorization' => 'Bearer '.$token,
            'Accept'        => 'application/json',
        ];
        $url = $this->baseUrl.'/consignado-trabalhador/autoriza-consulta';

        /** @var array<string,HttpResponse> $responses */
        try {
            $responses = Http::pool(function (Pool $pool) use ($cpfs, $headers, $url) {
                $reqs = [];
                foreach ($cpfs as $cpf) {
                    $reqs[] = $pool->as($cpf)
                        ->withHeaders($headers)
                        ->timeout($this->httpTimeout)
                        ->connectTimeout($this->httpConnectTimeout)
                        ->retry(
                            (int) env('CLT_HTTP_RETRY', 1),
                            (int) env('CLT_HTTP_RETRY_DELAY_MS', 200),
                            function ($exception) {
                                return $exception instanceof ConnectionException;
                            }
                        )
                        ->get($url, ['cpf' => $cpf]);
                }
                return $reqs;
            });
        } catch (Throwable $e) {
            // Pool falhou – tenta unitário para cada CPF (fallback)
            $out = [];
            foreach ($cpfs as $cpf) {
                $one = $this->autorizaConsulta($cpf);
                $one['mensagem'] = 'Fallback (pool exceção): '.($one['mensagem'] ?? '');
                $out[$cpf] = $one;
            }
            return $out;
        }

        // 401 → renova token apenas dos necessários
        $needRetry = [];
        foreach ($responses as $cpf => $resp) {
            if ($resp instanceof HttpResponse && $resp->status() === 401) {
                $needRetry[] = $cpf;
            }
        }
        if (!empty($needRetry)) {
            Cache::forget('facta_token');
            $token2   = $this->getToken();
            $headers2 = [
                'Authorization' => 'Bearer '.$token2,
                'Accept'        => 'application/json',
            ];
            try {
                /** @var array<string,HttpResponse> $retryResponses */
                $retryResponses = Http::pool(function (Pool $pool) use ($needRetry, $headers2, $url) {
                    $reqs = [];
                    foreach ($needRetry as $cpf) {
                        $reqs[] = $pool->as($cpf)
                            ->withHeaders($headers2)
                            ->timeout($this->httpTimeout)
                            ->connectTimeout($this->httpConnectTimeout)
                            ->retry(
                                (int) env('CLT_HTTP_RETRY', 1),
                                (int) env('CLT_HTTP_RETRY_DELAY_MS', 200),
                                function ($exception) {
                                    return $exception instanceof ConnectionException;
                                }
                            )
                            ->get($url, ['cpf' => $cpf]);
                    }
                    return $reqs;
                });
                foreach ($retryResponses as $cpf => $resp) {
                    $responses[$cpf] = $resp;
                }
            } catch (Throwable $e) {
                // mantém as respostas antigas (401); o Job vai retriar em outra tentativa
            }
        }

        $out = [];
        foreach ($cpfs as $cpf) {
            $resp = $responses[$cpf] ?? null;
            if (!$resp instanceof HttpResponse) {
                // Fallback unitário quando o pool não devolveu Response para este CPF
                $one = $this->autorizaConsulta($cpf);
                $one['mensagem'] = 'Fallback após pool: '.($one['mensagem'] ?? '');
                $out[$cpf] = $one;
                continue;
            }
            $out[$cpf] = $this->parseAutorizaResponse($resp);
        }

        return $out;
    }

    /** --------- Helpers --------- */

    private function parseAutorizaResponse(HttpResponse $resp): array
    {
        $status     = $resp->status();
        $retryAfter = $this->getRetryAfterSeconds($resp);

        if (!$resp->ok()) {
            $mensagem  = $this->responseMessage($resp);
            $retriable = in_array($status, [401, 408, 429], true) || $status >= 500;
            return [
                'ok'          => false,
                'mensagem'    => $mensagem ?: "HTTP {$status}",
                'vinculos'    => null,
                'retriable'   => $retriable,
                'not_found'   => false,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        $json = $resp->json();

        if (!is_array($json)) {
            return [
                'ok'          => false,
                'mensagem'    => $this->responseMessage($resp) ?: 'Resposta inválida da FACTA',
                'vinculos'    => null,
                'retriable'   => true,
                'not_found'   => false,
                'http_status' => $status,
                'retry_after' => $retryAfter,
            ];
        }

        if (!empty($json['erro'])) {
            $mensagem        = (string) ($json['mensagem'] ?? 'Falha na consulta');
            $isNaoEncontrado = $this->isNaoEncontradoMessage($mensagem);

            return [
                'ok'          => false,
                'mensagem'    => $mensagem,
                'vinculos'    => null,
                'retriable'   => !$isNaoEncontrado,
                'not_found'   => $isNaoEncontrado,
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
                'ok'          => true,
                'mensagem'    => $json['mensagem'] ?? ($container['mensagem'] ?? 'OK'),
                'vinculos'    => $dados,
                'retriable'   => false,
                'not_found'   => false,
                'http_status' => 200,
                'retry_after' => null,
            ];
        }

        return [
            'ok'          => true,
            'mensagem'    => $json['mensagem'] ?? ($container['mensagem'] ?? 'Sem vínculos'),
            'vinculos'    => [],
            'retriable'   => false,
            'not_found'   => false,
            'http_status' => 200,
            'retry_after' => null,
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

    private function isNaoEncontradoMessage(string $mensagem): bool
    {
        $msg = trim($mensagem);

        if (strcasecmp($msg, 'CPF não encontrado na base') === 0) return true;
        if (strcasecmp($msg, 'CPF nao encontrado na base') === 0) return true;

        $norm = $this->normalize($msg);
        if ($norm === 'cpf nao encontrado na base') return true;

        return str_contains($norm, 'nao encontrado na base')
            || str_contains($norm, 'não encontrado na base');
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
}
