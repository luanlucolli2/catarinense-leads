<?php

namespace App\Modules\V8Fgts\Jobs;

use App\Modules\V8Fgts\Models\V8FgtsConsultJob;
use App\Modules\V8Fgts\Support\V8FgtsSchema;
use App\Modules\V8Fgts\Support\V8FgtsSpool;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FinalizeV8FgtsConsultReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $targetStatus;
    public int $timeout = 7200;

    public function __construct(public int $jobId, string $targetStatus)
    {
        $this->onQueue((string) config('v8_fgts.preview.queue', 'reports'));
        $this->targetStatus = in_array($targetStatus, ['concluido', 'falhou'], true) ? $targetStatus : 'falhou';
    }

    public function handle(): void
    {
        $job = V8FgtsConsultJob::query()->whereKey($this->jobId)->first();
        if (!$job) {
            return;
        }

        if ($job->status === 'cancelado') {
            $this->finishCancelledPreservingUsefulPreview($job);
            return;
        }

        $diskName = (string) config('v8_fgts.storage.reports_disk', 'local');
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        $spoolPath = $job->spool_path ?? null;
        if (!$spoolPath || !$disk->exists($spoolPath)) {
            Log::warning("[V8-FGTS] FINAL (job {$job->id}) spool ausente.");
            $this->finishWithoutFinal($job, $this->targetStatus);
            return;
        }

        try {
            $finalPrefix = (string) config('v8_fgts.storage.final_prefix', 'v8-fgts-consulta');
            $dirReports = (string) config('v8_fgts.storage.dir_reports', 'v8-fgts-reports');

            if (!$disk->exists($dirReports)) {
                $disk->makeDirectory($dirReports);
            }

            $ts = Carbon::now()->format('Ymd_His');
            $fileName = "{$finalPrefix}_{$job->id}_{$ts}.csv";
            $path = "{$dirReports}/{$fileName}";
            $embedBom = (bool) config('v8_fgts.csv.embed_bom', true);
            $finalEol = strtoupper((string) config('v8_fgts.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";
            $srcReal = $disk->path($spoolPath);
            $tmpReal = $disk->path("{$dirReports}/.{$fileName}.tmp");

            $in = @fopen($srcReal, 'rb');
            $out = @fopen($tmpReal, 'wb');
            if ($in === false || $out === false) {
                if (is_resource($in)) {
                    fclose($in);
                }
                if (is_resource($out)) {
                    fclose($out);
                }
                throw new \RuntimeException('Falha ao abrir streams para promover CSV final.');
            }

            try {
                $peek = fread($in, 3);
                if ($peek !== "\xEF\xBB\xBF") {
                    fseek($in, 0);
                }

                if ($embedBom) {
                    fwrite($out, "\xEF\xBB\xBF");
                }

                fwrite($out, V8FgtsSchema::headerCsvLine(';') . $finalEol);
                fgets($in);

                while (!feof($in)) {
                    $chunk = fread($in, 1024 * 256);
                    if ($chunk === false) {
                        break;
                    }

                    $chunk = str_replace("\r\n", "\n", $chunk);
                    if ($finalEol === "\r\n") {
                        $chunk = str_replace("\n", "\r\n", $chunk);
                    }
                    fwrite($out, $chunk);
                }
            } finally {
                fclose($in);
                fflush($out);
                fclose($out);
            }

            $disk->put($path, fopen($tmpReal, 'rb'));
            @unlink($tmpReal);

            if (!$disk->exists($path)) {
                throw new \RuntimeException("Arquivo FINAL não encontrado após promover CSV: {$path}");
            }

            $job->update([
                'file_disk' => $diskName,
                'file_path' => $path,
                'file_name' => $fileName,
            ]);
        } catch (Throwable $e) {
            Log::error("[V8-FGTS] FINAL (job {$job->id}) falhou: " . $e->getMessage());
            $this->finishWithoutFinal($job, 'falhou');
            return;
        }

        $this->cleanupSpool($job);

        $job->update([
            'status' => $this->targetStatus,
            'phase' => null,
            'finished_at' => Carbon::now(),
        ]);
    }

    private function finishWithoutFinal(V8FgtsConsultJob $job, string $status): void
    {
        $this->cleanupSpool($job);
        $job->update([
            'status' => $status,
            'phase' => null,
            'finished_at' => Carbon::now(),
        ]);
    }

    private function finishCancelledPreservingUsefulPreview(V8FgtsConsultJob $job): void
    {
        $disk = Storage::disk((string) config('v8_fgts.storage.reports_disk', 'local'));
        $spoolPath = $job->spool_path ?? null;
        $hasDataRows = V8FgtsSpool::hasDataRows($disk, $spoolPath);

        if (!$hasDataRows) {
            $this->cleanupSpool($job);
            $job->update([
                'status' => 'cancelado',
                'phase' => null,
                'finished_at' => $job->finished_at ?? Carbon::now(),
            ]);
            return;
        }

        try {
            $cpfsPath = $job->spool_cpfs_path ?? null;
            if ($cpfsPath && $disk->exists($cpfsPath)) {
                $disk->delete($cpfsPath);
            }

            $dirSpool = (string) config('v8_fgts.storage.dir_spool', 'v8-fgts-spool');
            $prefix = (string) config('v8_fgts.storage.final_prefix', 'v8-fgts-consulta') . '_' . $job->id;

            if ($disk->exists($dirSpool)) {
                foreach ($disk->files($dirSpool) as $rel) {
                    $base = basename($rel);
                    if ($rel === $spoolPath) {
                        continue;
                    }
                    if (str_starts_with($base, $prefix)) {
                        try {
                            $disk->delete($rel);
                        } catch (Throwable) {
                        }
                    }
                }
            }
        } catch (Throwable) {
        }

        try {
            $spoolBytes = $spoolPath && $disk->exists($spoolPath) ? (int) $disk->size($spoolPath) : 0;
        } catch (Throwable) {
            $spoolBytes = 0;
        }

        $job->update([
            'status' => 'cancelado',
            'phase' => null,
            'finished_at' => $job->finished_at ?? Carbon::now(),
            'spool_cpfs_path' => null,
            'spool_bytes' => $spoolBytes,
        ]);
    }

    private function cleanupSpool(V8FgtsConsultJob $job): void
    {
        try {
            $disk = Storage::disk((string) config('v8_fgts.storage.reports_disk', 'local'));
            foreach (['spool_path', 'spool_cpfs_path'] as $field) {
                $path = $job->{$field} ?? null;
                if ($path && $disk->exists($path)) {
                    try {
                        $disk->delete($path);
                    } catch (Throwable) {
                    }
                }
            }

            $dirSpool = (string) config('v8_fgts.storage.dir_spool', 'v8-fgts-spool');
            $prefix = (string) config('v8_fgts.storage.final_prefix', 'v8-fgts-consulta') . '_' . $job->id;

            if ($disk->exists($dirSpool)) {
                foreach ($disk->files($dirSpool) as $rel) {
                    $base = basename($rel);
                    if (str_starts_with($base, $prefix)) {
                        try {
                            $disk->delete($rel);
                        } catch (Throwable) {
                        }
                    }
                }
            }
        } finally {
            $job->updateQuietly([
                'spool_path' => null,
                'spool_cpfs_path' => null,
                'spool_bytes' => 0,
            ]);
        }
    }
}
