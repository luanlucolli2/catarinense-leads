<?php

declare(strict_types=1);

namespace App\Modules\Vendeai\Support;

use Illuminate\Support\Carbon;

final class VendeaiDateRange
{
    /**
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    public static function fromValidated(array $validated): array
    {
        $from = isset($validated['from']) ? self::parseDateBoundary((string) $validated['from'], false) : null;
        $to = isset($validated['to']) ? self::parseDateBoundary((string) $validated['to'], true) : null;

        return [$from, $to];
    }

    private static function parseDateBoundary(string $value, bool $isEnd): Carbon
    {
        $date = Carbon::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $isEnd ? $date->endOfDay() : $date->startOfDay();
        }

        return $date;
    }
}
