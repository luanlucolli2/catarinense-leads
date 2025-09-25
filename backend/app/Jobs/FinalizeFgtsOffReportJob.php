<?php

namespace App\Jobs;

use App\Exports\FgtsOfflineExport;
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
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class FinalizeFgtsOffReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var 'concluido'|'expirado'|'falhou' */
    public string $targetStatus;

    /** Timeout para conversão CSV→XLSX. */
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
        /** @var FgtsOfflineJob|null $job */
        $job = FgtsOfflineJob::query()->whereKey($this->jobId)->first();
        if (!$job) return;

        // Cancelado não gera final
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
            $fileName = "{$finalPrefix}_{$job->id}_{$ts}.xlsx";
            $tmpName  = "{$finalPrefix}_{$job->id}_{$ts}.tmp.xlsx";
            $path     = "{$dirReports}/{$fileName}";
            $tmpPath  = "{$dirReports}/{$tmpName}";

            $export = FgtsOfflineExport::fromCsv($disk->path($spoolPath));
            Excel::store($export, $tmpPath, $diskName);
            $disk->move($tmpPath, $path);

            if (!$disk->exists($path)) {
                throw new \RuntimeException("Arquivo FINAL não encontrado após move: {$path}");
            }

            $job->update([
                'file_disk' => $diskName,
                'file_path' => $path,
                'file_name' => $fileName,
            ]);
        } catch (Throwable $e) {
            Log::error("[FGTS-OFF] FINAL (job {$job->id}) falhou: ".$e->getMessage());

            // Sem final só marca status conforme semântica anterior
            if ($this->targetStatus === 'concluido') {
                $this->finishWithoutFinal($job, 'falhou');
                return;
            }

            $this->finishWithoutFinal($job, $this->targetStatus);
            return;
        }

        // Limpa spool e prévia e fecha com status alvo
        $this->cleanupSpool($job);
        $this->deletePreview($job);

        $job->update([
            'status'      => $this->targetStatus,
            'finished_at' => Carbon::now(),
        ]);

        Log::info("[FGTS-OFF] FINAL (job {$job->id}) status={$this->targetStatus} concluído.");
    }

    private function finishWithoutFinal(FgtsOfflineJob $job, string $status): void
    {
        $this->cleanupSpool($job);
        $this->deletePreview($job);

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

    private function deletePreview(FgtsOfflineJob $job): void
    {
        try {
            if ($job->preview_disk && $job->preview_path) {
                $disk = Storage::disk($job->preview_disk);
                if ($disk->exists($job->preview_path)) {
                    $disk->delete($job->preview_path);
                }
            }
        } catch (Throwable $e) {
            Log::warning("[FGTS-OFF] FINAL (job {$job->id}) falha ao apagar prévia: ".$e->getMessage());
        } finally {
            $job->updateQuietly([
                'preview_disk'        => null,
                'preview_path'        => null,
                'preview_name'        => null,
                'preview_updated_at'  => null,
                'preview_dirty'       => false,
                'preview_status'      => 'none',
                'preview_requested_at'=> null,
                'preview_started_at'  => null,
                'preview_finished_at' => null,
                'preview_size_bytes'  => 0,
                'preview_rows'        => 0,
                'preview_error'       => null,
            ]);
        }
    }
}
