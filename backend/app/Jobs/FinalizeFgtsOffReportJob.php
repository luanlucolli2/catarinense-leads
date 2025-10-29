<?php

namespace App\Jobs;

use App\Models\FgtsOfflineJob;
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

class FinalizeFgtsOffReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var 'concluido'|'expirado'|'falhou' */
    public string $targetStatus;

    public int $timeout = 7200;

    public function __construct(public int $jobId, string $targetStatus)
    {
        $this->onQueue((string) config('facta_off.preview.queue', 'reports'));
        $this->targetStatus = in_array($targetStatus, ['concluido','expirado','falhou'], true)
            ? $targetStatus
            : 'falhou';
    }

    public function handle(): void
    {
        $job = FgtsOfflineJob::query()->whereKey($this->jobId)->first();
        if (!$job) return;

        if ($job->status === 'cancelado') {
            $this->finishWithoutFinal($job, 'cancelado');
            return;
        }

        $diskName = (string) config('facta_off.storage.reports_disk', 'public');
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        $spoolPath = $job->spool_path ?? null;
        if (!$spoolPath || !$disk->exists($spoolPath)) {
            Log::warning("[FGTS-OFF] FINAL (job {$job->id}) spool ausente.");
            $this->finishWithoutFinal($job, $this->targetStatus);
            return;
        }

        try {
            $finalPrefix = (string) config('facta_off.storage.final_prefix', 'fgts-offline');
            $dirReports  = (string) config('facta_off.storage.dir_reports', 'fgts-off-reports');

            if (!$disk->exists($dirReports)) {
                $disk->makeDirectory($dirReports);
            }

            $ts       = Carbon::now()->format('Ymd_His');
            $fileName = "{$finalPrefix}_{$job->id}_{$ts}.csv";
            $path     = "{$dirReports}/{$fileName}";

            // promover o spool para CSV final
            $src = $disk->path($spoolPath);
            $tmp = $disk->path("{$dirReports}/.{$fileName}.tmp");

            // copy em vez de rename, para não travar em FS distintos
            if (!@copy($src, $tmp)) {
                throw new \RuntimeException("Falha ao copiar spool para tmp.");
            }
            // move tmp -> destino no disk
            $disk->put($path, fopen($tmp, 'rb'));
            @unlink($tmp);

            if (!$disk->exists($path)) {
                throw new \RuntimeException("Arquivo FINAL não encontrado após promover CSV: {$path}");
            }

            $job->update([
                'file_disk' => $diskName,
                'file_path' => $path,
                'file_name' => $fileName,
            ]);
        } catch (Throwable $e) {
            Log::error("[FGTS-OFF] FINAL (job {$job->id}) falhou: ".$e->getMessage());

            if ($this->targetStatus === 'concluido') {
                $this->finishWithoutFinal($job, 'falhou');
                return;
            }

            $this->finishWithoutFinal($job, $this->targetStatus);
            return;
        }

        $this->cleanupSpool($job);

        $job->update([
            'status'      => $this->targetStatus,
            'finished_at' => Carbon::now(),
        ]);

        Log::info("[FGTS-OFF] FINAL (job {$job->id}) status={$this->targetStatus} concluído.");
    }

    private function finishWithoutFinal(FgtsOfflineJob $job, string $status): void
    {
        $this->cleanupSpool($job);

        $job->update([
            'status'      => $status,
            'finished_at' => Carbon::now(),
        ]);
    }

    private function cleanupSpool(FgtsOfflineJob $job): void
    {
        try {
            $disk = Storage::disk((string) config('facta_off.storage.reports_disk', 'public'));
            foreach (['spool_path','spool_cpfs_path'] as $field) {
                $p = $job->{$field} ?? null;
                if ($p && $disk->exists($p)) {
                    try { $disk->delete($p); } catch (Throwable) {}
                }
            }
        } finally {
            $job->updateQuietly([
                'spool_path'      => null,
                'spool_cpfs_path' => null,
                'spool_bytes'     => 0,
            ]);
        }
    }
}
