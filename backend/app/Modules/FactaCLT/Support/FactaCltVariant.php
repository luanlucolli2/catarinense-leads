<?php

namespace App\Modules\FactaCLT\Support;

final class FactaCltVariant
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
            'offline' => (string) config('facta.job.queue_offline', 'facta-clt-off'),
            'hybrid' => (string) config('facta.job.queue_hybrid', config('facta.job.queue_online', 'facta-clt-consulta-online')),
            'credit_policy' => (string) config('facta.job.queue_phase2', 'facta-clt-valida-politica-cred'),
            default => (string) config('facta.job.queue_online', 'facta-clt-consulta-online'),
        };
    }
}
