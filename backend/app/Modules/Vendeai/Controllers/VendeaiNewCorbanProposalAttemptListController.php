<?php

namespace App\Modules\Vendeai\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Vendeai\Support\VendeaiDateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class VendeaiNewCorbanProposalAttemptListController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(['all', 'success', 'failed', 'pending'])],
            'sort' => ['nullable', Rule::in(['received_at', 'newcorban_sent_at', 'id'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        [$from, $to] = VendeaiDateRange::fromValidated($validated);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $sort = (string) ($validated['sort'] ?? 'received_at');
        $direction = strtolower((string) ($validated['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = DB::table('vendeai_newcorban_proposal_attempts as attempts')
            ->leftJoin('vendeai_leads as leads', 'leads.id', '=', 'attempts.vendeai_lead_id')
            ->select([
                'attempts.id',
                'attempts.vendeai_lead_id',
                'attempts.received_at',
                'attempts.newcorban_sent_at',
                'attempts.newcorban_response_status',
                'attempts.newcorban_proposta_id',
                'attempts.newcorban_cliente_id',
                'attempts.newcorban_error',
                'leads.account_id',
                'leads.chat_id',
                'leads.customer_cpf',
                'leads.customer_name',
                'leads.customer_birth_date',
                'leads.customer_phone',
                'leads.stage',
                'leads.proposal_id',
                'leads.proposal_bank',
                'leads.proposal_product',
                'leads.proposal_status',
                'leads.proposal_liquid_value',
            ]);

        $this->applyDateFilter($query, 'attempts.received_at', $from, $to);
        $this->applyStatusFilter($query, (string) ($validated['status'] ?? 'all'));

        $sortColumn = match ($sort) {
            'id' => 'attempts.id',
            'newcorban_sent_at' => 'attempts.newcorban_sent_at',
            default => 'attempts.received_at',
        };

        return response()->json(
            $query
                ->orderBy($sortColumn, $direction)
                ->orderBy('attempts.id', $direction)
                ->paginate($perPage)
                ->through(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'vendeai_lead_id' => $row->vendeai_lead_id === null ? null : (int) $row->vendeai_lead_id,
                    'received_at' => $this->iso($row->received_at),
                    'newcorban_sent_at' => $this->iso($row->newcorban_sent_at),
                    'status' => $this->attemptStatus($row),
                    'newcorban_response_status' => $row->newcorban_response_status === null ? null : (int) $row->newcorban_response_status,
                    'newcorban_proposta_id' => $row->newcorban_proposta_id,
                    'newcorban_cliente_id' => $row->newcorban_cliente_id,
                    'newcorban_error' => $row->newcorban_error,
                    'lead' => [
                        'account_id' => $row->account_id,
                        'chat_id' => $row->chat_id,
                        'customer_cpf' => $row->customer_cpf,
                        'customer_name' => $row->customer_name,
                        'customer_birth_date' => $this->date($row->customer_birth_date),
                        'customer_phone' => $row->customer_phone,
                        'stage' => $row->stage,
                    ],
                    'proposal' => [
                        'proposal_id' => $row->proposal_id,
                        'bank' => $row->proposal_bank,
                        'product' => $row->proposal_product,
                        'status' => $row->proposal_status,
                        'liquid_value' => $row->proposal_liquid_value,
                    ],
                ])
        );
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

    private function applyStatusFilter(Builder $query, string $status): void
    {
        match ($status) {
            'success' => $query->whereNotNull('attempts.newcorban_proposta_id'),
            'failed' => $query
                ->whereNull('attempts.newcorban_proposta_id')
                ->whereNotNull('attempts.newcorban_sent_at'),
            'pending' => $query->whereNull('attempts.newcorban_sent_at'),
            default => null,
        };
    }

    private function attemptStatus(object $row): string
    {
        if ($row->newcorban_proposta_id !== null) {
            return 'success';
        }

        if ($row->newcorban_sent_at === null) {
            return 'pending';
        }

        return 'failed';
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
