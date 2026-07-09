<?php

namespace App\Modules\HubCredito\Jobs;

use App\Modules\HubCredito\Models\HubCreditoConsultJob;
use App\Modules\HubCredito\Support\HubCreditoSchema;
use App\Modules\HubCredito\Support\HubCreditoSpool;
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

class FinalizeHubCreditoConsultReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public function __construct(public int $jobId, private string $targetStatus)
    {
        $this->onQueue((string) config('hubcredito.preview.queue', 'reports'));
        $this->targetStatus = in_array($targetStatus, ['concluido', 'falhou'], true) ? $targetStatus : 'falhou';
    }

    public function handle(): void
    {
        $job = HubCreditoConsultJob::query()->whereKey($this->jobId)->first();
        if ($job === null) {
            return;
        }

        if ($job->status === 'cancelado') {
            $this->finishCancelledPreservingUsefulPreview($job);
            return;
        }

        $diskName = (string) config('hubcredito.storage.reports_disk', 'local');
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        $spoolPath = $job->spool_path ?? null;
        if (!$spoolPath || !$disk->exists($spoolPath)) {
            Log::warning("[HUBCREDITO] Finalização sem spool (job {$job->id}).");
            $this->finishWithoutFinal($job, $this->targetStatus);
            return;
        }

        try {
            $finalPrefix = (string) config('hubcredito.storage.final_prefix', 'hubcredito-consulta');
            $dirReports = (string) config('hubcredito.storage.dir_reports', 'hubcredito-reports');
            if (!$disk->exists($dirReports)) {
                $disk->makeDirectory($dirReports);
            }
            $this->normalizeLocalPermissions($disk, $dirReports, true);

            $ts = Carbon::now()->format('Ymd_His');
            $fileName = "{$finalPrefix}_{$job->id}_{$ts}.csv";
            $path = "{$dirReports}/{$fileName}";

            $embedBom = (bool) config('hubcredito.csv.embed_bom', true);
            $finalEol = strtoupper((string) config('hubcredito.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";

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

                fwrite($out, HubCreditoSchema::headerCsvLine(';') . $finalEol);
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
            $this->normalizeLocalPermissions($disk, $path, false);

            if (!$disk->exists($path)) {
                throw new \RuntimeException("Arquivo FINAL não encontrado após promover CSV: {$path}");
            }

            $job->update([
                'file_disk' => $diskName,
                'file_path' => $path,
                'file_name' => $fileName,
            ]);
        } catch (Throwable $e) {
            Log::error("[HUBCREDITO] Finalização falhou (job {$job->id}): {$e->getMessage()}", [
                'exception' => $e,
            ]);
            $this->finishWithoutFinal($job, 'falhou');
            return;
        }

        $this->cleanupSpool($job);
        $job->update([
            'status' => $this->targetStatus,
            'phase' => null,
            'finished_at' => Carbon::now(),
        ]);
        $this->logWarning($job, 'Relatorio finalizado.', [
            'target_status' => $this->targetStatus,
            'file_name' => $job->file_name,
        ]);
    }

    private function finishWithoutFinal(HubCreditoConsultJob $job, string $status): void
    {
        $this->cleanupSpool($job);
        $job->update([
            'status' => $status,
            'phase' => null,
            'finished_at' => Carbon::now(),
        ]);
        $this->logWarning($job, 'Job finalizado sem arquivo final.', [
            'target_status' => $status,
        ]);
    }

    private function finishCancelledPreservingUsefulPreview(HubCreditoConsultJob $job): void
    {
        $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
        $spoolPath = $job->spool_path ?? null;
        $hasDataRows = HubCreditoSpool::hasDataRows($disk, $spoolPath);

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
            $inputsPath = $job->spool_inputs_path ?? null;
            if ($inputsPath && $disk->exists($inputsPath)) {
                $disk->delete($inputsPath);
            }

            $dirSpool = (string) config('hubcredito.storage.dir_spool', 'hubcredito-spool');
            $prefix = (string) config('hubcredito.storage.final_prefix', 'hubcredito-consulta') . '_' . $job->id;
            if ($disk->exists($dirSpool)) {
                foreach ($disk->files($dirSpool) as $rel) {
                    if ($rel === $spoolPath) {
                        continue;
                    }
                    if (str_starts_with(basename($rel), $prefix)) {
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
            'spool_inputs_path' => null,
            'spool_bytes' => $spoolBytes,
        ]);
        $this->logWarning($job, 'Job cancelado preservando preview.', [
            'spool_bytes' => $spoolBytes,
        ]);
    }

    private function logWarning(HubCreditoConsultJob $job, string $message, array $context = []): void
    {
        if (!(bool) config('hubcredito.logging.enabled', false)) {
            return;
        }

        try {
            Log::warning("[HUBCREDITO] {$message}", array_merge([
                'job_id' => $job->id,
            ], $context));
        } catch (Throwable) {
        }
    }

    private function cleanupSpool(HubCreditoConsultJob $job): void
    {
        try {
            $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
            foreach (['spool_path', 'spool_inputs_path'] as $field) {
                $path = $job->{$field} ?? null;
                if ($path && $disk->exists($path)) {
                    try {
                        $disk->delete($path);
                    } catch (Throwable) {
                    }
                }
            }

            $dirSpool = (string) config('hubcredito.storage.dir_spool', 'hubcredito-spool');
            $prefix = (string) config('hubcredito.storage.final_prefix', 'hubcredito-consulta') . '_' . $job->id;
            if ($disk->exists($dirSpool)) {
                foreach ($disk->files($dirSpool) as $rel) {
                    if (str_starts_with(basename($rel), $prefix)) {
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
                'spool_inputs_path' => null,
                'spool_bytes' => 0,
            ]);
        }
    }

    private function normalizeLocalPermissions(FilesystemAdapter $disk, string $path, bool $directory): void
    {
        try {
            if ((string) config('hubcredito.storage.reports_disk', 'local') !== 'local') {
                return;
            }

            $realPath = $disk->path($path);
            if (!file_exists($realPath)) {
                return;
            }

            @chmod($realPath, $directory ? 0775 : 0664);
        } catch (Throwable) {
        }
    }
}
