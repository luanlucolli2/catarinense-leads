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
     *  - mensagem: string
     *  - vinculos: array|null
     *  - retriable: bool (false para erros terminais, ex.: "CPF não encontrado na base")
     */
    public function autorizaConsulta(string $cpf): array
    {
        // CPF já deve vir normalizado/validado no Job; aqui só reforço 11 dígitos.
        $cpf = preg_replace('/\D+/', '', $cpf ?? '');
        if (strlen($cpf) !== 11) {
            return ['ok' => false, 'mensagem' => 'CPF inválido', 'vinculos' => null, 'retriable' => false];
        }

        $doRequest = function () use ($cpf) {
            $token = $this->getToken();

            return Http::withHeaders([
                'Authorization' => 'Bearer '.$token,
            ])->timeout(15)->get($this->baseUrl.'/consignado-trabalhador/autoriza-consulta', [
                'cpf' => $cpf,
            ]);
        };

        try {
            $resp = $doRequest();

            if ($resp->status() === 401) {
                Cache::forget('facta_token');
                $resp = $doRequest();
            }

            if (!$resp->ok()) {
                $status = $resp->status();
                // Considera não-retriável para 400; retriável para 401/408/429/5xx
                $retriable = in_array($status, [401, 408, 429], true) || $status >= 500;
                return ['ok' => false, 'mensagem' => "HTTP {$status}", 'vinculos' => null, 'retriable' => $retriable];
            }

            $json = $resp->json();
            if (!is_array($json)) {
                return ['ok' => false, 'mensagem' => 'Resposta inválida da FACTA', 'vinculos' => null, 'retriable' => true];
            }

            if (empty($json['erro'])) {
                // aceita variações de chave
                $container =
                    $json['dados_Trabalhador']
                    ?? $json['dados_trabalhador']
                    ?? $json['dadosTrabalhador']
                    ?? null;

                $dados = is_array($container) ? ($container['dados'] ?? null) : null;

                if (is_array($dados) && count($dados) > 0) {
                    return [
                        'ok'        => true,
                        'mensagem'  => $json['mensagem'] ?? ($container['mensagem'] ?? 'OK'),
                        'vinculos'  => $dados,
                        'retriable' => false, // sucesso não precisa retry
                    ];
                }

                return [
                    'ok'        => true,
                    'mensagem'  => $json['mensagem'] ?? ($container['mensagem'] ?? 'Sem vínculos'),
                    'vinculos'  => [],
                    'retriable' => false,
                ];
            }

            // erro=true
            $mensagem = (string) ($json['mensagem'] ?? 'Falha na consulta');
            $lower = mb_strtolower($mensagem, 'UTF-8');
            $naoEncontrado =
                str_contains($lower, 'não encontrado na base') ||
                str_contains($lower, 'nao encontrado na base');

            return [
                'ok'        => false,
                'mensagem'  => $mensagem,
                'vinculos'  => null,
                'retriable' => ! $naoEncontrado, // não-retriável quando "CPF não encontrado na base"
            ];

        } catch (Throwable $e) {
            return ['ok' => false, 'mensagem' => 'Exceção: '.$e->getMessage(), 'vinculos' => null, 'retriable' => true];
        }
    }
}
