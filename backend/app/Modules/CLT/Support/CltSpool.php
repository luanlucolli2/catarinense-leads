<?php

namespace App\Modules\CLT\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Throwable;

final class CltSpool
{
    /**
     * @return array<int,string>
     */
    public static function artifactPaths(?string $spoolPath, ?string $cpfsPath = null, ?int $phase2MaxAttempts = null): array
    {
        $targets = [];

        if (is_string($spoolPath) && $spoolPath !== '') {
            $targets[] = $spoolPath;
        }

        if (is_string($cpfsPath) && $cpfsPath !== '') {
            $targets[] = $cpfsPath;
        }

        if (is_string($spoolPath) && $spoolPath !== '') {
            $targets[] = "{$spoolPath}.phase2.tmp";
            $targets[] = "{$spoolPath}.phase2.delta.ndjson";
            $targets[] = "{$spoolPath}.phase2.pending.ndjson";
            $targets[] = "{$spoolPath}.phase2.pending.ndjson.next";

            $attempts = max(1, $phase2MaxAttempts ?? (int) config('cltfacta.credit_worker.phase2_max_attempts', 3));
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $targets[] = "{$spoolPath}.phase2.delta.a{$attempt}.ndjson";
            }
        }

        return array_values(array_unique(array_filter($targets, static fn ($target) => is_string($target) && $target !== '')));
    }

    public static function deleteArtifacts(
        FilesystemAdapter $disk,
        ?string $spoolPath,
        ?string $cpfsPath = null,
        ?int $phase2MaxAttempts = null
    ): void {
        foreach (self::artifactPaths($spoolPath, $cpfsPath, $phase2MaxAttempts) as $target) {
            if (!$disk->exists($target)) {
                continue;
            }

            try {
                $disk->delete($target);
            } catch (Throwable) {
            }
        }
    }
}
