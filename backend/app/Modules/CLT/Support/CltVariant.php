<?php

namespace App\Modules\CLT\Support;

final class CltVariant
{
    public static function normalizeFilter(string $variant): string
    {
        return match ($variant) {
            'on' => 'online',
            'off' => 'offline',
            'hyb' => 'hybrid',
            default => $variant,
        };
    }

    public static function normalizeStored(?string $variant): string
    {
        return match ($variant) {
            'offline' => 'offline',
            'hybrid' => 'hybrid',
            default => 'online',
        };
    }

    public static function supportsCreditPhaseTwo(?string $variant): bool
    {
        return in_array(self::normalizeStored($variant), ['online', 'hybrid'], true);
    }

    public static function resolvePhaseOneQueue(?string $variant): string
    {
        return match (self::normalizeStored($variant)) {
            'offline' => (string) config('cltfacta.job.queue_offline', 'clt-off'),
            'hybrid' => (string) config('cltfacta.job.queue_hybrid', config('cltfacta.job.queue_online', 'clt-consulta-online')),
            default => (string) config('cltfacta.job.queue_online', 'clt-consulta-online'),
        };
    }
}
