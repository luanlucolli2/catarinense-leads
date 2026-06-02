<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Support\VendeaiDateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class VendeaiMetricsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product' => ['nullable', 'in:all,clt,fgts'],
        ]);

        [$from, $to] = VendeaiDateRange::fromValidated($validated);
        $product = (string) ($validated['product'] ?? 'all');

        $leads = DB::table('vendeai_leads');
        $attempts = DB::table('vendeai_newcorban_proposal_attempts as attempts')
            ->leftJoin('vendeai_leads as leads', 'leads.id', '=', 'attempts.vendeai_lead_id');

        $this->applyDateFilter($leads, 'first_received_at', $from, $to);
        $this->applyDateFilter($attempts, 'attempts.received_at', $from, $to);
        $this->applyProductFilter($leads, $product, 'product_key');
        $this->applyProductFilter($attempts, $product, 'leads.product_key');

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
                'total' => (int) (clone $leads)->count(),
                'offered_total' => $this->sumMoney($leads, "COALESCE(simulation_best_liquid_value, simulation_liquid_value)"),
                'typed_total' => $this->sumMoney($leads, 'proposal_liquid_value'),
                'paid_total' => $this->sumMoney(
                    $leads,
                    "CASE WHEN proposal_status = 'LIQUIDATED_TO_CUSTOMER' THEN proposal_liquid_value ELSE 0 END"
                ),
                'by_product' => $this->countsBy($leads, 'product_key'),
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

    private function applyDateFilter(Builder $query, string $column, ?Carbon $from, ?Carbon $to): void
    {
        if ($from !== null) {
            $query->where($column, '>=', $from);
        }

        if ($to !== null) {
            $query->where($column, '<=', $to);
        }
    }

    private function countsBy(Builder $baseQuery, string $column): array
    {
        $expression = "COALESCE(NULLIF(CAST({$column} AS CHAR), ''), 'sem_valor')";

        return (clone $baseQuery)
            ->selectRaw("{$expression} as label, COUNT(*) as total")
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

    private function applyProductFilter(Builder $query, string $product, string $column): void
    {
        if (! in_array($product, ['clt', 'fgts'], true)) {
            return;
        }

        $query->where($column, $product);
    }
}
