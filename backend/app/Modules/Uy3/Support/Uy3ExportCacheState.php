<?php

declare(strict_types=1);

namespace App\Modules\Uy3\Support;

final class Uy3ExportCacheState
{
    public static function key(int $userId, string $token): string
    {
        $prefix = (string) config('uy3.export.cache.key_prefix', 'uy3_export');
        return "{$prefix}:{$userId}:{$token}";
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed>|null $current
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
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
