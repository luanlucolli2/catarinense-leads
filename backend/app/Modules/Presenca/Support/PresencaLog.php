<?php

namespace App\Modules\Presenca\Support;

use Illuminate\Support\Facades\Log;

final class PresencaLog
{
    public static function enabled(): bool
    {
        return (bool) config('presenca.logging.enabled', true);
    }

    public static function debug($message, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }

        Log::debug($message, $context);
    }

    public static function info($message, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }

        Log::info($message, $context);
    }

    public static function warning($message, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }

        Log::warning($message, $context);
    }

    public static function error($message, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }

        Log::error($message, $context);
    }
}
