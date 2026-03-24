<?php

declare(strict_types=1);

namespace App\Modules\Uy3\Jobs;

use App\Modules\Uy3\Support\Uy3CltCsvExport;
use App\Modules\Uy3\Support\Uy3ExportCacheState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateUy3ExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        public int $userId,
        public string $token,
        public array $filters,
        public int $ttlSeconds
    ) {
        $this->timeout = max(1, (int) config('uy3.export.timeout_seconds', 3600));
        $this->onQueue((string) config('uy3.export.queue', 'reports'));
    }

    public function handle(): void
    {
        $key = Uy3ExportCacheState::key($this->userId, $this->token);
        $current = Cache::get($key);
        Cache::put($key, Uy3ExportCacheState::running(is_array($current) ? $current : null, $this->ttlSeconds), $this->ttlSeconds);

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', (string) config('uy3.export.memory_limit', '256M'));
            @ini_set('max_execution_time', '0');
            @ini_set('zend.enable_gc', '1');
            @ini_set('output_buffering', '0');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            DB::connection()->disableQueryLog();
            DB::connection()->getPdo()->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        } catch (\Throwable) {
        }

        $diskName = (string) config('uy3.export.storage.disk', 'local');
        $dir = trim((string) config('uy3.export.storage.directory', 'uy3-exports'), '/');
        $filenamePrefix = (string) config('uy3.export.storage.filename_prefix', 'uy3_export');
        $filename = "{$filenamePrefix}_{$this->token}.csv";
        $path = "{$dir}/{$filename}";
        $tmpPath = "{$dir}/{$this->token}.tmp.csv";

        $delimiter = (string) config('uy3.export.csv.delimiter', ';');
        $enclosure = (string) config('uy3.export.csv.enclosure', '"');
        $writeBOM = (bool) config('uy3.export.csv.bom', true);
        $flushEvery = max(1, (int) config('uy3.export.query.flush_every', 2000));

        try {
            $disk = Storage::disk($diskName);
            if (! $disk->exists($dir)) {
                $disk->makeDirectory($dir);
            }

            $absTmp = method_exists($disk, 'path')
                ? $disk->path($tmpPath)
                : storage_path('app/' . $tmpPath);

            $parent = dirname($absTmp);
            if (! is_dir($parent)) {
                @mkdir($parent, 0775, true);
            }

            $fh = @fopen($absTmp, 'wb');
            if ($fh === false) {
                throw new \RuntimeException("Falha ao abrir arquivo temporário para escrita: {$absTmp}");
            }

            @stream_set_write_buffer($fh, 1024 * 1024);

            if ($writeBOM) {
                fwrite($fh, "\xEF\xBB\xBF");
            }

            fputcsv($fh, Uy3CltCsvExport::headings(), $delimiter, $enclosure, '\\');
            Uy3CltCsvExport::writeRows($fh, $this->filters, $delimiter, $enclosure, $flushEvery);

            fflush($fh);
            fclose($fh);

            if ($diskName === 'local' && method_exists($disk, 'move') && method_exists($disk, 'exists')) {
                $disk->move($tmpPath, $path);
                if (! $disk->exists($path)) {
                    throw new \RuntimeException('Arquivo não encontrado após move');
                }
            } else {
                $stream = @fopen($absTmp, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('Falha ao reabrir tmp para upload');
                }
                $disk->put($path, $stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                @unlink($absTmp);
            }

            $size = 0;
            try {
                $size = (int) $disk->size($path);
            } catch (Throwable) {
            }

            Cache::put(
                $key,
                Uy3ExportCacheState::ready($diskName, $path, $filename, $size, $this->ttlSeconds),
                $this->ttlSeconds
            );

            $grace = (int) config('uy3.export.grace_seconds', 600);
            CleanupUy3ExportJob::dispatch($this->userId, $this->token)
                ->delay(now()->addSeconds(max(60, $this->ttlSeconds + $grace)));
        } catch (Throwable $e) {
            Log::warning("[UY3][EXPORT] Falha token={$this->token}: " . $e->getMessage(), ['exception' => $e]);

            try {
                if (isset($fh) && is_resource($fh)) {
                    fclose($fh);
                }
                if (isset($absTmp) && is_file($absTmp)) {
                    @unlink($absTmp);
                }
                if (isset($disk, $tmpPath) && $disk->exists($tmpPath)) {
                    $disk->delete($tmpPath);
                }
            } catch (Throwable) {
            }

            Cache::put(
                $key,
                Uy3ExportCacheState::error(mb_strimwidth($e->getMessage(), 0, 1000, '...', 'UTF-8'), $this->ttlSeconds),
                $this->ttlSeconds
            );
        }
    }

    public function failed(Throwable $e): void
    {
        $key = Uy3ExportCacheState::key($this->userId, $this->token);

        Cache::put(
            $key,
            Uy3ExportCacheState::error(mb_strimwidth($e->getMessage(), 0, 1000, '...', 'UTF-8'), $this->ttlSeconds),
            $this->ttlSeconds
        );
    }
}
