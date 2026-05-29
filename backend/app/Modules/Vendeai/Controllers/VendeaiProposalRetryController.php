<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Models\VendeaiLead;
use App\Modules\Vendeai\Models\VendeaiProposalCreatedWebhook;
use App\Modules\Vendeai\Services\NewCorbanProposalService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VendeaiProposalRetryController extends Controller
{
    public function __construct(private readonly NewCorbanProposalService $newCorbanProposalService)
    {
    }

    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'account_id' => ['required', 'string', 'max:50'],
            'chat_id' => ['required', 'string', 'max:100'],
        ]);

        $lead = VendeaiLead::query()
            ->where('account_id', $validated['account_id'])
            ->where('chat_id', $validated['chat_id'])
            ->first();

        if ($lead === null) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'VendeAI lead not found.',
            ], 404);
        }

        $webhook = VendeaiProposalCreatedWebhook::query()
            ->where('vendeai_lead_id', $lead->id)
            ->latest('received_at')
            ->first();

        if ($webhook === null) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Proposal created webhook not found.',
            ], 404);
        }

        if ($webhook->newcorban_proposta_id !== null) {
            return response()->json([
                'error' => 'already_created',
                'message' => 'New Corban proposal already exists.',
                'newcorban_proposta_id' => $webhook->newcorban_proposta_id,
                'newcorban_cliente_id' => $webhook->newcorban_cliente_id,
            ], 409);
        }

        $payload = $webhook->raw_payload;

        if (! is_array($payload)) {
            return response()->json([
                'error' => 'invalid_payload',
                'message' => 'Stored webhook payload is invalid.',
            ], 422);
        }

        $this->newCorbanProposalService->sendProposalCreated($webhook, $payload);
        $webhook->refresh();

        return response()->json([
            'ok' => true,
            'id' => $webhook->id,
            'newcorban_response_status' => $webhook->newcorban_response_status,
            'newcorban_response_body' => $webhook->newcorban_response_body,
            'newcorban_proposta_id' => $webhook->newcorban_proposta_id,
            'newcorban_cliente_id' => $webhook->newcorban_cliente_id,
            'newcorban_sent_at' => $webhook->newcorban_sent_at?->toIso8601String(),
            'newcorban_error' => $webhook->newcorban_error,
        ]);
    }
}
