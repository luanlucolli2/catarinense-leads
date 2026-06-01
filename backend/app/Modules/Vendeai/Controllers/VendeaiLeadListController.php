<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Models\VendeaiLead;
use App\Modules\Vendeai\Models\VendeaiProposalCreatedWebhook;
use App\Modules\Vendeai\Support\VendeaiDateRange;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class VendeaiLeadListController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'view' => ['nullable', Rule::in(['summary'])],
            'sort' => ['nullable', Rule::in(['first_received_at', 'last_received_at', 'id'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        [$from, $to] = VendeaiDateRange::fromValidated($validated);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = (string) ($validated['sort'] ?? 'last_received_at');
        $direction = strtolower((string) ($validated['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if (($validated['view'] ?? null) === 'summary') {
            $summarySort = $sort === 'id'
                ? 'vendeai_newcorban_proposal_attempts.id'
                : 'vendeai_newcorban_proposal_attempts.received_at';

            return response()->json(
                VendeaiProposalCreatedWebhook::query()
                    ->join('vendeai_leads', 'vendeai_leads.id', '=', 'vendeai_newcorban_proposal_attempts.vendeai_lead_id')
                    ->whereNotNull('vendeai_leads.customer_cpf')
                    ->where('vendeai_leads.customer_cpf', '<>', '')
                    ->orderBy($summarySort, $direction)
                    ->select([
                        'vendeai_newcorban_proposal_attempts.id',
                        'vendeai_newcorban_proposal_attempts.newcorban_proposta_id',
                        'vendeai_newcorban_proposal_attempts.created_at',
                        'vendeai_leads.customer_cpf',
                        'vendeai_leads.customer_name',
                    ])
                    ->paginate($perPage)
                    ->through(fn (VendeaiProposalCreatedWebhook $webhook): array => [
                        'customer_cpf' => $webhook->customer_cpf,
                        'customer_name' => $webhook->customer_name,
                        'newcorban_proposta_id' => $webhook->newcorban_proposta_id,
                        'created_at' => $webhook->created_at?->toIso8601String(),
                    ])
            );
        }

        $query = VendeaiLead::query();

        if ($from !== null) {
            $query->where('first_received_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('first_received_at', '<=', $to);
        }

        $query->orderBy($sort, $direction)->orderBy('id', $direction);

        return response()->json($query->paginate($perPage));
    }
}
