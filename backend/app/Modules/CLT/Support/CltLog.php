<?php

namespace App\Modules\CLT\Support;

use Illuminate\Support\Facades\Log;

final class CltLog
{
    public static function enabled(): bool
    {
        return (bool) config('cltfacta.logging.enabled', true);
    }

    public static function debug($message, array $context = []): void
    {
        return;
    }

    public static function info($message, array $context = []): void
    {
        return;
    }

    public static function warning($message, array $context = []): void
    {
        return;
    }

    public static function error($message, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }

        Log::error($message, $context);
    }
}
