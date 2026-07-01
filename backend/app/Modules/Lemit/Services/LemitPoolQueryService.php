<?php

declare(strict_types=1);

namespace App\Modules\Lemit\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class LemitPoolQueryService
{
    /**
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    public function preview(array $filters): array
    {
        $baseQuery = $this->buildFilteredQuery($filters);

        return [
            'pool_size' => (int) (clone $baseQuery)->count('leads.id'),
            'pool_with_phones' => (int) $this->countByPhoneStatus(clone $baseQuery, true),
            'pool_without_phones' => (int) $this->countByPhoneStatus(clone $baseQuery, false),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters): int
    {
        return (int) $this->buildFilteredQuery($filters)->count('leads.id');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function sample(array $filters, int $quantity, ?int $poolSize = null): array
    {
        $query = $this->buildFilteredQuery($filters)
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

        usort($sample, fn(array $left, array $right): int => $left['lead_id'] <=> $right['lead_id']);

        return [
            'pool_size' => $poolSize ?? $seen,
            'sampled_quantity' => count($sample),
            'selected_banks' => $this->selectedBanks($filters),
            'bank_combination_mode' => (string) ($filters['bank_combination_mode'] ?? 'all'),
            'items' => array_values($sample),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildFilteredQuery(array $filters): Builder
    {
        $query = DB::table('leads');
        $selectedBanks = $this->selectedBanks($filters);

        if (in_array('clt', $selectedBanks, true)) {
            $query->leftJoin('clt_snapshots as cs', 'cs.cpf', '=', 'leads.cpf');
        }

        if (in_array('mercantil', $selectedBanks, true)) {
            $query->leftJoin('mercantil_snapshots as ms', 'ms.cpf', '=', 'leads.cpf');
        }

        if (in_array('uy3', $selectedBanks, true)) {
            $query->leftJoin('uy3_snapshots as us', 'us.cpf', '=', 'leads.cpf');
        }

        $this->applyPhoneStatusFilters($query, $filters);
        $this->applyBankConstraints($query, $filters, $selectedBanks);

        return $query;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, string> $selectedBanks
     */
    private function applyBankConstraints(Builder $query, array $filters, array $selectedBanks): void
    {
        if ($selectedBanks === []) {
            return;
        }

        $mode = (string) ($filters['bank_combination_mode'] ?? 'all');

        if ($mode === 'any') {
            $query->where(function (Builder $matchAny) use ($filters, $selectedBanks): void {
                foreach ($selectedBanks as $bank) {
                    $matchAny->orWhere(function (Builder $bankQuery) use ($bank, $filters): void {
                        $this->applySingleBankFilter($bankQuery, $bank, (array) ($filters[$bank] ?? []));
                    });
                }
            });

            return;
        }

        foreach ($selectedBanks as $bank) {
            $this->applySingleBankFilter($query, $bank, (array) ($filters[$bank] ?? []));
        }
    }

