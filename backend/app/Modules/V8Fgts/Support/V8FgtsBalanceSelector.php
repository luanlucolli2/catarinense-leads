<?php

namespace App\Modules\V8Fgts\Support;

use Carbon\CarbonImmutable;

final class V8FgtsBalanceSelector
{
    public static function selectLatestRelevant(array $items, string $cpf, string $provider, string $acceptedAt, int $toleranceSeconds = 5): ?array
    {
        $accepted = self::parseTimestamp($acceptedAt);
        if ($accepted === null) {
            return null;
        }

        $threshold = $accepted->subSeconds(max(0, $toleranceSeconds));
        $matches = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (preg_replace('/\D+/', '', (string) ($item['documentNumber'] ?? '')) !== $cpf) {
                continue;
            }

            if (strtolower(trim((string) ($item['provider'] ?? ''))) !== strtolower($provider)) {
                continue;
            }

            $stamp = self::pickTimestamp($item);
            if ($stamp === null || $stamp->lt($threshold)) {
                continue;
            }

            $matches[] = ['item' => $item, 'stamp' => $stamp];
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn (array $a, array $b) => $b['stamp']->getTimestamp() <=> $a['stamp']->getTimestamp());

        return $matches[0]['item'];
    }

    private static function pickTimestamp(array $item): ?CarbonImmutable
    {
        foreach (['updatedAt', 'createdAt'] as $field) {
            $stamp = self::parseTimestamp($item[$field] ?? null);
            if ($stamp !== null) {
                return $stamp;
            }
        }

        return null;
    }

    private static function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
