<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Models\VendeaiLead;
use App\Modules\Vendeai\Models\VendeaiProposalCreatedWebhook;
use App\Modules\Vendeai\Support\VendeaiAttemptPayload;
use App\Modules\Vendeai\Support\VendeaiDateRange;
use App\Modules\Vendeai\Support\VendeaiLeadFilters;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class VendeaiLeadListController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(array_merge([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'sort' => ['nullable', Rule::in(['first_received_at', 'last_received_at', 'id'])],
        ], VendeaiLeadFilters::rules()));

        [$from, $to] = VendeaiDateRange::fromValidated($validated);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = (string) ($validated['sort'] ?? 'last_received_at');
        $direction = strtolower((string) ($validated['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $leadPeriodColumn = VendeaiLeadFilters::leadPeriodColumn($validated);

        $latestAttempts = VendeaiLeadFilters::latestAttemptsSubquery();

        $query = VendeaiLead::query()
            ->leftJoinSub($latestAttempts, 'latest_attempts', function ($join) {
                $join->on('latest_attempts.vendeai_lead_id', '=', 'vendeai_leads.id');
            })
            ->leftJoin('vendeai_newcorban_proposal_attempts as attempts', 'attempts.id', '=', 'latest_attempts.id')
            ->select([
                'vendeai_leads.*',
                'attempts.newcorban_proposta_id',
                'attempts.newcorban_error',
                'attempts.newcorban_sent_at',
            ]);

        $leadFilters = $validated;
        unset($leadFilters['newcorban_status']);

        VendeaiLeadFilters::applyFilters($query, $leadFilters, [
            'lead_alias' => 'vendeai_leads',
            'attempt_alias' => 'attempts',
            'date_column' => $leadPeriodColumn,
            'from' => $from,
            'to' => $to,
        ]);

        VendeaiLeadFilters::applyConversationScopedNewcorbanStatusFilter(
            $query,
            $validated['newcorban_status'] ?? null,
            'vendeai_leads',
            'vendeai_newcorban_proposal_attempts',
            $from,
            $to,
        );

        $query->orderBy("vendeai_leads.{$sort}", $direction)->orderBy('vendeai_leads.id', $direction);

        $paginator = $query->paginate($perPage);
        $leadIds = collect($paginator->items())
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        $attemptNumberMap = VendeaiProposalCreatedWebhook::query()
            ->whereIn('vendeai_lead_id', $leadIds)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get(['id', 'vendeai_lead_id', 'received_at'])
            ->groupBy('vendeai_lead_id')
            ->map(fn ($items) => $items
                ->sortBy([
                    ['received_at', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->mapWithKeys(fn ($attempt, $index) => [(int) $attempt->id => $index + 1])
                ->all()
            )
            ->all();

        $attemptsQuery = VendeaiProposalCreatedWebhook::query()
            ->whereIn('vendeai_lead_id', $leadIds)
            ->orderByDesc('received_at')
            ->orderByDesc('id');

        $attemptsByLead = $attemptsQuery
            ->get([
                'id',
                'vendeai_lead_id',
                'received_at',
                'newcorban_sent_at',
                'newcorban_response_status',
                'newcorban_proposta_id',
                'newcorban_cliente_id',
                'newcorban_error',
                'raw_payload',
                'newcorban_request_payload',
                'newcorban_response_body',
            ])
            ->groupBy('vendeai_lead_id')
            ->map(fn ($items) => $items->map(function (VendeaiProposalCreatedWebhook $attempt) use ($attemptNumberMap, $from, $to, $validated): array {
                $proposal = VendeaiAttemptPayload::proposal($attempt->raw_payload);
                $status = VendeaiLeadFilters::attemptStatus($attempt->newcorban_proposta_id, $attempt->newcorban_sent_at);
                $isInFilteredPeriod = VendeaiLeadFilters::attemptIsInFilteredPeriod($attempt->received_at, $from, $to);
                $matchesNewcorbanScope = VendeaiLeadFilters::attemptMatchesNewcorbanScope(
                    $attempt->received_at,
                    $attempt->newcorban_proposta_id,
                    $attempt->newcorban_sent_at,
                    $validated['newcorban_status'] ?? null,
                    $from,
                    $to,
                );

                return [
                    'id' => (int) $attempt->id,
                    'received_at' => $attempt->received_at?->toIso8601String(),
                    'newcorban_sent_at' => $attempt->newcorban_sent_at?->toIso8601String(),
                    'newcorban_response_status' => $attempt->newcorban_response_status,
                    'newcorban_proposta_id' => $attempt->newcorban_proposta_id,
                    'newcorban_cliente_id' => $attempt->newcorban_cliente_id,
                    'newcorban_error' => $attempt->newcorban_error,
                    'original_number' => $attemptNumberMap[(int) $attempt->vendeai_lead_id][(int) $attempt->id] ?? null,
                    'status' => $status,
                    'is_in_filtered_period' => $isInFilteredPeriod,
                    'matches_newcorban_scope' => $matchesNewcorbanScope,
                    'proposal' => [
                        ...$proposal,
                        'proposal_created_at' => $attempt->received_at?->toIso8601String(),
                        'proposal_status_updated_at' => null,
                    ],
                ];
            })->values()->all())
            ->all();

        $paginator->getCollection()->transform(function (object $lead) use ($attemptsByLead): object {
            $allAttempts = $attemptsByLead[(int) $lead->id] ?? [];
            $lead->newcorban_attempts = $allAttempts;

            $outsideScopeAttempts = array_values(array_filter(
                $allAttempts,
                fn (array $attempt): bool => ! ($attempt['matches_newcorban_scope'] ?? false)
            ));

            $lead->newcorban_attempts_out_of_period_count = count($outsideScopeAttempts);
            $lead->newcorban_attempts_out_of_period_received_at = count($outsideScopeAttempts) === 1
                ? ($outsideScopeAttempts[0]['received_at'] ?? null)
                : null;

            return $lead;
        });

        return response()->json($paginator);
    }
}
