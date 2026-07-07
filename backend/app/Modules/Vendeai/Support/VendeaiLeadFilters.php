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
            'lead_period_basis' => ['nullable', Rule::in(['updated', 'started'])],
            'product' => ['nullable'],
            'product.*' => [Rule::in(['clt', 'fgts'])],
            'search' => ['nullable', 'string', 'max:255'],
            'bank' => ['nullable'],
            'bank.*' => ['string', 'max:80'],
            'stage' => ['nullable'],
            'stage.*' => ['string', 'max:80'],
            'proposal_status' => ['nullable'],
            'proposal_status.*' => ['string', 'max:120'],
            'newcorban_status' => ['nullable'],
            'newcorban_status.*' => [Rule::in(['not_sent', 'success', 'failed', 'sent'])],
            'newcorban_filter' => ['nullable', Rule::in(['all', 'sent', 'created'])],
            'inbox_phone_number' => ['nullable'],
            'inbox_phone_number.*' => ['string', 'max:30'],
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

        self::applyProductFilter($query, self::stringList($filters['product'] ?? null), "{$leadAlias}.product_key");
        self::applySearchFilter($query, self::stringValue($filters['search'] ?? null), $leadAlias, $attemptAlias);
        self::applyBankFilter($query, self::normalizedBankValues($filters['bank'] ?? null), $leadAlias);
        self::applyStageFilter($query, self::stringList($filters['stage'] ?? null), "{$leadAlias}.stage");
        self::applyProposalStatusFilter($query, self::stringList($filters['proposal_status'] ?? null), $leadAlias);
        self::applyNewcorbanStatusFilter(
            $query,
            self::stringList($filters['newcorban_status'] ?? null),
            $attemptAlias
        );
        self::applyInboxPhoneNumberFilter(
            $query,
            self::stringList($filters['inbox_phone_number'] ?? null),
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

        return match ($normalized) {
            'mercantil_api' => 'mercantil',
            'novo_saque_api' => 'novo_saque',
            'soma_celcoin', 'soma_uy3' => 'soma',
            default => $normalized,
        };
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

        return "CASE
            WHEN {$normalized} = 'mercantil_api' THEN 'mercantil'
            WHEN {$normalized} = 'novo_saque_api' THEN 'novo_saque'
            WHEN {$normalized} IN ('soma_celcoin', 'soma_uy3') THEN 'soma'
            ELSE {$normalized}
        END";
    }

    public static function digitsExpression(string $column): string
    {
        return "REGEXP_REPLACE(COALESCE({$column}, ''), '[^0-9]', '')";
    }

    public static function leadPeriodColumn(array $filters, string $leadAlias = 'vendeai_leads'): string
    {
        return ($filters['lead_period_basis'] ?? 'updated') === 'started'
            ? "{$leadAlias}.first_received_at"
            : "{$leadAlias}.last_received_at";
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

    private static function applyProductFilter(EloquentBuilder|QueryBuilder $query, array $products, string $column): void
    {
        $products = array_values(array_intersect($products, ['clt', 'fgts']));

        if ($products === []) {
            return;
        }

        $query->whereIn($column, $products);
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

    private static function applyBankFilter(EloquentBuilder|QueryBuilder $query, array $banks, string $leadAlias): void
    {
        if ($banks === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($banks), '?'));
        $query->whereRaw(self::bankExpression($leadAlias) . " in ({$placeholders})", $banks);
    }

    private static function applyStageFilter(EloquentBuilder|QueryBuilder $query, array $stages, string $column): void
    {
        $stages = array_map('mb_strtolower', $stages);
        if ($stages === []) {
            return;
        }

        $placeholders = implode(', ', array_fill(0, count($stages), '?'));
        $query->whereRaw("LOWER(TRIM(COALESCE({$column}, ''))) in ({$placeholders})", $stages);
    }

    private static function applyProposalStatusFilter(EloquentBuilder|QueryBuilder $query, array $proposalStatuses, string $leadAlias): void
    {
        if ($proposalStatuses === []) {
            return;
        }

        $includeNoProposal = in_array(self::NO_PROPOSAL, $proposalStatuses, true);
        $statuses = array_values(array_filter($proposalStatuses, fn (string $status): bool => $status !== self::NO_PROPOSAL));

        $query->where(function ($inner) use ($includeNoProposal, $leadAlias, $statuses) {
            if ($includeNoProposal) {
                $inner->where(function ($missing) use ($leadAlias) {
                    $missing
                        ->where(function ($query) use ($leadAlias) {
                            $query->whereNull("{$leadAlias}.proposal_id")->orWhere("{$leadAlias}.proposal_id", '');
                        })
                        ->where(function ($query) use ($leadAlias) {
                            $query->whereNull("{$leadAlias}.proposal_status")->orWhere("{$leadAlias}.proposal_status", '');
                        });
                });
            }

            if ($statuses !== []) {
                if ($includeNoProposal) {
                    $inner->orWhereIn("{$leadAlias}.proposal_status", $statuses);
                } else {
                    $inner->whereIn("{$leadAlias}.proposal_status", $statuses);
                }
            }
        });
    }

    private static function applyNewcorbanStatusFilter(EloquentBuilder|QueryBuilder $query, array $statuses, ?string $attemptAlias): void
    {
        if ($statuses === [] || $attemptAlias === null) {
            return;
        }

        $query->where(function ($inner) use ($attemptAlias, $statuses) {
            foreach (array_values($statuses) as $index => $status) {
                match ($status) {
                    'not_sent' => $index === 0
                        ? $inner->whereNull("{$attemptAlias}.newcorban_sent_at")
                        : $inner->orWhereNull("{$attemptAlias}.newcorban_sent_at"),
                    'success' => $index === 0
                        ? $inner->whereNotNull("{$attemptAlias}.newcorban_proposta_id")
                        : $inner->orWhereNotNull("{$attemptAlias}.newcorban_proposta_id"),
                    'failed' => $index === 0
                        ? $inner->where(function ($failed) use ($attemptAlias) {
                            $failed
                                ->whereNull("{$attemptAlias}.newcorban_proposta_id")
                                ->whereNotNull("{$attemptAlias}.newcorban_sent_at");
                        })
                        : $inner->orWhere(function ($failed) use ($attemptAlias) {
                        $failed
                            ->whereNull("{$attemptAlias}.newcorban_proposta_id")
                            ->whereNotNull("{$attemptAlias}.newcorban_sent_at");
                    }),
                    'sent' => $index === 0
                        ? $inner->whereNotNull("{$attemptAlias}.newcorban_sent_at")
                        : $inner->orWhereNotNull("{$attemptAlias}.newcorban_sent_at"),
                    default => null,
                };
            }
        });
    }

    private static function applyInboxPhoneNumberFilter(EloquentBuilder|QueryBuilder $query, array $values, string $column): void
    {
        $digits = array_values(array_unique(array_filter(array_map(
            fn (string $value): ?string => self::normalizeDigits($value),
            $values
        ))));

        if ($digits === []) {
            return;
        }

        $query->where(function ($inner) use ($column, $digits) {
            foreach (array_values($digits) as $index => $value) {
                if ($index === 0) {
                    $inner->whereRaw(self::digitsExpression($column) . ' = ?', [$value]);
                } else {
                    $inner->orWhereRaw(self::digitsExpression($column) . ' = ?', [$value]);
                }
            }
        });
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

    private static function stringList(mixed $value): array
    {
        if (is_array($value)) {
            $values = $value;
        } elseif (is_scalar($value)) {
            $values = [(string) $value];
        } else {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => self::stringValue(is_scalar($item) ? (string) $item : null),
            $values
        ), fn (?string $item): bool => $item !== null && $item !== 'all')));
    }

    private static function normalizedBankValues(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $bank): ?string => self::normalizeBankValue($bank),
            self::stringList($value)
        ))));
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
