<?php

namespace App\Modules\Vendeai\Support;

final class VendeaiProductKey
{
    public static function canonicalize(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim((string) $value));

        return match ($normalized) {
            'clt', 'credito do trabalhador', 'crédito do trabalhador' => 'clt',
            'fgts' => 'fgts',
            default => null,
        };
    }

    public static function resolveFromPayload(array $payload): ?string
    {
        return self::canonicalize(data_get($payload, 'proposal.product'))
            ?? self::canonicalize(data_get($payload, 'simulation.product'))
            ?? self::canonicalize(data_get($payload, 'chat_summary.details.session.product_being_processed'))
            ?? self::canonicalize(data_get($payload, 'chat_summary.product'));
    }

    public static function collectFromLead(array|object $lead): array
    {
        $values = [
            self::value($lead, 'proposal_product'),
            self::value($lead, 'simulation_product'),
            self::value($lead, 'product_being_processed'),
            self::value($lead, 'chat_product'),
        ];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): ?string => self::canonicalize($value),
            $values,
        ))));
    }

    private static function value(array|object $lead, string $key): mixed
    {
        if (is_array($lead)) {
            return $lead[$key] ?? null;
        }

        return $lead->{$key} ?? null;
    }
}
