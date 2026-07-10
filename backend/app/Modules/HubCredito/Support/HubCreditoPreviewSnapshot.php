<?php

namespace App\Modules\HubCredito\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Throwable;

final class HubCreditoPreviewSnapshot
{
    public static function create(FilesystemAdapter $disk, ?string $spoolPath)
    {
        if (!is_string($spoolPath) || $spoolPath === '' || !$disk->exists($spoolPath)) {
            return null;
        }

        $source = @fopen($disk->path($spoolPath), 'rb');
        if (!is_resource($source)) {
            return null;
        }

        $snapshot = tmpfile();
        if (!is_resource($snapshot)) {
            fclose($source);
            return null;
        }

        try {
            flock($source, LOCK_SH);
            if (stream_copy_to_stream($source, $snapshot) === false) {
                fclose($snapshot);
                return null;
            }
            rewind($snapshot);
        } catch (Throwable) {
            fclose($snapshot);
            return null;
        } finally {
            flock($source, LOCK_UN);
            fclose($source);
        }

        return $snapshot;
    }
}
