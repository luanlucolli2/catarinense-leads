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
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class InovachatTriageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $allowedTokens = (array) config('inovachat.connections.tokens', []);

        $data = $request->validate([
            'cpf'              => ['required', 'string', 'max:100'],
            'connection_token' => array_values(array_filter([
                'required',
                'string',
                'max:255',
                !empty($allowedTokens) ? Rule::in($allowedTokens) : null,
            ])),
            'phone'            => ['required', 'string', 'max:32'],
            'ticket_id'        => ['required', 'string', 'max:64'],
            'protocol'         => ['required', 'string', 'max:64'],
            'name'             => ['required', 'string', 'max:255'],
            'firstName'        => ['required', 'string', 'max:255'],
            'source'           => ['required', 'string', 'max:64'],
        ]);

        $normalizedCpf = Cpf::normalize($data['cpf']);
        if ($normalizedCpf === null || !Cpf::isValid($normalizedCpf)) {
            return response()->json([
                'error'   => 'cpf_invalid',
                'message' => 'CPF inválido. Revise o número informado.',
            ], 422);
        }

        $phone = Phone::normalize($data['phone']);
        if (! $phone) {
            return response()->json([
                'error'   => 'phone_invalid',
                'message' => 'Telefone inválido. Revise o número informado.',
            ], 422);
        }

        $trackingId = (string) Str::uuid();

        InovachatTriage::create([
            'tracking_id'      => $trackingId,
            'cpf'              => $normalizedCpf,
            'connection_token' => $data['connection_token'],
            'phone'            => $phone,
            'ticket_id'        => $data['ticket_id'],
            'protocol'         => $data['protocol'],
            'name'             => $data['name'],
            'first_name'       => $data['firstName'],
            'source'           => $data['source'],
            'status'           => 'started',
        ]);

        // Log detalhado só se habilitar
        if ((bool) config('inovachat.logging.verbose', false)) {
            Log::info('Inovachat triage webhook received', [
                'tracking_id'      => $trackingId,
                'cpf'              => $normalizedCpf,
                'phone'            => $phone,
                'ticket_id'        => $data['ticket_id'],
                'protocol'         => $data['protocol'],
                'connection_token' => $data['connection_token'],
                'ip'               => $request->ip(),
            ]);
        }

        GenerateC6AuthorizationLinkJob::dispatch(
            trackingId: $trackingId,
            cpf: $normalizedCpf,
            firstName: $data['firstName'],
            fullName: $data['name'],
            phone: $phone,
            openTicket: '0',
            queueId: '0',
            connectionToken: $data['connection_token'],
        );

        return response()->json([
            'tracking_id' => $trackingId,
            'cpf'         => $normalizedCpf,
        ]);
    }
}
