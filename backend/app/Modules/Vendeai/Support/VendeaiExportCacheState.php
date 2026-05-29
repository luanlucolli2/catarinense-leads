<?php

declare(strict_types=1);

namespace App\Modules\Vendeai\Support;

final class VendeaiExportCacheState
{
    public static function key(int $userId, string $token): string
    {
        $prefix = (string) config('vendeai.export.cache.key_prefix', 'vendeai_export');
        return "{$prefix}:{$userId}:{$token}";
    }

    public static function queued(int $ttlSeconds): array
    {
        $now = now()->toIso8601String();

        return [
            'status' => 'queued',
            'message' => 'Export enfileirado.',
            'created_at' => $now,
            'updated_at' => $now,
            'disk' => null,
            'path' => null,
            'filename' => null,
            'size_bytes' => 0,
            'error' => null,
            'ttl_seconds' => $ttlSeconds,
        ];
    }

    public static function running(?array $current, int $ttlSeconds): array
    {
        $now = now()->toIso8601String();

        return [
            'status' => 'running',
            'message' => 'Gerando arquivo de exportação.',
            'created_at' => $current['created_at'] ?? $now,
            'updated_at' => $now,
            'disk' => $current['disk'] ?? null,
            'path' => $current['path'] ?? null,
            'filename' => $current['filename'] ?? null,
            'size_bytes' => (int) ($current['size_bytes'] ?? 0),
            'error' => null,
            'ttl_seconds' => $ttlSeconds,
        ];
    }

    public static function ready(string $disk, string $path, string $filename, int $sizeBytes, int $ttlSeconds): array
    {
        $now = now()->toIso8601String();

        return [
            'status' => 'ready',
            'message' => 'Export pronto para download.',
            'created_at' => $now,
            'updated_at' => $now,
            'disk' => $disk,
            'path' => $path,
            'filename' => $filename,
            'size_bytes' => $sizeBytes,
            'error' => null,
            'ttl_seconds' => $ttlSeconds,
        ];
    }

    public static function error(string $message, int $ttlSeconds): array
    {
        $now = now()->toIso8601String();

        return [
            'status' => 'error',
            'message' => 'Falha ao gerar export.',
            'created_at' => $now,
            'updated_at' => $now,
            'disk' => null,
            'path' => null,
            'filename' => null,
            'size_bytes' => 0,
            'error' => $message,
            'ttl_seconds' => $ttlSeconds,
        ];
    }

    public static function deleted(array $current, string $disk, ?string $path, string $message): array
    {
        return [
            'status' => 'deleted',
            'message' => $message,
            'created_at' => $current['created_at'] ?? now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'disk' => $disk,
            'path' => $path,
            'filename' => $current['filename'] ?? null,
            'size_bytes' => 0,
            'error' => null,
            'ttl_seconds' => (int) ($current['ttl_seconds'] ?? 3600),
        ];
    }
}
