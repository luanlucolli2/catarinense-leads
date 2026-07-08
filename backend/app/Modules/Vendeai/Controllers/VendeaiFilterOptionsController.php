<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Support\VendeaiDateRange;
use App\Modules\Vendeai\Support\VendeaiLeadFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class VendeaiFilterOptionsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(VendeaiLeadFilters::rules(includeDirection: false));
        [$from, $to] = VendeaiDateRange::fromValidated($validated);

        return response()->json([
            'banks' => $this->banks($this->baseQuery($validated, $from, $to, ['bank'])),
            'stages' => $this->simpleDistinct($this->baseQuery($validated, $from, $to, ['stage']), 'vendeai_leads.stage'),
            'proposal_statuses' => $this->proposalStatuses($this->baseQuery($validated, $from, $to, ['proposal_status'])),
            'inbox_phone_numbers' => $this->simpleDistinct($this->baseQuery($validated, $from, $to, ['inbox_phone_number']), 'vendeai_leads.inbox_phone_number'),
            'tags' => $this->tags($this->baseQuery($validated, $from, $to, ['tags'])),
        ]);
    }

    private function baseQuery(array $filters, mixed $from, mixed $to, array $excludedKeys = [])
    {
        $baseQuery = DB::table('vendeai_leads');
        $leadPeriodColumn = VendeaiLeadFilters::leadPeriodColumn($filters);
        $appliedFilters = $this->filtersWithout($filters, $excludedKeys);
        $leadFilters = $appliedFilters;
        unset($leadFilters['newcorban_status']);

        VendeaiLeadFilters::applyFilters($baseQuery, $leadFilters, [
            'lead_alias' => 'vendeai_leads',
            'attempt_alias' => null,
            'date_column' => $leadPeriodColumn,
            'from' => $from,
            'to' => $to,
        ]);
        VendeaiLeadFilters::applyConversationScopedNewcorbanStatusFilter(
            $baseQuery,
            $appliedFilters['newcorban_status'] ?? null,
            'vendeai_leads',
            'vendeai_newcorban_proposal_attempts',
            $from,
            $to,
        );

        return $baseQuery;
    }

    private function filtersWithout(array $filters, array $excludedKeys): array
    {
        foreach ($excludedKeys as $key) {
            unset($filters[$key]);
        }

        return $filters;
    }

    private function banks($baseQuery): array
    {
        $expression = VendeaiLeadFilters::bankExpression('vendeai_leads');

        return (clone $baseQuery)
            ->selectRaw("{$expression} as value")
            ->whereRaw("{$expression} <> ''")
            ->groupByRaw($expression)
            ->orderBy('value')
            ->pluck('value')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    private function proposalStatuses($baseQuery): array
    {
        $statuses = (clone $baseQuery)
            ->select('vendeai_leads.proposal_status')
            ->whereNotNull('vendeai_leads.proposal_status')
            ->where('vendeai_leads.proposal_status', '<>', '')
            ->groupBy('vendeai_leads.proposal_status')
            ->orderBy('vendeai_leads.proposal_status')
            ->pluck('proposal_status')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();

        $hasNoProposal = (clone $baseQuery)
            ->where(function ($query) {
                $query->whereNull('vendeai_leads.proposal_id')->orWhere('vendeai_leads.proposal_id', '');
            })
            ->where(function ($query) {
                $query->whereNull('vendeai_leads.proposal_status')->orWhere('vendeai_leads.proposal_status', '');
            })
            ->exists();

        return $hasNoProposal
            ? array_merge([VendeaiLeadFilters::NO_PROPOSAL], $statuses)
            : $statuses;
    }

    private function tags($baseQuery): array
    {
        $tags = [];

        foreach ((clone $baseQuery)->select('vendeai_leads.tags')->cursor() as $row) {
            $values = $row->tags;

            if (is_string($values)) {
                $decoded = json_decode($values, true);
                $values = is_array($decoded) ? $decoded : [];
            }

            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $tag) {
                if (! is_string($tag)) {
                    continue;
                }

                $tag = trim($tag);
                if ($tag === '') {
                    continue;
                }

                $tags[$tag] = true;
            }
        }

        $values = array_keys($tags);
        sort($values, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($values);
    }

    private function simpleDistinct($baseQuery, string $column): array
    {
        return (clone $baseQuery)
            ->selectRaw("{$column} as value")
            ->whereNotNull($column)
            ->where($column, '<>', '')
            ->groupBy($column)
            ->orderBy($column)
            ->pluck('value')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }
}
