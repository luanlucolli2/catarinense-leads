<?php

namespace App\Modules\V8\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Throwable;

final class V8Spool
{
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
