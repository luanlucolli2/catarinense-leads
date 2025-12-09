<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateC6AuthorizationLinkJob;
use App\Models\InovachatTriage;
use App\Support\Cpf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class InovachatTriageController extends Controller
{
    /**
     * Endpoint chamado pelo Flowbuilder do Inovachat.
     *
     * Versão atual:
     *  - valida/normaliza CPF;
     *  - gera tracking_id e faz log estruturado;
     *  - persiste triagem (inovachat_triages);
     *  - dispara Job assíncrono para gerar link de autorização no C6
     *    e enviar ao cliente via Inovachat;
     *  - responde rapidamente ao Flowbuilder.
     */
    public function __invoke(Request $request): Response
    {
        // 1) Validação básica do payload
        $data = $request->validate([
            'cpf'              => ['required', 'string', 'max:100'],
            'connection_token' => ['nullable', 'string', 'max:255'],
            'phone'            => ['nullable', 'string', 'max:32'],
            'ticket_id'        => ['nullable', 'string', 'max:64'],
            'protocol'         => ['nullable', 'string', 'max:64'],
            'name'             => ['nullable', 'string', 'max:255'],
            'firstName'        => ['nullable', 'string', 'max:255'],
            'source'           => ['nullable', 'string', 'max:64'],

            'openTicket'       => ['nullable', 'string', 'max:16'],
            'queueId'          => ['nullable', 'string', 'max:32'],
        ]);

        // 2) Normaliza CPF usando helper centralizado
        $normalizedCpf = Cpf::normalize($data['cpf'] ?? null);

        if ($normalizedCpf === null || ! Cpf::isValid($normalizedCpf)) {
            return response()->json([
                'error'   => 'cpf_invalid',
                'message' => 'CPF inválido. Revise o número informado.',
            ], 422);
        }

        // 3) Gera tracking_id para rastrear esse request no futuro
        $trackingId = (string) Str::uuid();

        $openTicket = (string) ($data['openTicket'] ?? '0');
        $queueId    = (string) ($data['queueId'] ?? '0');

        // 4) Persiste triagem
        InovachatTriage::create([
            'tracking_id'      => $trackingId,
            'cpf'              => $normalizedCpf,
            'connection_token' => $data['connection_token'] ?? null,
            'phone'            => $data['phone'] ?? null,
            'ticket_id'        => $data['ticket_id'] ?? null,
            'protocol'         => $data['protocol'] ?? null,
            'name'             => $data['name'] ?? null,
            'first_name'       => $data['firstName'] ?? null,
            'source'           => $data['source'] ?? 'inovachat-flow',
            'status'           => 'started',
        ]);

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
            'openTicket'       => $openTicket,
            'queueId'          => $queueId,
            'ip'               => $request->ip(),
            'user_agent'       => $request->userAgent(),
        ]);

        // 6) Dispara Job assíncrono para gerar link no C6 e enviar ao cliente
        GenerateC6AuthorizationLinkJob::dispatch(
            trackingId: $trackingId,
            cpf: $normalizedCpf,
            firstName: $data['firstName'] ?? null,
            fullName: $data['name'] ?? null,
            phone: $data['phone'] ?? null,
            openTicket: $openTicket,
            queueId: $queueId
        );

        // 7) Resposta simples para o Flowbuilder consumir
        return response()->json([
            'tracking_id' => $trackingId,
            'cpf'         => $normalizedCpf,
        ]);
    }
}
