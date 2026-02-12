<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\C6AuthorizationLink;
use App\Services\C6\C6AuthorizationService;
use App\Services\C6\Exceptions\C6ApiException;
use App\Support\Cpf;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class C6AuthorizationLinkController extends Controller
{
    public function __invoke(Request $request, C6AuthorizationService $c6): Response
    {
        $data = $request->validate([
            'cpf' => ['required', 'string', 'max:20'],
            'nome_cliente' => ['nullable', 'string', 'max:255'],
            // Compatibilidade com payload anterior.
            'nome' => ['nullable', 'string', 'max:255'],
            'data_nascimento' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'telefone' => ['nullable', 'array'],
            'telefone.numero' => ['nullable', 'string', 'max:32'],
            'telefone.codigo_area' => ['nullable', 'string', 'max:8'],
        ]);

        $normalizedCpf = Cpf::normalize($data['cpf'] ?? null);

        if ($normalizedCpf === null || ! Cpf::isValid($normalizedCpf)) {
            throw ValidationException::withMessages([
                'cpf' => ['CPF inválido. Informe um CPF válido com 11 dígitos.'],
            ]);
        }

        $phoneNumber = $this->digitsOnly((string) data_get($data, 'telefone.numero', ''));
        $areaCode = $this->digitsOnly((string) data_get($data, 'telefone.codigo_area', ''));

        if ($phoneNumber !== '' && ! in_array(strlen($phoneNumber), [8, 9], true)) {
            throw ValidationException::withMessages([
                'telefone.numero' => ['Telefone inválido. Informe 8 ou 9 dígitos (sem DDI e sem DDD).'],
            ]);
        }

        if ($areaCode !== '' && strlen($areaCode) !== 2) {
            throw ValidationException::withMessages([
                'telefone.codigo_area' => ['DDD inválido. Informe 2 dígitos.'],
            ]);
        }

        $nomeClienteInput = $this->pickClientName(
            $data['nome_cliente'] ?? null,
            $data['nome'] ?? null
        );

        try {
            $result = $c6->generateAuthorizationLink(
                cpf: $normalizedCpf,
                nome: $nomeClienteInput,
                dataNascimento: isset($data['data_nascimento']) ? (string) $data['data_nascimento'] : null,
                ddd: $areaCode !== '' ? $areaCode : null,
                numero: $phoneNumber !== '' ? $phoneNumber : null
            );

            $expiresAt = CarbonImmutable::parse((string) ($result['data_expiracao'] ?? ''));
            $generatedAt = now();
            // Persiste apenas o nome informado pelo usuário; sem fallback aleatório.
            $nomeCliente = $nomeClienteInput;

            $status = $expiresAt->lessThanOrEqualTo($generatedAt)
                ? C6AuthorizationLink::STATUS_EXPIRED
                : C6AuthorizationLink::STATUS_ACTIVE;

            // Não exclui: apenas marca os expirados.
            C6AuthorizationLink::markExpired((int) $request->user()->id);

            C6AuthorizationLink::create([
                'user_id' => $request->user()->id,
                'cpf' => $normalizedCpf,
                'nome_cliente' => $nomeCliente,
                'link' => (string) $result['link'],
                'generated_at' => $generatedAt,
                'expires_at' => $expiresAt,
                'status' => $status,
            ]);

            return response()->json([
                'link' => (string) $result['link'],
                'nome_cliente' => $nomeCliente,
                'generated_at' => $generatedAt->toIso8601String(),
                'data_expiracao' => $expiresAt->toIso8601String(),
                'status' => $status,
            ]);
        } catch (C6ApiException $e) {
            return response()->json([
                'error' => $e->error(),
                'message' => $e->getMessage(),
                'upstream_status' => $e->upstreamStatus(),
                'upstream_body' => $e->upstreamBody(),
            ], $e->httpStatus());
        } catch (InvalidFormatException $e) {
            return response()->json([
                'error' => 'c6_invalid_expiration',
                'message' => 'Formato inválido de data de expiração retornado pelo C6.',
            ], 502);
        } catch (\Throwable $e) {
            Log::error('Unexpected C6 authorization link generation error', [
                'cpf' => $normalizedCpf,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'c6_unexpected_error',
                'message' => 'Erro inesperado ao gerar link de autorização.',
            ], 500);
        }
    }

    protected function digitsOnly(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?: '';
    }

    protected function pickClientName(?string $primary, ?string $fallback = null): ?string
    {
        $name = trim((string) ($primary ?: $fallback ?: ''));
        return $name !== '' ? $name : null;
    }
}
