<?php
declare(strict_types=1);

namespace App\Modules\Leads\Jobs;

use App\Modules\Leads\Filters\LeadFilter;
use App\Modules\Leads\Support\LeadExportColumns;
use App\Modules\Leads\Support\LeadsExportCacheState;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        $this->timeout = max(1, (int) config('leads.export.timeout_seconds', 7200));
        $this->onQueue((string) config('leads.export.queue', 'reports'));
    }

    public function handle(): void
    {
        $key = LeadsExportCacheState::key($this->userId, $this->token);

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', (string) config('leads.export.memory_limit', '256M'));
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

        $excelTempPath = (string) config('leads.export.storage.excel_temp_local_path', 'framework/cache/excel-temp');
        if ($excelTempPath !== '') {
            $resolvedExcelTempPath = str_starts_with($excelTempPath, DIRECTORY_SEPARATOR)
                ? $excelTempPath
                : storage_path(ltrim($excelTempPath, '/'));
            Config::set('excel.temporary_files.local_path', $resolvedExcelTempPath);
        }

        $diskName = (string) config('leads.export.storage.disk', 'local');
        $dir = trim((string) config('leads.export.storage.directory', 'leads-exports'), '/');
        $filenamePrefix = (string) config('leads.export.storage.filename_prefix', 'leads_export');
        $filename = "{$filenamePrefix}_{$this->token}.csv";
        $path = "{$dir}/{$filename}";
        $tmpPath = "{$dir}/{$this->token}.tmp.csv";

        $delimiter = (string) config('leads.export.csv.delimiter', ';');
        $enclosure = (string) config('leads.export.csv.enclosure', '"');
        $writeBOM = (bool) config('leads.export.csv.bom', true);
        $chunkSize = max(1, (int) config('leads.export.query.chunk_size', 800));
        $flushEvery = max(1, (int) config('leads.export.query.flush_every', 2000));

        try {
            $req = new HttpRequest();
            $req->replace($this->payload);

            $columns = (array) ($this->payload['columns'] ?? []);
            $eloquent = LeadFilter::apply($req, $columns); // export-mode

            $pk = $eloquent->getModel()->getKeyName() ?: 'id';
            $qualifiedPk = $eloquent->getModel()->qualifyColumn($pk);

            $base = $eloquent->toBase();

            $disk = Storage::disk($diskName);
            if (!$disk->exists($dir)) {
                $disk->makeDirectory($dir);
            }

            $absTmp = method_exists($disk, 'path')
                ? $disk->path($tmpPath)
                : storage_path('app/' . $tmpPath);

            $parent = dirname($absTmp);
            if (!is_dir($parent)) {
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

            fputcsv($fh, $this->headings($columns), $delimiter, $enclosure);

            $written = 0;

            foreach ($base->lazyById($chunkSize, $qualifiedPk, $pk) as $row) {
                fputcsv($fh, $this->mapRecord($row, $columns), $delimiter, $enclosure);
                $written++;
                if ($written % $flushEvery === 0) {
                    fflush($fh);
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                }
            }

            fflush($fh);
            fclose($fh);

            if ($diskName === 'local' && method_exists($disk, 'move') && method_exists($disk, 'exists')) {
                $disk->move($tmpPath, $path);
                if (!$disk->exists($path)) {
                    throw new \RuntimeException("Arquivo não encontrado após move");
                }
            } else {
                $stream = @fopen($absTmp, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException("Falha ao reabrir tmp para upload");
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
                LeadsExportCacheState::ready($diskName, $path, $filename, $size, $this->ttlSeconds),
                $this->ttlSeconds
            );

            $grace = (int) config('leads.export.grace_seconds', 600);
            \App\Modules\Leads\Jobs\CleanupLeadsExportJob::dispatch($this->userId, $this->token)
                ->delay(now()->addSeconds(max(60, $this->ttlSeconds + $grace)));
        } catch (Throwable $e) {
            Log::warning("[LEADS][EXPORT] Falha token={$this->token}: " . $e->getMessage(), ['exception' => $e]);
            try {
                if (isset($fh) && is_resource($fh))
                    fclose($fh);
                if (isset($absTmp) && is_file($absTmp))
                    @unlink($absTmp);
                if (isset($disk, $tmpPath) && $disk->exists($tmpPath))
                    $disk->delete($tmpPath);
            } catch (\Throwable) {
            }

            Cache::put(
                $key,
                LeadsExportCacheState::error(mb_strimwidth($e->getMessage(), 0, 1000, '...', 'UTF-8'), $this->ttlSeconds),
                $this->ttlSeconds
            );
        }
    }

    private function headings(array $columns): array
    {
        $labels = LeadExportColumns::labels();
        return array_map(static fn($c) => $labels[$c] ?? $c, $columns);
    }

    private function mapRecord(object $lead, array $columns): array
    {
        $row = [];
        foreach ($columns as $col) {
            $value = $lead->{$col} ?? null;
            $row[] = match (LeadExportColumns::formatterFor($col)) {
                'cpf_digits' => $this->cpfDigits($value),
                'date' => $this->formatDate($value),
                'datetime' => $this->formatUtcDateTime($value),
                'date_only' => $this->formatDate($value, true),
                'float' => $this->toFloat($value),
                'int' => isset($value) ? (int) $value : null,
                'bool_ptbr' => $value === null ? null : ($value ? 'Sim' : 'Não'),
                default => $value,
            };
        }
        return $row;
    }

    private function formatDate($value, bool $isDateOnly = false, bool $includeTime = false): ?string
    {
        if (empty($value))
            return null;
        try {
            $ts = is_string($value) ? strtotime($value) : (is_int($value) ? $value : null);
            if ($ts === null)
                $ts = strtotime((string) $value);
            if ($ts === false)
                return null;
            if ($isDateOnly) {
                $d = getdate($ts);
                $ts = mktime(0, 0, 0, $d['mon'], $d['mday'], $d['year']);
            }
            return date($includeTime ? 'd/m/Y H:i:s' : 'd/m/Y', $ts);
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatUtcDateTime($value): ?string
    {
        if (empty($value))
            return null;

        try {
            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value)
                    ->setTimezone('America/Sao_Paulo')
                    ->format('d/m/Y H:i:s');
            }

            return Carbon::parse((string) $value, 'UTC')
                ->setTimezone('America/Sao_Paulo')
                ->format('d/m/Y H:i:s');
        } catch (\Throwable) {
            return $this->formatDate($value, false, true);
        }
    }

    private function toFloat($val): ?float
    {
        if ($val === null || $val === '')
            return null;
        $s = preg_replace('/[^0-9.,-]/', '', (string) $val);
        if ($s === '')
            return null;
        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');
        if ($lastDot === false && $lastComma === false)
            return is_numeric($s) ? (float) $s : null;
        $dec = ($lastDot !== false && $lastComma !== false) ? (($lastDot > $lastComma) ? '.' : ',') : (($lastDot !== false) ? '.' : ',');
        $th = ($dec === '.') ? ',' : '.';
        $n = str_replace($th, '', $s);
        $n = str_replace($dec, '.', $n);
        if (substr_count($n, '.') > 1)
            $n = preg_replace('/\.(?=.*\.)/', '', $n);
        return is_numeric($n) ? (float) $n : null;
    }

    private function cpfDigits($val): ?string
    {
        if ($val === null || $val === '')
            return null;
        $d = preg_replace('/\D+/', '', (string) $val) ?? '';
        $d = ltrim($d, '0');
        if ($d === '')
            $d = '0';
        return $d;
    }
}
