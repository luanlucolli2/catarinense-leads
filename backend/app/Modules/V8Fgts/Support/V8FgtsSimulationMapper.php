<?php

namespace App\Modules\V8Fgts\Support;

final class V8FgtsSimulationMapper
{
    public static function mapDesiredInstallments(array $periods): array
    {
        $out = [];

        foreach ($periods as $period) {
            if (!is_array($period)) {
                continue;
            }

            $amount = $period['amount'] ?? null;
            $dueDate = is_string($period['dueDate'] ?? null) ? trim((string) $period['dueDate']) : '';
            if (!is_numeric($amount) || $dueDate === '') {
                continue;
            }

            $out[] = [
                'totalAmount' => (float) $amount,
                'dueDate' => $dueDate,
            ];
        }

        return $out;
    }

    public static function summarizePeriods(array $periods): string
    {
        $parts = [];

        foreach (self::mapDesiredInstallments($periods) as $installment) {
            $parts[] = $installment['dueDate'] . ':' . number_format((float) $installment['totalAmount'], 2, '.', '');
        }

        return implode(' | ', $parts);
    }

    public static function selectNormalFee(array $fees, string $label = 'normal'): ?array
    {
        $label = strtolower(trim($label));

        foreach ($fees as $item) {
            if (!is_array($item) || !($item['active'] ?? false)) {
                continue;
            }

            $simulationFees = is_array($item['simulation_fees'] ?? null) ? $item['simulation_fees'] : null;
            if ($simulationFees !== null) {
                if (strtolower(trim((string) ($simulationFees['label'] ?? ''))) !== $label) {
                    continue;
                }

                $feeId = $simulationFees['id_simulation_fees'] ?? null;
                if (!is_string($feeId) || trim($feeId) === '') {
                    continue;
                }

                return [
                    'label' => (string) ($simulationFees['label'] ?? $label),
                    'id' => $feeId,
                ];
            }

            if (strtolower(trim((string) ($item['visible_name'] ?? ''))) !== $label) {
                continue;
            }

            $feeId = $item['id'] ?? null;
            if (!is_string($feeId) || trim($feeId) === '') {
                continue;
            }

            return [
                'label' => (string) ($item['visible_name'] ?? $label),
                'id' => $feeId,
            ];
        }

        return null;
    }
}
