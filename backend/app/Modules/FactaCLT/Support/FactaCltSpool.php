<?php

namespace App\Modules\FactaCLT\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Throwable;

final class FactaCltSpool
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

            $attempts = max(1, $phase2MaxAttempts ?? (int) config('facta.credit_worker.phase2_max_attempts', 3));
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $targets[] = "{$spoolPath}.phase2.delta.a{$attempt}.ndjson";
            }
        }

        return array_values(array_unique(array_filter($targets, static fn ($target) => is_string($target) && $target !== '')));
    }

    /**
     * Caminhos transitórios da fase 2 que podem ser removidos preservando o spool base.
     *
     * @return array<int,string>
     */
    public static function phaseTwoAuxiliaryArtifactPaths(
        ?string $spoolPath,
        ?string $cpfsPath = null,
        ?int $phase2MaxAttempts = null
    ): array {
        $targets = [];

        if (is_string($cpfsPath) && $cpfsPath !== '') {
            $targets[] = $cpfsPath;
        }

        if (is_string($spoolPath) && $spoolPath !== '') {
            $targets[] = "{$spoolPath}.phase2.tmp";
            $targets[] = "{$spoolPath}.phase2.delta.ndjson";
            $targets[] = "{$spoolPath}.phase2.pending.ndjson";
            $targets[] = "{$spoolPath}.phase2.pending.ndjson.next";

            $attempts = max(1, $phase2MaxAttempts ?? (int) config('facta.credit_worker.phase2_max_attempts', 3));
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

    public static function deletePhaseTwoAuxiliaryArtifacts(
        FilesystemAdapter $disk,
        ?string $spoolPath,
        ?string $cpfsPath = null,
        ?int $phase2MaxAttempts = null
    ): void {
        foreach (self::phaseTwoAuxiliaryArtifactPaths($spoolPath, $cpfsPath, $phase2MaxAttempts) as $target) {
            if (!$disk->exists($target)) {
                continue;
            }

            try {
                $disk->delete($target);
            } catch (Throwable) {
            }
        }
    }

    public static function hasDataRows(FilesystemAdapter $disk, ?string $spoolPath): bool
    {
        if (!is_string($spoolPath) || $spoolPath === '' || !$disk->exists($spoolPath)) {
            return false;
        }

        $realPath = $disk->path($spoolPath);
        $handle = @fopen($realPath, 'rb');
        if (!is_resource($handle)) {
            return false;
        }

        try {
            flock($handle, LOCK_SH);

            $peek = fread($handle, 3);
            if ($peek !== "\xEF\xBB\xBF") {
                fseek($handle, 0);
            }

            fgetcsv($handle, 0, ';');

            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                if (!is_array($row) || $row === [null]) {
                    continue;
                }

                foreach ($row as $value) {
                    if (trim((string) $value) !== '') {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            return false;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return false;
    }
}
