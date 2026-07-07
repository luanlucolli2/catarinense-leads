<?php

declare(strict_types=1);

namespace App\Modules\Vendeai\Support;

final class VendeaiAttemptPayload
{
    public static function proposal(mixed $payload): array
    {
        $data = self::toArray($payload);

        return [
            'proposal_id' => self::stringOrNull(data_get($data, 'proposal.proposal_id')),
            'proposal_number' => self::stringOrNull(data_get($data, 'proposal.proposal_number')),
            'proposal_bank' => self::stringOrNull(data_get($data, 'proposal.bank')),
            'proposal_product' => self::stringOrNull(data_get($data, 'proposal.product')),
            'proposal_status' => self::stringOrNull(data_get($data, 'proposal.proposal_status')),
            'previous_proposal_status' => self::stringOrNull(data_get($data, 'proposal.previous_proposal_status')),
            'proposal_liquid_value' => self::scalarOrNull(data_get($data, 'proposal.liquid_value')),
            'proposal_gross_value' => self::scalarOrNull(data_get($data, 'proposal.gross_value')),
            'proposal_number_of_payments' => self::intOrNull(data_get($data, 'proposal.number_of_payments')),
            'proposal_installment_value' => self::scalarOrNull(data_get($data, 'proposal.installment_value')),
            'proposal_table_name' => self::stringOrNull(data_get($data, 'proposal.table_name')),
            'proposal_table_id' => self::stringOrNull(data_get($data, 'proposal.table_id')),
            'proposal_formalization_link' => self::stringOrNull(data_get($data, 'proposal.formalization_link')),
        ];
    }

    private static function toArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        if (is_object($payload)) {
            $decoded = json_decode(json_encode($payload, JSON_UNESCAPED_UNICODE), true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_scalar($value)) {
            $value = trim((string) $value);

            return $value === '' ? null : $value;
        }

        return null;
    }

    private static function scalarOrNull(mixed $value): int|float|string|null
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        return $value;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