    /**
     * @param array<string, mixed> $bankFilters
     */
    private function applySingleBankFilter(Builder $query, string $bank, array $bankFilters): void
    {
        match ($bank) {
            'clt' => $this->applyCltFilters($query, $bankFilters),
            'mercantil' => $this->applyMercantilFilters($query, $bankFilters),
            'uy3' => $this->applyUy3Filters($query, $bankFilters),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyCltFilters(Builder $query, array $filters): void
    {
        $query->whereNotNull('cs.cpf');

        $situacao = $filters['clt_situacao'] ?? null;
        if ($situacao === 'aprovado') {
            $query->where('cs.not_found', 0)->where('cs.politica_credito_aprovado', 1);
        } elseif ($situacao === 'nao_aprovado') {
            $query->whereRaw('NOT (COALESCE(cs.not_found, 0) = 0 AND COALESCE(cs.politica_credito_aprovado, 0) = 1)');
        }

        $this->applyDateTimeRange(
            $query,
            'cs.consulted_at',
            $filters['clt_consulta_from'] ?? null,
            $filters['clt_consulta_to'] ?? null
        );

        $this->applyIntegerRange(
            $query,
            'cs.meses_admissao',
            $filters['clt_meses_admissao_min'] ?? null,
            $filters['clt_meses_admissao_max'] ?? null
        );

        $this->applyNumericRange(
            $query,
            'cs.margem_disponivel',
            $filters['clt_margem_min'] ?? null,
            $filters['clt_margem_max'] ?? null
        );

        $this->applyIntegerRange(
            $query,
            'cs.politica_credito_prazo_maximo_disponivel',
            $filters['clt_numero_parcelas_min'] ?? null,
            $filters['clt_numero_parcelas_max'] ?? null
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyMercantilFilters(Builder $query, array $filters): void
    {
        $query->whereNotNull('ms.cpf');

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
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyUy3Filters(Builder $query, array $filters): void
    {
        $query->whereNotNull('us.cpf');

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

        $this->applyIntegerExpressionRange(
            $query,
            $this->uy3MonthsExpression(),
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
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyPhoneStatusFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['with_phones'])) {
            $this->applyHasPhonesConstraint($query);
        }

        if (! empty($filters['without_phones'])) {
            $this->applyWithoutPhonesConstraint($query);
        }
    }

    private function applyHasPhonesConstraint(Builder $query): void
    {
        $query->where(function (Builder $phoneQuery): void {
            foreach ($this->phoneColumns() as $index => $phoneColumn) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $phoneQuery->{$method}(function (Builder $filledPhoneQuery) use ($phoneColumn): void {
                    $filledPhoneQuery
                        ->whereNotNull($phoneColumn)
                        ->whereRaw("TRIM({$phoneColumn}) <> ''");
                });
            }
        });
    }

    private function applyWithoutPhonesConstraint(Builder $query): void
    {
        foreach ($this->phoneColumns() as $phoneColumn) {
            $query->where(function (Builder $phoneQuery) use ($phoneColumn): void {
                $phoneQuery
                    ->whereNull($phoneColumn)
                    ->orWhereRaw("TRIM({$phoneColumn}) = ''");
            });
        }
    }

    private function countByPhoneStatus(Builder $query, bool $withPhones): int
    {
        if ($withPhones) {
            $this->applyHasPhonesConstraint($query);
        } else {
            $this->applyWithoutPhonesConstraint($query);
        }

        return (int) $query->count('leads.id');
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

    private function applyIntegerExpressionRange(Builder $query, string $expression, mixed $min, mixed $max): void
    {
        $parsedMin = $this->parseInteger($min);
        $parsedMax = $this->parseInteger($max);

        if ($parsedMin === null && $parsedMax === null) {
            return;
        }

        if ($parsedMin !== null) {
            $query->whereRaw("{$expression} >= ?", [$parsedMin]);
        }

        if ($parsedMax !== null) {
            $query->whereRaw("{$expression} <= ?", [$parsedMax]);
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

    /**
     * @return array<int, string>
     */
    private function phoneColumns(): array
    {
        return ['leads.fone1', 'leads.fone2', 'leads.fone3', 'leads.fone4'];
    }

    private function firstPhoneExpression(): string
    {
        return "COALESCE(NULLIF(TRIM(leads.fone1), ''), NULLIF(TRIM(leads.fone2), ''), NULLIF(TRIM(leads.fone3), ''), NULLIF(TRIM(leads.fone4), ''))";
    }

    private function uy3MonthsExpression(): string
    {
        $driver = DB::connection()->getDriverName();

        return match ($driver) {
            'sqlite' => <<<SQL
                (
                    (
                        (CAST(strftime('%Y', CURRENT_DATE) AS INTEGER) - CAST(strftime('%Y', us.data_admissao) AS INTEGER)) * 12
                    ) + (
                        CAST(strftime('%m', CURRENT_DATE) AS INTEGER) - CAST(strftime('%m', us.data_admissao) AS INTEGER)
                    ) - CASE
                        WHEN CAST(strftime('%d', CURRENT_DATE) AS INTEGER) < CAST(strftime('%d', us.data_admissao) AS INTEGER) THEN 1
                        ELSE 0
                    END
                )
            SQL,
            default => 'TIMESTAMPDIFF(MONTH, us.data_admissao, CURRENT_DATE)',
        };
    }
}
