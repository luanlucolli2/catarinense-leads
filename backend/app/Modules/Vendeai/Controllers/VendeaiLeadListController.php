<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Models\VendeaiLead;
use App\Modules\Vendeai\Models\VendeaiProposalCreatedWebhook;
use App\Modules\Vendeai\Support\VendeaiAttemptPayload;
use App\Modules\Vendeai\Support\VendeaiDateRange;
use App\Modules\Vendeai\Support\VendeaiLeadFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class VendeaiLeadListController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(array_merge([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'view' => ['nullable', Rule::in(['summary'])],
            'sort' => ['nullable', Rule::in(['first_received_at', 'last_received_at', 'id'])],
        ], VendeaiLeadFilters::rules()));

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
                    ->tap(fn ($query) => VendeaiLeadFilters::applyFilters($query, $validated, [
                        'lead_alias' => 'vendeai_leads',
                        'attempt_alias' => 'vendeai_newcorban_proposal_attempts',
                        'date_column' => 'vendeai_leads.first_received_at',
                        'from' => $from,
                        'to' => $to,
                    ]))
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

        if (in_array(($validated['newcorban_filter'] ?? 'all'), ['sent', 'created'], true) && ! isset($validated['newcorban_status'])) {
            $validated['newcorban_status'] = 'sent';
        }

        $leadFilters = $validated;
        unset($leadFilters['newcorban_status']);

        VendeaiLeadFilters::applyFilters($query, $leadFilters, [
            'lead_alias' => 'vendeai_leads',
            'attempt_alias' => 'attempts',
            'date_column' => 'vendeai_leads.first_received_at',
            'from' => $from,
            'to' => $to,
        ]);

        self::applyLeadAttemptStatusFilter($query, $validated['newcorban_status'] ?? null);

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

        self::applyAttemptStatusFilter($attemptsQuery, $validated['newcorban_status'] ?? null);

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
            ])
            ->groupBy('vendeai_lead_id')
            ->map(fn ($items) => $items->map(function (VendeaiProposalCreatedWebhook $attempt) use ($attemptNumberMap): array {
                $proposal = VendeaiAttemptPayload::proposal($attempt->raw_payload);

                return [
                    'id' => (int) $attempt->id,
                    'received_at' => $attempt->received_at?->toIso8601String(),
                    'newcorban_sent_at' => $attempt->newcorban_sent_at?->toIso8601String(),
                    'newcorban_response_status' => $attempt->newcorban_response_status,
                    'newcorban_proposta_id' => $attempt->newcorban_proposta_id,
                    'newcorban_cliente_id' => $attempt->newcorban_cliente_id,
                    'newcorban_error' => $attempt->newcorban_error,
                    'original_number' => $attemptNumberMap[(int) $attempt->vendeai_lead_id][(int) $attempt->id] ?? null,
                    'status' => $attempt->newcorban_proposta_id !== null
                        ? 'success'
                        : ($attempt->newcorban_sent_at === null ? 'pending' : 'failed'),
                    'proposal' => [
                        ...$proposal,
                        'proposal_created_at' => $attempt->received_at?->toIso8601String(),
                        'proposal_status_updated_at' => null,
                    ],
                ];
            })->values()->all())
            ->all();

        $paginator->getCollection()->transform(function (object $lead) use ($attemptsByLead): object {
            $lead->newcorban_attempts = $attemptsByLead[(int) $lead->id] ?? [];

            return $lead;
        });

        return response()->json($paginator);
    }

    private static function applyAttemptStatusFilter($query, mixed $statuses): void
    {
        if (is_string($statuses) && $statuses !== '') {
            $statuses = [$statuses];
        }

        if (! is_array($statuses) || $statuses === []) {
            return;
        }

        $query->where(function ($inner) use ($statuses) {
            foreach (array_values($statuses) as $index => $status) {
                match ($status) {
                    'not_sent' => $index === 0
                        ? $inner->whereNull('newcorban_sent_at')
                        : $inner->orWhereNull('newcorban_sent_at'),
                    'success' => $index === 0
                        ? $inner->whereNotNull('newcorban_proposta_id')
                        : $inner->orWhereNotNull('newcorban_proposta_id'),
                    'failed' => $index === 0
                        ? $inner->where(function ($failed) {
                            $failed
                                ->whereNull('newcorban_proposta_id')
                                ->whereNotNull('newcorban_sent_at');
                        })
                        : $inner->orWhere(function ($failed) {
                            $failed
                                ->whereNull('newcorban_proposta_id')
                                ->whereNotNull('newcorban_sent_at');
                        }),
                    'sent' => $index === 0
                        ? $inner->whereNotNull('newcorban_sent_at')
                        : $inner->orWhereNotNull('newcorban_sent_at'),
                    default => null,
                };
            }
        });
    }

    private static function applyLeadAttemptStatusFilter($query, mixed $statuses): void
    {
        if (is_string($statuses) && $statuses !== '') {
            $statuses = [$statuses];
        }

        if (! is_array($statuses) || $statuses === []) {
            return;
        }

        $query->where(function ($outer) use ($statuses) {
            foreach (array_values($statuses) as $index => $status) {
                $method = $index === 0 ? 'where' : 'orWhere';

                match ($status) {
                    'not_sent' => $outer->{$method}(function ($notSent) {
                        $notSent->whereNotExists(function ($exists) {
                            $exists
                                ->selectRaw('1')
                                ->from('vendeai_newcorban_proposal_attempts')
                                ->whereColumn('vendeai_newcorban_proposal_attempts.vendeai_lead_id', 'vendeai_leads.id')
                                ->whereNotNull('vendeai_newcorban_proposal_attempts.newcorban_sent_at');
                        });
                    }),
                    'success' => $outer->{$method}(function ($success) {
                        $success->whereExists(function ($exists) {
                            $exists
                                ->selectRaw('1')
                                ->from('vendeai_newcorban_proposal_attempts')
                                ->whereColumn('vendeai_newcorban_proposal_attempts.vendeai_lead_id', 'vendeai_leads.id')
                                ->whereNotNull('vendeai_newcorban_proposal_attempts.newcorban_proposta_id');
                        });
                    }),
                    'failed' => $outer->{$method}(function ($failed) {
                        $failed->whereExists(function ($exists) {
                            $exists
                                ->selectRaw('1')
                                ->from('vendeai_newcorban_proposal_attempts')
                                ->whereColumn('vendeai_newcorban_proposal_attempts.vendeai_lead_id', 'vendeai_leads.id')
                                ->whereNull('vendeai_newcorban_proposal_attempts.newcorban_proposta_id')
                                ->whereNotNull('vendeai_newcorban_proposal_attempts.newcorban_sent_at');
                        });
                    }),
                    'sent' => $outer->{$method}(function ($sent) {
                        $sent->whereExists(function ($exists) {
                            $exists
                                ->selectRaw('1')
                                ->from('vendeai_newcorban_proposal_attempts')
                                ->whereColumn('vendeai_newcorban_proposal_attempts.vendeai_lead_id', 'vendeai_leads.id')
                                ->whereNotNull('vendeai_newcorban_proposal_attempts.newcorban_sent_at');
                        });
                    }),
                    default => null,
                };
            }
        });
    }
}
