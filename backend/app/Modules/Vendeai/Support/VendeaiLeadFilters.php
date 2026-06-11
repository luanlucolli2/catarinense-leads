<?php

namespace App\Modules\Vendeai\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class VendeaiLeadFilters
{
    public const NO_PROPOSAL = 'no_proposal';

    public static function rules(bool $includeDirection = true, bool $includeAttemptStatus = false): array
    {
        $rules = [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product' => ['nullable', Rule::in(['all', 'clt', 'fgts'])],
            'search' => ['nullable', 'string', 'max:255'],
            'bank' => ['nullable', 'string', 'max:80'],
            'stage' => ['nullable', 'string', 'max:80'],
            'proposal_status' => ['nullable', 'string', 'max:120'],
            'newcorban_status' => ['nullable', Rule::in(['all', 'not_sent', 'success', 'failed', 'sent'])],
            'newcorban_filter' => ['nullable', Rule::in(['all', 'sent', 'created'])],
            'inbox_phone_number' => ['nullable', 'string', 'max:30'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
        ];

        if ($includeDirection) {
            $rules['direction'] = ['nullable', Rule::in(['asc', 'desc'])];
        }

        if ($includeAttemptStatus) {
            $rules['status'] = ['nullable', Rule::in(['all', 'success', 'failed', 'pending'])];
        }

        return $rules;
    }

    public static function latestAttemptsSubquery(): QueryBuilder
    {
        return DB::table('vendeai_newcorban_proposal_attempts')
            ->selectRaw('MAX(id) as id, vendeai_lead_id')
            ->whereNotNull('vendeai_lead_id')
            ->groupBy('vendeai_lead_id');
    }

    public static function applyFilters(EloquentBuilder|QueryBuilder $query, array $filters, array $config = []): void
    {
        if (! isset($filters['newcorban_status']) && in_array(($filters['newcorban_filter'] ?? 'all'), ['sent', 'created'], true)) {
            $filters['newcorban_status'] = 'sent';
        }

        $leadAlias = $config['lead_alias'] ?? 'vendeai_leads';
        $attemptAlias = $config['attempt_alias'] ?? 'attempts';
        $dateColumn = $config['date_column'] ?? null;
        $from = $config['from'] ?? null;
        $to = $config['to'] ?? null;

        if ($dateColumn !== null) {
            self::applyDateFilter($query, $dateColumn, $from, $to);
        }

        self::applyProductFilter($query, (string) ($filters['product'] ?? 'all'), "{$leadAlias}.product_key");
        self::applySearchFilter($query, self::stringValue($filters['search'] ?? null), $leadAlias, $attemptAlias);
        self::applyBankFilter($query, self::stringValue($filters['bank'] ?? null), $leadAlias);
        self::applyStageFilter($query, self::stringValue($filters['stage'] ?? null), "{$leadAlias}.stage");
        self::applyProposalStatusFilter($query, self::stringValue($filters['proposal_status'] ?? null), $leadAlias);
        self::applyNewcorbanStatusFilter(
            $query,
            self::stringValue($filters['newcorban_status'] ?? null),
            $attemptAlias
        );
        self::applyInboxPhoneNumberFilter(
            $query,
            self::stringValue($filters['inbox_phone_number'] ?? null),
            "{$leadAlias}.inbox_phone_number"
        );
        self::applyTagsFilter($query, self::tagValues($filters['tags'] ?? null), "{$leadAlias}.tags");
    }

    public static function normalizeBankValue(?string $value): ?string
    {
        $normalized = self::stringValue($value);

        if ($normalized === null || $normalized === 'all') {
            return null;
        }

        $normalized = mb_strtolower($normalized);
        $normalized = str_replace([' ', 'ç'], ['_', 'c'], $normalized);

        if ($normalized === 'mercantil_api') {
            return 'mercantil';
        }

        return $normalized;
    }

    public static function normalizeDigits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits === '' ? null : $digits;
    }

    public static function bankExpression(string $leadAlias = 'vendeai_leads'): string
    {
        $base = "COALESCE(NULLIF({$leadAlias}.proposal_bank, ''), NULLIF({$leadAlias}.simulation_bank, ''))";
        $normalized = "LOWER(REPLACE(REPLACE(TRIM(COALESCE({$base}, '')), ' ', '_'), 'ç', 'c'))";

        return "CASE WHEN {$normalized} = 'mercantil_api' THEN 'mercantil' ELSE {$normalized} END";
    }

    public static function digitsExpression(string $column): string
    {
        return "REGEXP_REPLACE(COALESCE({$column}, ''), '[^0-9]', '')";
    }

    private static function applyDateFilter(EloquentBuilder|QueryBuilder $query, string $column, mixed $from, mixed $to): void
    {
        if ($from !== null) {
            $query->where($column, '>=', $from);
        }

        if ($to !== null) {
            $query->where($column, '<=', $to);
        }
    }

    private static function applyProductFilter(EloquentBuilder|QueryBuilder $query, string $product, string $column): void
    {
        if (! in_array($product, ['clt', 'fgts'], true)) {
            return;
        }

        $query->where($column, $product);
    }

