<?php

namespace App\Modules\Vendeai\Jobs;

use App\Modules\Vendeai\Support\VendeaiCsvExport;
use App\Modules\Vendeai\Support\VendeaiExportCacheState;
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

class GenerateVendeaiExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        public int $userId,
        public string $token,
        public string $type,
        public array $filters,
        public int $ttlSeconds
    ) {
        $this->timeout = max(1, (int) config('vendeai.export.timeout_seconds', 3600));
        $this->onQueue((string) config('vendeai.export.queue', 'reports'));
    }

    public function handle(): void
    {
        $key = VendeaiExportCacheState::key($this->userId, $this->token);
        $current = Cache::get($key);
        Cache::put($key, VendeaiExportCacheState::running(is_array($current) ? $current : null, $this->ttlSeconds), $this->ttlSeconds);

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', (string) config('vendeai.export.memory_limit', '256M'));
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
        } catch (Throwable) {
        }

        $diskName = (string) config('vendeai.export.storage.disk', 'local');
        $dir = trim((string) config('vendeai.export.storage.directory', 'vendeai-exports'), '/');
        $filename = VendeaiCsvExport::filenamePrefix($this->type) . "_{$this->token}.csv";
        $path = "{$dir}/{$filename}";
        $tmpPath = "{$dir}/{$this->token}.tmp.csv";
        $delimiter = (string) config('vendeai.export.csv.delimiter', ';');
        $enclosure = (string) config('vendeai.export.csv.enclosure', '"');
        $writeBOM = (bool) config('vendeai.export.csv.bom', true);
        $flushEvery = max(1, (int) config('vendeai.export.query.flush_every', 2000));

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

            fputcsv($fh, VendeaiCsvExport::headings($this->type), $delimiter, $enclosure, '\\');
            VendeaiCsvExport::writeRows($fh, $this->type, $this->filters, $delimiter, $enclosure, $flushEvery);

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

            Cache::put(
                $key,
                VendeaiExportCacheState::ready($diskName, $path, $filename, (int) $disk->size($path), $this->ttlSeconds),
                $this->ttlSeconds
            );

            CleanupVendeaiExportJob::dispatch($this->userId, $this->token)
                ->delay(now()->addSeconds(max(60, $this->ttlSeconds + (int) config('vendeai.export.grace_seconds', 600))));
        } catch (Throwable $e) {
            Log::warning("[VENDEAI][EXPORT] Falha token={$this->token}: " . $e->getMessage(), ['exception' => $e]);

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
                VendeaiExportCacheState::error(mb_strimwidth($e->getMessage(), 0, 1000, '...', 'UTF-8'), $this->ttlSeconds),
                $this->ttlSeconds
            );
        }
    }

    public function failed(Throwable $e): void
    {
        Cache::put(
            VendeaiExportCacheState::key($this->userId, $this->token),
            VendeaiExportCacheState::error(mb_strimwidth($e->getMessage(), 0, 1000, '...', 'UTF-8'), $this->ttlSeconds),
            $this->ttlSeconds
        );
    }
}
