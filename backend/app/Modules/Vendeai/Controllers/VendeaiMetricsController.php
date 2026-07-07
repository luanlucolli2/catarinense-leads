<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Support\VendeaiDateRange;
use App\Modules\Vendeai\Support\VendeaiLeadFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class VendeaiMetricsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(VendeaiLeadFilters::rules(includeDirection: false));

        [$from, $to] = VendeaiDateRange::fromValidated($validated);
        if (in_array(($validated['newcorban_filter'] ?? 'all'), ['sent', 'created'], true) && ! isset($validated['newcorban_status'])) {
            $validated['newcorban_status'] = 'sent';
        }
        $leadPeriodColumn = VendeaiLeadFilters::leadPeriodColumn($validated);
        $attemptLeadPeriodColumn = VendeaiLeadFilters::leadPeriodColumn($validated, 'leads');

        $latestAttempts = VendeaiLeadFilters::latestAttemptsSubquery();

        $leads = DB::table('vendeai_leads')
            ->leftJoinSub($latestAttempts, 'latest_attempts', function ($join) {
                $join->on('latest_attempts.vendeai_lead_id', '=', 'vendeai_leads.id');
            })
            ->leftJoin('vendeai_newcorban_proposal_attempts as attempts', 'attempts.id', '=', 'latest_attempts.id');
        $startedLeads = DB::table('vendeai_leads')
            ->leftJoinSub($latestAttempts, 'latest_attempts', function ($join) {
                $join->on('latest_attempts.vendeai_lead_id', '=', 'vendeai_leads.id');
            })
            ->leftJoin('vendeai_newcorban_proposal_attempts as attempts', 'attempts.id', '=', 'latest_attempts.id');
        $attempts = DB::table('vendeai_newcorban_proposal_attempts as attempts')
            ->leftJoin('vendeai_leads as leads', 'leads.id', '=', 'attempts.vendeai_lead_id');

        VendeaiLeadFilters::applyFilters($leads, $validated, [
            'lead_alias' => 'vendeai_leads',
            'attempt_alias' => 'attempts',
            'date_column' => $leadPeriodColumn,
            'from' => $from,
            'to' => $to,
        ]);
        VendeaiLeadFilters::applyFilters($startedLeads, $validated, [
            'lead_alias' => 'vendeai_leads',
            'attempt_alias' => 'attempts',
            'date_column' => 'vendeai_leads.first_received_at',
            'from' => $from,
            'to' => $to,
        ]);
        VendeaiLeadFilters::applyFilters($attempts, $validated, [
            'lead_alias' => 'leads',
            'attempt_alias' => 'attempts',
            'date_column' => 'attempts.received_at',
            'from' => $from,
            'to' => $to,
        ]);
        if ($from !== null) {
            $attempts->where($attemptLeadPeriodColumn, '>=', $from);
        }
        if ($to !== null) {
            $attempts->where($attemptLeadPeriodColumn, '<=', $to);
        }

        $attemptsTotal = (int) (clone $attempts)->count();
        $attemptsSuccess = (int) (clone $attempts)->whereNotNull('attempts.newcorban_proposta_id')->count();
        $attemptsPending = (int) (clone $attempts)->whereNull('attempts.newcorban_sent_at')->count();
        $attemptsFailed = (int) (clone $attempts)
            ->whereNull('attempts.newcorban_proposta_id')
            ->whereNotNull('attempts.newcorban_sent_at')
            ->count();

        return response()->json([
            'filters' => [
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
            ],
            'leads' => [
                'total' => (int) (clone $leads)->distinct('vendeai_leads.id')->count('vendeai_leads.id'),
                'started_total' => (int) (clone $startedLeads)->distinct('vendeai_leads.id')->count('vendeai_leads.id'),
                'offered_total' => $this->sumMoney($leads, "COALESCE(vendeai_leads.simulation_best_liquid_value, vendeai_leads.simulation_liquid_value)"),
                'typed_total' => $this->sumMoney($leads, 'vendeai_leads.proposal_liquid_value'),
                'paid_total' => $this->sumMoney(
                    $leads,
                    "CASE WHEN vendeai_leads.proposal_status = 'LIQUIDATED_TO_CUSTOMER' THEN vendeai_leads.proposal_liquid_value ELSE 0 END"
                ),
                'by_product' => $this->countsBy($leads, 'vendeai_leads.product_key', 'vendeai_leads.id'),
            ],
            'attempts' => [
                'conversations_total' => $this->distinctAttemptConversations($attempts),
                'total' => $attemptsTotal,
                'success' => $attemptsSuccess,
                'failed' => $attemptsFailed,
                'pending' => $attemptsPending,
                'success_rate' => $attemptsTotal > 0 ? round(($attemptsSuccess / $attemptsTotal) * 100, 2) : 0,
                'by_product' => $this->countsBy($attempts, 'leads.product_key'),
            ],
        ]);
    }

    private function countsBy(Builder $baseQuery, string $column, ?string $distinctColumn = null): array
    {
        $expression = "COALESCE(NULLIF(CAST({$column} AS CHAR), ''), 'sem_valor')";
        $aggregate = $distinctColumn === null ? 'COUNT(*)' : "COUNT(DISTINCT {$distinctColumn})";

        return (clone $baseQuery)
            ->selectRaw("{$expression} as label, {$aggregate} as total")
            ->groupByRaw($expression)
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => [
                'label' => (string) $row->label,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    private function distinctAttemptConversations(Builder $baseQuery): int
    {
        $accountId = "JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.chat_summary.account_id'))";
        $chatId = "JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.chat_summary.chat_id'))";
        $expression = "COALESCE(CAST(vendeai_lead_id AS CHAR), CASE WHEN {$accountId} IS NOT NULL AND {$chatId} IS NOT NULL THEN CONCAT({$accountId}, ':', {$chatId}) END)";

        return (int) ((clone $baseQuery)
            ->selectRaw("COUNT(DISTINCT {$expression}) as total")
            ->value('total') ?? 0);
    }

    private function sumMoney(Builder $baseQuery, string $expression): float
    {
        return round((float) ((clone $baseQuery)
            ->selectRaw("COALESCE(SUM({$expression}), 0) as total")
            ->value('total') ?? 0), 2);
    }
}
