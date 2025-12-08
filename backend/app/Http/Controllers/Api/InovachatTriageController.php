<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Cpf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class InovachatTriageController extends Controller
{
    /**
     * Endpoint chamado pelo Flowbuilder do Inovachat.
     * Primeira versão: apenas valida/normaliza CPF, faz log e confirma recebimento.
     *
     * Futuro: aqui você pode despachar um Job para orquestrar consultas nos bancos (Facta, C6, etc.).
     */
    public function __invoke(Request $request): Response
    {
        // 1) Validação básica do payload
        // Aumentamos max:32 para max:100 para evitar erro de validação do Laravel em strings "sujas"
        $data = $request->validate([
            'cpf'              => ['required', 'string', 'max:100'],
            'connection_token' => ['nullable', 'string', 'max:255'],
            'phone'            => ['nullable', 'string', 'max:32'],
            'ticket_id'        => ['nullable', 'string', 'max:64'],
            'protocol'         => ['nullable', 'string', 'max:64'],
            'name'             => ['nullable', 'string', 'max:255'],
            'firstName'        => ['nullable', 'string', 'max:255'],
            'source'           => ['nullable', 'string', 'max:64'],
        ]);

        // 2) Normaliza CPF usando helper centralizado
        // - mantém apenas dígitos, completa com zeros, etc.
        $normalizedCpf = Cpf::normalize($data['cpf'] ?? null);

        // 3) Se não normalizar ou for inválido pelos dígitos verificadores, retorna 422
        if ($normalizedCpf === null || ! Cpf::isValid($normalizedCpf)) {
            return response()->json([
                'error'   => 'cpf_invalid',
                'message' => 'CPF inválido. Revise o número informado.',
            ], 422);
        }

        // 4) Gera um tracking_id para rastrear esse request no futuro
        $trackingId = (string) Str::uuid();

        // 5) Log estruturado para debug e rastreabilidade
        Log::info('Inovachat triage webhook received', [
            'tracking_id'      => $trackingId,
            'cpf'              => $normalizedCpf,
            'connection_token' => $data['connection_token'] ?? null,
            'phone'            => $data['phone'] ?? null,
            'ticket_id'        => $data['ticket_id'] ?? null,
            'protocol'         => $data['protocol'] ?? null,
            'name'             => $data['name'] ?? null,
            'firstName'        => $data['firstName'] ?? null,
            'source'           => $data['source'] ?? 'inovachat-flow',
            'ip'               => $request->ip(),
            'user_agent'       => $request->userAgent(),
        ]);

        // 6) Ponto futuro de extensão: disparar Job assíncrono
        // dispatch(new ProcessInovachatTriageJob(...));

        // 7) Resposta simples para o Flowbuilder consumir (sem 'ok')
        return response()->json([
            'tracking_id' => $trackingId,
            'cpf'         => $normalizedCpf,
        ]);
    }
}