    private static function applySearchFilter(
        EloquentBuilder|QueryBuilder $query,
        ?string $search,
        string $leadAlias,
        ?string $attemptAlias,
    ): void {
        if ($search === null) {
            return;
        }

        $digits = self::normalizeDigits($search);
        $like = '%' . $search . '%';

        $query->where(function ($inner) use ($attemptAlias, $digits, $leadAlias, $like) {
            $inner
                ->where("{$leadAlias}.customer_name", 'like', $like)
                ->orWhere("{$leadAlias}.chat_id", 'like', $like)
                ->orWhere("{$leadAlias}.account_id", 'like', $like)
                ->orWhere("{$leadAlias}.proposal_id", 'like', $like)
                ->orWhere("{$leadAlias}.proposal_number", 'like', $like);

            if ($attemptAlias !== null) {
                $inner->orWhere("{$attemptAlias}.newcorban_proposta_id", 'like', $like);
            }

            if ($digits !== null) {
                $digitsLike = '%' . $digits . '%';

                $inner
                    ->orWhereRaw(self::digitsExpression("{$leadAlias}.customer_cpf") . ' like ?', [$digitsLike])
                    ->orWhereRaw(self::digitsExpression("{$leadAlias}.customer_phone") . ' like ?', [$digitsLike])
                    ->orWhereRaw(self::digitsExpression("{$leadAlias}.inbox_phone_number") . ' like ?', [$digitsLike])
                    ->orWhere("{$leadAlias}.chat_id", 'like', $digitsLike)
                    ->orWhere("{$leadAlias}.account_id", 'like', $digitsLike)
                    ->orWhere("{$leadAlias}.proposal_id", 'like', $digitsLike)
                    ->orWhere("{$leadAlias}.proposal_number", 'like', $digitsLike);

                if ($attemptAlias !== null) {
                    $inner->orWhere("{$attemptAlias}.newcorban_proposta_id", 'like', $digitsLike);
                }
            }
        });
    }

    private static function applyBankFilter(EloquentBuilder|QueryBuilder $query, ?string $bank, string $leadAlias): void
    {
        $normalizedBank = self::normalizeBankValue($bank);

        if ($normalizedBank === null) {
            return;
        }

        $query->whereRaw(self::bankExpression($leadAlias) . ' = ?', [$normalizedBank]);
    }

    private static function applyStageFilter(EloquentBuilder|QueryBuilder $query, ?string $stage, string $column): void
    {
        $normalizedStage = self::stringValue($stage);

        if ($normalizedStage === null || $normalizedStage === 'all') {
            return;
        }

        $query->whereRaw("LOWER(TRIM(COALESCE({$column}, ''))) = ?", [mb_strtolower($normalizedStage)]);
    }

    private static function applyProposalStatusFilter(EloquentBuilder|QueryBuilder $query, ?string $proposalStatus, string $leadAlias): void
    {
        $proposalStatus = self::stringValue($proposalStatus);

        if ($proposalStatus === null || $proposalStatus === 'all') {
            return;
        }

        if ($proposalStatus === self::NO_PROPOSAL) {
            $query
                ->where(function ($inner) use ($leadAlias) {
                    $inner
                        ->whereNull("{$leadAlias}.proposal_id")
                        ->orWhere("{$leadAlias}.proposal_id", '');
                })
                ->where(function ($inner) use ($leadAlias) {
                    $inner
                        ->whereNull("{$leadAlias}.proposal_status")
                        ->orWhere("{$leadAlias}.proposal_status", '');
                });

            return;
        }

        $query->where("{$leadAlias}.proposal_status", $proposalStatus);
    }

    private static function applyNewcorbanStatusFilter(EloquentBuilder|QueryBuilder $query, ?string $status, ?string $attemptAlias): void
    {
        $status = self::stringValue($status);

        if ($status === null || $status === 'all' || $attemptAlias === null) {
            return;
        }

        match ($status) {
            'not_sent' => $query->whereNull("{$attemptAlias}.newcorban_sent_at"),
            'success' => $query->whereNotNull("{$attemptAlias}.newcorban_proposta_id"),
            'failed' => $query
                ->whereNull("{$attemptAlias}.newcorban_proposta_id")
                ->whereNotNull("{$attemptAlias}.newcorban_sent_at"),
            'sent' => $query->whereNotNull("{$attemptAlias}.newcorban_sent_at"),
            default => null,
        };
    }

    private static function applyInboxPhoneNumberFilter(EloquentBuilder|QueryBuilder $query, ?string $value, string $column): void
    {
        $digits = self::normalizeDigits($value);

        if ($digits === null) {
            return;
        }

        $query->whereRaw(self::digitsExpression($column) . ' = ?', [$digits]);
    }

    private static function applyTagsFilter(EloquentBuilder|QueryBuilder $query, array $tags, string $column): void
    {
        if ($tags === []) {
            return;
        }

        $query->where(function ($inner) use ($column, $tags) {
            foreach ($tags as $tag) {
                $inner->orWhereRaw("JSON_CONTAINS(COALESCE({$column}, JSON_ARRAY()), JSON_QUOTE(?))", [$tag]);
            }
        });
    }

    private static function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function tagValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $tag): ?string => self::stringValue(is_scalar($tag) ? (string) $tag : null),
            $value
        ))));
    }
}
