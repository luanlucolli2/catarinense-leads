<?php

namespace App\Jobs;

use App\Exports\LeadsExport;
use App\Http\Filters\LeadFilter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class GenerateLeadsExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public function __construct(
        public int $userId,
        public string $token,
        public array $payload,
        public int $ttlSeconds
    ) {
        $this->onQueue((string) env('PREVIEW_JOB_QUEUE', 'reports'));
    }

    public function handle(): void
    {
        $key = $this->cacheKey($this->userId, $this->token);

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
            @ini_set('max_execution_time', '0');
        }
        Config::set('excel.exports.chunk_size', 1000);
        Config::set('excel.exports.pre_calculate_formulas', false);
        Config::set('excel.cache.driver', 'illuminate');
        Config::set('excel.cache.illuminate.store', null);
        Config::set('excel.cache.batch.memory_limit', 32768);
        Config::set('excel.temporary_files.local_path', storage_path('framework/cache/excel-temp'));

        $diskName = (string) env('LEADS_EXPORT_DISK', 'local');
        $dir = trim((string) env('LEADS_EXPORT_DIR', 'leads-exports'), '/');
        $filename = "leads_export_{$this->token}.xlsx";
        $path = "{$dir}/{$filename}";

        try {
            $req = new HttpRequest();
            $req->replace($this->payload);

            $columns = (array) ($this->payload['columns'] ?? []);
            $query = LeadFilter::apply($req, $columns);

            $disk = Storage::disk($diskName);
            if (!$disk->exists($dir))
                $disk->makeDirectory($dir);

            $tmpPath = "{$dir}/{$this->token}.tmp.xlsx";
            Excel::store(new LeadsExport($query, $columns), $tmpPath, $diskName);
            $disk->move($tmpPath, $path);
            if (!$disk->exists($path))
                throw new \RuntimeException("Arquivo não encontrado após move");

            $size = 0;
            try {
                $size = (int) $disk->size($path);
            } catch (Throwable) {
            }

            Cache::put($key, [
                'status' => 'ready',
                'message' => 'Export pronto para download.',
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
                'disk' => $diskName,
                'path' => $path,
                'filename' => $filename,
                'size_bytes' => $size,
                'error' => null,
                'ttl_seconds' => $this->ttlSeconds,
            ], $this->ttlSeconds);

            // Agenda limpeza tardia caso ninguém baixe
            $grace = (int) env('LEADS_EXPORT_GRACE_SECONDS', 600);
            CleanupLeadsExportJob::dispatch($this->userId, $this->token)
                ->delay(now()->addSeconds(max(60, $this->ttlSeconds + $grace)));
        } catch (Throwable $e) {
            Log::warning("[LEADS][EXPORT] Falha token={$this->token}: " . $e->getMessage(), ['exception' => $e]);

            Cache::put($key, [
                'status' => 'error',
                'message' => 'Falha ao gerar export.',
                'created_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
                'disk' => null,
                'path' => null,
                'filename' => null,
                'size_bytes' => 0,
                'error' => mb_strimwidth($e->getMessage(), 0, 1000, '…', 'UTF-8'),
                'ttl_seconds' => $this->ttlSeconds,
            ], $this->ttlSeconds);
        }
    }

    private function cacheKey(int $userId, string $token): string
    {
        return "leads_export:{$userId}:{$token}";
    }
}
