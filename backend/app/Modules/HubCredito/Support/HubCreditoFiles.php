<?php

namespace App\Modules\HubCredito\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Throwable;

final class HubCreditoFiles
{
    public const PENDING_SHARD_COUNT = 64;

    public static function pendingShardPath(string $dirSpool, string $finalPrefix, int $jobId, int $shard): string
    {
        return sprintf(
            '%s/%s_%d.phase2.pending.%02d.csv',
            $dirSpool,
            $finalPrefix,
            $jobId,
            $shard
        );
    }

    public static function pendingShardPaths(string $dirSpool, string $finalPrefix, int $jobId): array
    {
        $paths = [];

        for ($shard = 0; $shard < self::PENDING_SHARD_COUNT; $shard++) {
            $paths[] = self::pendingShardPath($dirSpool, $finalPrefix, $jobId, $shard);
        }

        return $paths;
    }

    public static function deleteTransientFiles(
        FilesystemAdapter $disk,
        string $dirSpool,
        string $finalPrefix,
        int $jobId,
        array $extraPaths = [],
        array $keepPaths = []
    ): void {
        $keep = array_fill_keys(array_filter($keepPaths, static fn ($path) => is_string($path) && $path !== ''), true);
        $paths = array_merge(
            array_filter($extraPaths, static fn ($path) => is_string($path) && $path !== ''),
            self::pendingShardPaths($dirSpool, $finalPrefix, $jobId)
        );

        foreach (array_values(array_unique($paths)) as $path) {
            if (isset($keep[$path])) {
                continue;
            }

            try {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (Throwable) {
            }
        }
    }
}
