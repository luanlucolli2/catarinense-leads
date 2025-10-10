<?php

namespace App\Jobs;

use App\Exports\CltConsultExport;
use App\Models\CltConsultJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class GenerateCltPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public function __construct(public int $jobId)
    {
        $this->onQueue((string) config('facta_off.preview.queue', 'reports'));
    }

    public function handle(): void
    {
        $job = CltConsultJob::query()->whereKey($this->jobId)->first();
        if (!$job) {
            Log::warning("[CLT][PREVIEW] Job {$this->jobId} não encontrado.");
            return;
        }

        // cancelado não gera
        $status = DB::table('clt_consult_jobs')->where('id', $job->id)->value('status');
        if ($status === 'cancelado') {
            $this->markNone($job);
            return;
        }

        $diskName = (string) config('cltfacta.storage.reports_disk', 'local');
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        if (empty($job->spool_path) || !$disk->exists($job->spool_path)) {
            $this->markError($job, 'Prévia indisponível: spool ausente.');
            return;
        }

        $spoolBytesAtStart = (int) ($job->spool_bytes ?? 0);

        $job->update([
            'preview_status' => 'running',
            'preview_started_at' => Carbon::now(),
            'preview_error' => null,
        ]);

        $snapshotRel = null;

        try {
            $finalPrefix = (string) config('cltfacta.storage.final_prefix', 'clt-consulta');
            $dirPreviews = (string) config('cltfacta.storage.dir_previews', 'clt-previews');
            if (!$disk->exists($dirPreviews)) {
                $disk->makeDirectory($dirPreviews);
            }

            $fileName = $job->preview_name ?: "{$finalPrefix}_{$job->id}_preview.xlsx";
            $tmpName  = preg_replace('/\.xlsx$/', '.tmp.xlsx', $fileName);
            $path     = "{$dirPreviews}/{$fileName}";
            $tmpPath  = "{$dirPreviews}/{$tmpName}";

            // ===== Snapshot do spool (sem lock prolongado) =====
            $spoolReal = $disk->path($job->spool_path);
            $snapshotRel  = "{$dirPreviews}/{$finalPrefix}_{$job->id}_spool_snapshot.csv";
            $snapshotReal = $disk->path($snapshotRel);

            $in  = @fopen($spoolReal, 'r');
            $out = @fopen($snapshotReal, 'w');
            if (!is_resource($in) || !is_resource($out)) {
                if (is_resource($in)) fclose($in);
                if (is_resource($out)) fclose($out);
                throw new \RuntimeException("Falha ao criar snapshot do spool");
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);

            $processed = 0;
            $logEvery = (int) max(5000, (int) env('CLT_PREVIEW_LOG_EVERY', 20000));

            // Generator lendo o SNAPSHOT (sem flock)
            $iteratorFactory = function () use ($snapshotReal, &$processed, $logEvery, $job): \Generator {
                $fh = fopen($snapshotReal, 'r');
                if ($fh === false) {
                    Log::warning("[CLT][PREVIEW] Job {$job->id} falha ao abrir snapshot para leitura.");
                    return;
                }
                $t0 = microtime(true);
                try {
                    // pula cabeçalho
                    fgetcsv($fh, 0, ';');
                    while (($data = fgetcsv($fh, 0, ';')) !== false) {
                        $assoc = [];
                        foreach (CltConsultExport::COLS as $i => $k) {
                            $assoc[$k] = $data[$i] ?? null;
                        }
                        $processed++;

                        if (($processed % $logEvery) === 0) {
                            $elapsed = max(0.001, microtime(true) - $t0);
                            $rps = number_format($processed / $elapsed, 1);
                            Log::debug("[CLT][PREVIEW] job={$job->id} progresso={$processed} linhas (~{$rps} lps)");
                        }

                        yield $assoc;
                    }
                } finally {
                    fclose($fh);
                }
            };

            $export = CltConsultExport::fromGenerator($iteratorFactory);
            Excel::store($export, $tmpPath, $diskName);
            $disk->move($tmpPath, $path);

            if (!$disk->exists($path)) {
                throw new \RuntimeException("Prévia não encontrada após move: {$path}");
            }

            // cancelado durante a geração?
            $statusAfter = DB::table('clt_consult_jobs')->where('id', $job->id)->value('status');
            if ($statusAfter === 'cancelado') {
                try { $disk->delete($path); } catch (Throwable) {}
                $this->markNone($job);
                return;
            }

            $sizeBytes = 0;
            try {
                $sizeBytes = (int) $disk->size($path);
            } catch (Throwable) {
            }

            $job->update([
                'preview_disk' => $diskName,
                'preview_path' => $path,
                'preview_name' => $fileName,
                'preview_updated_at' => Carbon::now(),
                'preview_status' => 'ready',
                'preview_finished_at' => Carbon::now(),
                'preview_size_bytes' => $sizeBytes,
                'preview_rows' => $processed, // somente processados
                'preview_error' => null,
            ]);

            // limpa dirty se o spool não mudou
            DB::table('clt_consult_jobs')
                ->where('id', $job->id)
                ->where('spool_bytes', $spoolBytesAtStart)
                ->update(['preview_dirty' => false, 'updated_at' => Carbon::now()]);

            Log::info("[CLT][PREVIEW] Concluída (job={$job->id}) linhas={$processed} size={$sizeBytes}B path={$path}");
        } catch (Throwable $e) {
            Log::warning("[CLT][PREVIEW] Job {$job->id} falhou: " . $e->getMessage());
            $this->markError($job, $e->getMessage());
        } finally {
            // Apaga snapshot (se criado)
            if ($snapshotRel) {
                try {
                    $disk = Storage::disk((string) config('cltfacta.storage.reports_disk', 'local'));
                    if ($disk->exists($snapshotRel)) {
                        $disk->delete($snapshotRel);
                    }
                } catch (Throwable) {}
            }
        }
    }

    private function markNone(CltConsultJob $job): void
    {
        $job->update([
            'preview_status' => 'none',
            'preview_requested_at' => null,
            'preview_started_at' => null,
            'preview_finished_at' => null,
            'preview_size_bytes' => 0,
            'preview_rows' => 0,
            'preview_error' => null,
        ]);
    }

    private function markError(CltConsultJob $job, string $msg): void
    {
        $job->update([
            'preview_status' => 'error',
            'preview_finished_at' => Carbon::now(),
            'preview_error' => mb_strimwidth($msg, 0, 1000, '…', 'UTF-8'),
        ]);
    }
}
