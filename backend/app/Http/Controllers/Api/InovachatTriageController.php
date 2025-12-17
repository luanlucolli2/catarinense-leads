<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateC6AuthorizationLinkJob;
use App\Models\InovachatTriage;
use App\Support\Cpf;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class InovachatTriageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'cpf'              => ['required', 'string', 'max:100'],

            // Em multi-conexões isso precisa ser obrigatório
            'connection_token' => ['required', 'string', 'max:255'],

            'phone'            => ['nullable', 'string', 'max:32'],
            'ticket_id'        => ['nullable', 'string', 'max:64'],
            'protocol'         => ['nullable', 'string', 'max:64'],
            'name'             => ['nullable', 'string', 'max:255'],
            'firstName'        => ['nullable', 'string', 'max:255'],
            'source'           => ['nullable', 'string', 'max:64'],
            'openTicket'       => ['nullable', 'string', 'max:16'],
            'queueId'          => ['nullable', 'string', 'max:32'],
        ]);

        $normalizedCpf = Cpf::normalize($data['cpf'] ?? null);
        if ($normalizedCpf === null || !Cpf::isValid($normalizedCpf)) {
            return response()->json([
                'error'   => 'cpf_invalid',
                'message' => 'CPF inválido. Revise o número informado.',
            ], 422);
        }

        $trackingId = (string) Str::uuid();

        $openTicket = (string) ($data['openTicket'] ?? '0');
        $queueId    = (string) ($data['queueId'] ?? '0');

        $phone = Phone::normalize($data['phone'] ?? null);

        InovachatTriage::create([
            'tracking_id'      => $trackingId,
            'cpf'              => $normalizedCpf,
            'connection_token' => $data['connection_token'],
            'phone'            => $phone,
            'ticket_id'        => $data['ticket_id'] ?? null,
            'protocol'         => $data['protocol'] ?? null,
            'name'             => $data['name'] ?? null,
            'first_name'       => $data['firstName'] ?? null,
            'source'           => $data['source'] ?? 'inovachat-flow',
            'status'           => 'started',
        ]);

        Log::info('Inovachat triage webhook received', [
            'tracking_id' => $trackingId,
            'cpf'         => $normalizedCpf,
            'phone'       => $phone,
            'ticket_id'   => $data['ticket_id'] ?? null,
            'openTicket'  => $openTicket,
            'queueId'     => $queueId,
            'ip'          => $request->ip(),
        ]);

        GenerateC6AuthorizationLinkJob::dispatch(
            trackingId: $trackingId,
            cpf: $normalizedCpf,
            firstName: $data['firstName'] ?? null,
            fullName: $data['name'] ?? null,
            phone: $phone,
            openTicket: $openTicket,
            queueId: $queueId,
            connectionToken: $data['connection_token'],
        );

        return response()->json([
            'tracking_id' => $trackingId,
            'cpf'         => $normalizedCpf,
        ]);
    }
}
