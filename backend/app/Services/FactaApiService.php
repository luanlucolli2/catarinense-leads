<?php

namespace App\Services;

use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class FactaApiService
{
    private string $baseUrl;
    private ?string $basicAuth;
    private int $tokenTtl;

    public function __construct()
    {
        $cfg = config('facta');
        $this->baseUrl   = rtrim($cfg['base_url'] ?? '', '/');
        $this->basicAuth = $cfg['basic_auth'] ?? null;
        $this->tokenTtl  = (int) ($cfg['token_ttl'] ?? 3300);
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
     * Consulta dados do trabalhador (Dataprev) por CPF.
     * Retorna:
     *  - ok: bool
     *  - mensagem: string (sempre tentando preservar a mensagem real da FACTA)
     *  - vinculos: array|null
     *  - retriable: bool (false p/ "CPF não encontrado na base")
     *  - not_found: bool (true quando a mensagem indica explicitamente "CPF não encontrado na base")
     */
    public function autorizaConsulta(string $cpf): array
    {
        // Reforço: 11 dígitos
        $cpf = preg_replace('/\D+/', '', $cpf ?? '');
        if (strlen($cpf) !== 11) {
            return [
                'ok'        => false,
                'mensagem'  => 'CPF inválido',
                'vinculos'  => null,
                'retriable' => false,
                'not_found' => false,
            ];
        }

        $doRequest = function () use ($cpf) {
            $token = $this->getToken();

            return Http::withHeaders([
                'Authorization' => 'Bearer '.$token,
                'Accept'        => 'application/json',
            ])->timeout(15)->get($this->baseUrl.'/consignado-trabalhador/autoriza-consulta', [
                'cpf' => $cpf,
            ]);
        };

        try {
            $resp = $doRequest();

            // Se 401, renova token e tenta 1x
            if ($resp->status() === 401) {
                Cache::forget('facta_token');
                $resp = $doRequest();
            }

            // HTTP != 2xx → erro de transporte; tenta extrair mensagem útil
            if (!$resp->ok()) {
                $status    = $resp->status();
                $mensagem  = $this->responseMessage($resp);
                $retriable = in_array($status, [401, 408, 429], true) || $status >= 500;
                return [
                    'ok'        => false,
                    'mensagem'  => $mensagem ?: "HTTP {$status}",
                    'vinculos'  => null,
                    'retriable' => $retriable,
                    'not_found' => false,
                ];
            }

            // HTTP 200 → pode ser sucesso OU erro lógico da FACTA
            $json = $resp->json();

            // 200 com corpo não-JSON: devolve body como mensagem e marca retriable
            if (!is_array($json)) {
                return [
                    'ok'        => false,
                    'mensagem'  => $this->responseMessage($resp) ?: 'Resposta inválida da FACTA',
                    'vinculos'  => null,
                    'retriable' => true,
                    'not_found' => false,
                ];
            }

            // Se "erro" vier true, mesmo com 200 → é falha lógica de negócio.
            if (!empty($json['erro'])) {
                $mensagem = (string) ($json['mensagem'] ?? 'Falha na consulta');
                $isNaoEncontrado = $this->isNaoEncontradoMessage($mensagem);

                return [
                    'ok'        => false,
                    'mensagem'  => $mensagem,                // preserva a mensagem exata
                    'vinculos'  => null,
                    'retriable' => ! $isNaoEncontrado,       // não retry se "não encontrado"
                    'not_found' => $isNaoEncontrado,         // <<< sinal neutro para o Job
                ];
            }

            // Sucesso lógico (erro=false)
            $container =
                $json['dados_Trabalhador']
                ?? $json['dados_trabalhador']
                ?? $json['dadosTrabalhador']
                ?? null;

            $dados = is_array($container) ? ($container['dados'] ?? null) : null;

            // Tem vínculos
            if (is_array($dados) && count($dados) > 0) {
                return [
                    'ok'        => true,
                    'mensagem'  => $json['mensagem'] ?? ($container['mensagem'] ?? 'OK'),
                    'vinculos'  => $dados,
                    'retriable' => false,
                    'not_found' => false,
                ];
            }

            // Sucesso sem vínculos (total 0)
            return [
                'ok'        => true,
                'mensagem'  => $json['mensagem'] ?? ($container['mensagem'] ?? 'Sem vínculos'),
                'vinculos'  => [],
                'retriable' => false,
                'not_found' => false,
            ];

        } catch (Throwable $e) {
            return [
                'ok'        => false,
                'mensagem'  => 'Exceção: '.$e->getMessage(),
                'vinculos'  => null,
                'retriable' => true,
                'not_found' => false,
            ];
        }
    }

    /**
     * Extrai a mensagem "mais útil" da resposta HTTP:
     * - Se JSON e tiver 'mensagem'/'message', usa.
     * - Senão, usa o corpo texto (truncado) se houver.
     * - Senão, retorna "HTTP {status}".
     */
    private function responseMessage(HttpResponse $resp): string
    {
        $status = $resp->status();

        // tenta JSON
        try {
            $json = $resp->json();
            if (is_array($json)) {
                $msg = $json['mensagem'] ?? $json['message'] ?? null;
                if (is_string($msg) && trim($msg) !== '') {
                    return trim($msg);
                }
                // nenhum campo padrão — aproveita um trecho do JSON
                $encoded = json_encode($json, JSON_UNESCAPED_UNICODE);
                if (is_string($encoded)) {
                    return $this->truncate(trim($encoded));
                }
            }
        } catch (\Throwable $e) {
            // ignora — não era JSON
        }

        // tenta corpo texto
        $body = (string) $resp->body();
        if (trim($body) !== '') {
            return $this->truncate(trim($body));
        }

        return "HTTP {$status}";
    }

    private function truncate(string $s, int $max = 500): string
    {
        if (mb_strlen($s, 'UTF-8') <= $max) return $s;
        return mb_substr($s, 0, $max, 'UTF-8').'…';
    }

    /**
     * Detecta de forma robusta "CPF não encontrado na base" (com/sem acento, espaços, variações).
     */
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

    /**
     * Normaliza string: lower, sem acentos, espaços colapsados.
     */
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
