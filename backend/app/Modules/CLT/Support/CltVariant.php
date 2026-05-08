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
            'policy', 'credit-policy', 'politica', 'politica_credito' => 'credit_policy',
            default => $variant,
        };
    }

    public static function normalizeStored(?string $variant): string
    {
        return match ($variant) {
            'offline' => 'offline',
            'hybrid' => 'hybrid',
            'credit_policy', 'policy', 'credit-policy', 'politica', 'politica_credito' => 'credit_policy',
            default => 'online',
        };
    }

    public static function supportsCreditPhaseTwo(?string $variant): bool
    {
        return in_array(self::normalizeStored($variant), ['online', 'hybrid', 'credit_policy'], true);
    }

    public static function isCreditPolicyOnly(?string $variant): bool
    {
        return self::normalizeStored($variant) === 'credit_policy';
    }

    public static function resolvePhaseOneQueue(?string $variant): string
    {
        return match (self::normalizeStored($variant)) {
            'offline' => (string) config('cltfacta.job.queue_offline', 'clt-off'),
            'hybrid' => (string) config('cltfacta.job.queue_hybrid', config('cltfacta.job.queue_online', 'clt-consulta-online')),
            'credit_policy' => (string) config('cltfacta.job.queue_phase2', 'clt-valida-politica-cred'),
            default => (string) config('cltfacta.job.queue_online', 'clt-consulta-online'),
        };
    }
}
