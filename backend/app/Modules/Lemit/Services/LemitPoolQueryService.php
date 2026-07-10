<?php

declare(strict_types=1);

namespace App\Modules\Lemit\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LemitPoolQueryService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    public function preview(array $filters): array
    {
        $row = DB::query()
            ->fromSub(
                $this->buildFilteredLeadQuery($filters)->select([
                    'leads.id',
                    'leads.has_phone',
                ]),
                'pool'
            )
            ->selectRaw('COUNT(*) as pool_size')
            ->selectRaw('SUM(CASE WHEN pool.has_phone = 1 THEN 1 ELSE 0 END) as pool_with_phones')
            ->selectRaw('SUM(CASE WHEN pool.has_phone = 0 THEN 1 ELSE 0 END) as pool_without_phones')
            ->first();

        return [
            'pool_size' => (int) ($row->pool_size ?? 0),
            'pool_with_phones' => (int) ($row->pool_with_phones ?? 0),
            'pool_without_phones' => (int) ($row->pool_without_phones ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function sample(array $filters, int $quantity): array
    {
        $query = $this->buildFilteredLeadQuery($filters)
            ->select([
                'leads.id as lead_id',
                'leads.cpf',
                'leads.nome',
                DB::raw($this->firstPhoneExpression() . ' as telefone_atual_antes'),
            ])
            ->orderBy('leads.id');

        $sample = [];
        $seen = 0;

        foreach ($query->cursor() as $row) {
            $seen++;

            $item = [
                'lead_id' => (int) $row->lead_id,
                'cpf' => (string) $row->cpf,
                'nome' => $row->nome !== null ? (string) $row->nome : null,
                'telefone_atual_antes' => $row->telefone_atual_antes !== null
                    ? (string) $row->telefone_atual_antes
                    : null,
            ];

            if ($seen <= $quantity) {
                $sample[] = $item;
                continue;
            }

            $index = random_int(1, $seen);
            if ($index <= $quantity) {
                $sample[$index - 1] = $item;
            }
        }

        if ($seen < $quantity) {
            throw ValidationException::withMessages([
                'quantity' => ['A quantidade solicitada excede a base filtrada atual.'],
            ]);
        }

        usort($sample, fn(array $left, array $right): int => $left['lead_id'] <=> $right['lead_id']);

        return [
            'pool_size' => $seen,
            'sampled_quantity' => count($sample),
            'selected_banks' => $this->selectedBanks($filters),
            'bank_combination_mode' => (string) ($filters['bank_combination_mode'] ?? 'all'),
            'items' => array_values($sample),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildFilteredLeadQuery(array $filters): Builder
    {
        $selectedBanks = $this->selectedBanks($filters);

        $query = DB::table('leads');

        if ($selectedBanks !== []) {
            $query->joinSub(
                $this->buildCandidateCpfQuery($filters, $selectedBanks),
                'candidate_cpfs',
                'candidate_cpfs.cpf',
                '=',
                'leads.cpf'
            );
        }

        $this->applyPhoneStatusFilters($query, $filters);

        return $query;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, string> $selectedBanks
     */
    private function buildCandidateCpfQuery(array $filters, array $selectedBanks): Builder
    {
        $mode = (string) ($filters['bank_combination_mode'] ?? 'all');

        return $mode === 'any'
            ? $this->buildAnyBankCandidateCpfQuery($filters, $selectedBanks)
            : $this->buildAllBankCandidateCpfQuery($filters, $selectedBanks);
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, string> $selectedBanks
     */
    private function buildAllBankCandidateCpfQuery(array $filters, array $selectedBanks): Builder
    {
        $firstBank = array_shift($selectedBanks);
        $firstQuery = $this->buildSingleBankCandidateQuery(
            (string) $firstBank,
            (array) ($filters[(string) $firstBank] ?? [])
        );

        $query = DB::query()
            ->fromSub($firstQuery, 'bank_0')
            ->select('bank_0.cpf');

        foreach (array_values($selectedBanks) as $index => $bank) {
            $alias = 'bank_' . ($index + 1);
            $query->joinSub(
                $this->buildSingleBankCandidateQuery($bank, (array) ($filters[$bank] ?? [])),
                $alias,
                "{$alias}.cpf",
                '=',
                'bank_0.cpf'
            );
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, string> $selectedBanks
     */
    private function buildAnyBankCandidateCpfQuery(array $filters, array $selectedBanks): Builder
    {
        $firstBank = array_shift($selectedBanks);
        $unionQuery = $this->buildSingleBankCandidateQuery(
            (string) $firstBank,
            (array) ($filters[(string) $firstBank] ?? [])
        );

        foreach ($selectedBanks as $bank) {
            $unionQuery->union(
                $this->buildSingleBankCandidateQuery($bank, (array) ($filters[$bank] ?? []))
            );
        }

        return DB::query()
            ->fromSub($unionQuery, 'bank_union')
            ->select('bank_union.cpf')
            ->distinct();
    }

    /**
     * @param array<string, mixed> $bankFilters
     */
    private function buildSingleBankCandidateQuery(string $bank, array $bankFilters): Builder
    {
        return match ($bank) {
            'facta' => $this->buildFactaCandidateQuery($bankFilters),
            'mercantil' => $this->buildMercantilCandidateQuery($bankFilters),
            'uy3' => $this->buildUy3CandidateQuery($bankFilters),
            default => DB::query()->fromSub(
                DB::table('leads')->selectRaw('NULL as cpf')->whereRaw('1 = 0'),
                'empty_pool'
            )->select('empty_pool.cpf'),
        };
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildFactaCandidateQuery(array $filters): Builder
    {
        $query = DB::table('facta_clt_snapshots as cs')->select('cs.cpf');

        $situacao = $filters['facta_situacao'] ?? null;
        if ($situacao === 'aprovado') {
            $query->where('cs.not_found', 0)->where('cs.politica_credito_aprovado', 1);
        } elseif ($situacao === 'nao_aprovado') {
            $query->where(function (Builder $situationQuery): void {
                $situationQuery
                    ->where('cs.not_found', 1)
                    ->orWhereNull('cs.not_found')
                    ->orWhere('cs.politica_credito_aprovado', 0)
                    ->orWhereNull('cs.politica_credito_aprovado');
            });
        }

        $this->applyDateTimeRange(
            $query,
            'cs.consulted_at',
            $filters['facta_consulta_from'] ?? null,
            $filters['facta_consulta_to'] ?? null
        );

        $this->applyIntegerRange(
            $query,
            'cs.meses_admissao',
            $filters['facta_meses_admissao_min'] ?? null,
            $filters['facta_meses_admissao_max'] ?? null
        );

        $this->applyNumericRange(
            $query,
            'cs.margem_disponivel',
            $filters['facta_margem_min'] ?? null,
            $filters['facta_margem_max'] ?? null
        );

        $this->applyIntegerRange(
            $query,
            'cs.politica_credito_prazo_maximo_disponivel',
            $filters['facta_numero_parcelas_min'] ?? null,
            $filters['facta_numero_parcelas_max'] ?? null
        );

        return $query;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildMercantilCandidateQuery(array $filters): Builder
    {
        $query = DB::table('mercantil_snapshots as ms')->select('ms.cpf');

        $situacao = $filters['mercantil_situacao'] ?? null;
        if ($situacao === 'aprovado') {
            $query->where('ms.status', 'SUCESSO');
        } elseif ($situacao === 'nao_aprovado') {
            $query->where(function (Builder $statusQuery): void {
                $statusQuery->whereNull('ms.status')->orWhere('ms.status', '!=', 'SUCESSO');
            });
        }

        $this->applyDateTimeRange(
            $query,
            'ms.data_hora_origem',
            $filters['mercantil_consulta_from'] ?? null,
            $filters['mercantil_consulta_to'] ?? null
        );

        $this->applyNumericRange(
            $query,
            'ms.valor_parcela',
            $filters['mercantil_valor_parcela_min'] ?? null,
            $filters['mercantil_valor_parcela_max'] ?? null
        );

        $this->applyIntegerRange(
            $query,
            'ms.quantidade_parcelas',
            $filters['mercantil_numero_parcelas_min'] ?? null,
            $filters['mercantil_numero_parcelas_max'] ?? null
        );

        return $query;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildUy3CandidateQuery(array $filters): Builder
    {
        $query = DB::table('uy3_snapshots as us')->select('us.cpf');

        $situacao = $filters['uy3_situacao'] ?? null;
        if ($situacao === 'aprovado') {
            $query->where('us.elegivel_emprestimo', 1);
        } elseif ($situacao === 'nao_aprovado') {
            $query->where(function (Builder $eligibilityQuery): void {
                $eligibilityQuery
                    ->whereNull('us.elegivel_emprestimo')
                    ->orWhere('us.elegivel_emprestimo', '!=', 1);
            });
        }

        $this->applyDateTimeRange(
            $query,
            'us.updated_at',
            $filters['uy3_consulta_from'] ?? null,
            $filters['uy3_consulta_to'] ?? null
        );

        $this->applyUy3MonthsAdmissionRange(
            $query,
            $filters['uy3_meses_admissao_min'] ?? null,
            $filters['uy3_meses_admissao_max'] ?? null
        );

        $this->applyNumericRange(
            $query,
            'us.margem_disponivel',
            $filters['uy3_margem_min'] ?? null,
            $filters['uy3_margem_max'] ?? null
        );

        $this->applyNumericRange(
            $query,
            'us.valor_liberado',
            $filters['uy3_valor_liberado_min'] ?? null,
            $filters['uy3_valor_liberado_max'] ?? null
        );

        $this->applyIntegerRange(
            $query,
            'us.numero_parcelas',
            $filters['uy3_numero_parcelas_min'] ?? null,
            $filters['uy3_numero_parcelas_max'] ?? null
        );

        return $query;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyPhoneStatusFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['with_phones'])) {
            $query->where('leads.has_phone', 1);
        }

        if (! empty($filters['without_phones'])) {
            $query->where('leads.has_phone', 0);
        }
    }

    private function applyDateTimeRange(Builder $query, string $column, mixed $from, mixed $to): void
    {
        if ($from === null && $to === null) {
            return;
        }

        $start = $from ? (string) $from . ' 00:00:00' : '1900-01-01 00:00:00';
        $end = $to ? (string) $to . ' 23:59:59' : now()->toDateString() . ' 23:59:59';

        $query->whereBetween($column, [$start, $end]);
    }

    private function applyIntegerRange(Builder $query, string $column, mixed $min, mixed $max): void
    {
        $parsedMin = $this->parseInteger($min);
        $parsedMax = $this->parseInteger($max);

        if ($parsedMin === null && $parsedMax === null) {
            return;
        }

        if ($parsedMin !== null) {
            $query->where($column, '>=', $parsedMin);
        }

        if ($parsedMax !== null) {
            $query->where($column, '<=', $parsedMax);
        }
    }

    private function applyNumericRange(Builder $query, string $column, mixed $min, mixed $max): void
    {
        $parsedMin = $this->parseDecimal($min);
        $parsedMax = $this->parseDecimal($max);

        if ($parsedMin === null && $parsedMax === null) {
            return;
        }

        if ($parsedMin !== null) {
            $query->where($column, '>=', $parsedMin);
        }

        if ($parsedMax !== null) {
            $query->where($column, '<=', $parsedMax);
        }
    }

    private function applyUy3MonthsAdmissionRange(Builder $query, mixed $min, mixed $max): void
    {
        $parsedMin = $this->parseInteger($min);
        $parsedMax = $this->parseInteger($max);

        if ($parsedMin === null && $parsedMax === null) {
            return;
        }

        $today = Carbon::today();

        if ($parsedMin !== null) {
            $query->where('us.data_admissao', '<=', $today->copy()->subMonthsNoOverflow($parsedMin)->toDateString());
        }

        if ($parsedMax !== null) {
            $query->where('us.data_admissao', '>', $today->copy()->subMonthsNoOverflow($parsedMax + 1)->toDateString());
        }
    }

    private function parseInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
    }

    private function parseDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, string>
     */
    private function selectedBanks(array $filters): array
    {
        $selected = $filters['selected_banks'] ?? [];
        if (! is_array($selected)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $selected)));
    }

    private function firstPhoneExpression(): string
    {
        return "COALESCE(NULLIF(TRIM(leads.fone1), ''), NULLIF(TRIM(leads.fone2), ''), NULLIF(TRIM(leads.fone3), ''), NULLIF(TRIM(leads.fone4), ''))";
    }
}
