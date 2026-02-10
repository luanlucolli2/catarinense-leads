<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

final class CltLog
{
    public static function enabled(): bool
    {
        return (bool) config('cltfacta.logging.enabled', true);
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

