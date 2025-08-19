<?php

namespace App\Services;

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
        $this->baseUrl = rtrim($cfg['base_url'] ?? '', '/');
        $this->basicAuth = $cfg['basic_auth'] ?? null;
        $this->tokenTtl = (int) ($cfg['token_ttl'] ?? 3300);
    }

    public function getToken(): ?string
    {
        return Cache::remember('facta_token', $this->tokenTtl, function () {
            if (!$this->basicAuth) {
                throw new \RuntimeException('FACTA_BASIC_AUTH not configured');
            }

            $resp = Http::withHeaders([
                'Authorization' => 'Basic ' . $this->basicAuth,
            ])->timeout(10)->get($this->baseUrl . '/gera-token');

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

            return $token;
        });
    }

    /**
     * Consulta dados do trabalhador (Dataprev) por CPF.
     * Retorna:
     *  - ok: bool
     *  - mensagem: string
     *  - vinculos: array|null
     *  - retriable: bool (false para erros terminais, ex.: "CPF não encontrado na base")
     */
    public function autorizaConsulta(string $cpf): array
    {
        // Reforço: 11 dígitos
        $cpf = preg_replace('/\D+/', '', $cpf ?? '');
        if (strlen($cpf) !== 11) {
            return ['ok' => false, 'mensagem' => 'CPF inválido', 'vinculos' => null, 'retriable' => false];
        }

        $doRequest = function () use ($cpf) {
            $token = $this->getToken();

            return Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
            ])->timeout(15)->get($this->baseUrl . '/consignado-trabalhador/autoriza-consulta', [
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

            if (!$resp->ok()) {
                $status = $resp->status();
                // retriável p/ 401/408/429/5xx; não-retriável p/ 400/404 etc.
                $retriable = in_array($status, [401, 408, 429], true) || $status >= 500;
                return ['ok' => false, 'mensagem' => "HTTP {$status}", 'vinculos' => null, 'retriable' => $retriable];
            }

            $json = $resp->json();
            if (!is_array($json)) {
                return ['ok' => false, 'mensagem' => 'Resposta inválida da FACTA', 'vinculos' => null, 'retriable' => true];
            }

            // sucesso
            if (empty($json['erro'])) {
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
                    ];
                }

                // sucesso sem vínculos
                return [
                    'ok' => true,
                    'mensagem' => $json['mensagem'] ?? ($container['mensagem'] ?? 'Sem vínculos'),
                    'vinculos' => [],
                    'retriable' => false,
                ];
            }

            // erro=true
            $mensagem = (string) ($json['mensagem'] ?? 'Falha na consulta');
            $terminalNaoEncontrado = $this->isNaoEncontradoMessage($mensagem);

            return [
                'ok' => false,
                'mensagem' => $mensagem,
                'vinculos' => null,
                'retriable' => !$terminalNaoEncontrado,
            ];

        } catch (Throwable $e) {
            return ['ok' => false, 'mensagem' => 'Exceção: ' . $e->getMessage(), 'vinculos' => null, 'retriable' => true];
        }
    }

    /**
     * Detecta de forma robusta "CPF não encontrado na base" (com/sem acento, espaços, variações).
     */
    private function isNaoEncontradoMessage(string $mensagem): bool
    {
        $msg = trim($mensagem);

        // Comparação direta (mais rápida) — com acento e exatamente igual
        if (strcasecmp($msg, 'CPF não encontrado na base') === 0) {
            return true;
        }
        // Mesma frase sem acento
        if (strcasecmp($msg, 'CPF nao encontrado na base') === 0) {
            return true;
        }

        // Normaliza para lower-case, remove acentos básicos e reduz espaços
        $norm = $this->normalize($msg);
        if ($norm === 'cpf nao encontrado na base') {
            return true;
        }

        // Também aceita como *substring* (algumas APIs anexam detalhes antes/depois)
        return str_contains($norm, 'nao encontrado na base')
            || str_contains($norm, 'não encontrado na base'); // por redundância/clareza
    }

    /**
     * Normaliza string: lower, sem acentos, espaços colapsados.
     */
    private function normalize(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        // remove acentos comuns sem depender de ext externas
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
        // colapsa múltiplos espaços
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }
}
