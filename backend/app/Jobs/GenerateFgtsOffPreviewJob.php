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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class GenerateFgtsOffPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Timeout (segundos) */
    public int $timeout = 7200;

    public function __construct(public int $consultJobId)
    {
        // Fila dedicada (relatórios/prévias)
        $this->onQueue((string) config('facta_off.preview.queue', 'reports'));
    }

    public function handle(): void
    {
        /** @var FgtsOfflineJob|null $job */
        $job = FgtsOfflineJob::query()->whereKey($this->consultJobId)->first();
        if (!$job) {
            Log::warning("[FGTS-OFF][PREVIEW] Job {$this->consultJobId} não encontrado.");
            return;
        }

        // Cancelado: não gera
        $statusNow = DB::table('fgts_off_consult_jobs')->where('id', $job->id)->value('status');
        if ($statusNow === 'cancelado') {
            Log::info("[FGTS-OFF][PREVIEW] Job {$job->id} cancelado antes da geração de prévia.");
            $this->markNone($job);
            return;
        }

        $diskName = (string) config('facta_off.storage.reports_disk', 'public');
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        if (empty($job->spool_path) || !$disk->exists($job->spool_path)) {
            Log::warning("[FGTS-OFF][PREVIEW] Job {$job->id} sem spool; prévia indisponível.");
            $this->markError($job, 'Prévia indisponível: spool ausente.');
            return;
        }

        $spoolBytesAtStart = (int) ($job->spool_bytes ?? 0);

        $job->update([
            'preview_status' => 'running',
            'preview_started_at' => Carbon::now(),
            'preview_error' => null,
        ]);

        try {
            $finalPrefix = (string) config('facta_off.storage.final_prefix', 'fgts-offline');
            $dirPreviews = (string) config('facta_off.storage.dir_previews', 'fgts-off-previews');

            if (!$disk->exists($dirPreviews)) {
                $disk->makeDirectory($dirPreviews);
            }

            $fileName = $job->preview_name ?: "{$finalPrefix}_{$job->id}_preview.xlsx";
            $tmpName = preg_replace('/\.xlsx$/', '.tmp.xlsx', $fileName);
            $path = "{$dirPreviews}/{$fileName}";
            $tmpPath = "{$dirPreviews}/{$tmpName}";

            $spoolReal = $disk->path($job->spool_path);

            Log::info("[FGTS-OFF][PREVIEW] Iniciando geração (job={$job->id}) spool={$job->spool_path} bytes={$spoolBytesAtStart}");

            // Generator: SOMENTE linhas processadas do spool
            $processedCount = 0;
            $logEvery = (int) max(5000, env('FGTS_OFF_PREVIEW_LOG_EVERY', 20000)); // logs de progresso a cada N linhas

            $iteratorFactory = function () use ($spoolReal, &$processedCount, $logEvery, $job): \Generator {
                $fh = fopen($spoolReal, 'r');
                if ($fh === false) {
                    Log::warning("[FGTS-OFF][PREVIEW] Job {$job->id} falha ao abrir spool para leitura.");
                    return;
                }
                $t0 = microtime(true);
                try {
                    flock($fh, LOCK_SH);
                    // pula cabeçalho
                    fgetcsv($fh, 0, ';');
                    while (($data = fgetcsv($fh, 0, ';')) !== false) {
                        $assoc = [];
                        foreach (FgtsOfflineExport::COLS as $i => $key) {
                            $assoc[$key] = $data[$i] ?? null;
                        }
                        $processedCount++;

                        if (($processedCount % $logEvery) === 0) {
                            $elapsed = max(0.001, microtime(true) - $t0);
                            $rps = number_format($processedCount / $elapsed, 1);
                            Log::debug("[FGTS-OFF][PREVIEW] job={$job->id} progresso={$processedCount} linhas, ~{$rps} lps");
                        }

                        yield $assoc;
                    }
                } finally {
                    flock($fh, LOCK_UN);
                    fclose($fh);
                }
            };

            $export = FgtsOfflineExport::fromGenerator($iteratorFactory);
            Excel::store($export, $tmpPath, $diskName);
            $disk->move($tmpPath, $path);

            if (!$disk->exists($path)) {
                throw new \RuntimeException("Prévia não encontrada após move: {$path}");
            }

            // Cancelado durante a geração?
            $statusAfter = DB::table('fgts_off_consult_jobs')->where('id', $job->id)->value('status');
            if ($statusAfter === 'cancelado') {
                try {
                    $disk->delete($path);
                } catch (Throwable) {
                }
                Log::info("[FGTS-OFF][PREVIEW] Job {$job->id} cancelado durante a geração; descartando arquivo.");
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
                'preview_rows' => $processedCount, // somente processados
                'preview_error' => null,
            ]);

            // Limpa preview_dirty se spool não mudou
            DB::table('fgts_off_consult_jobs')
                ->where('id', $job->id)
                ->where('spool_bytes', $spoolBytesAtStart)
                ->update([
                    'preview_dirty' => false,
                    'updated_at' => Carbon::now(),
                ]);

            Log::info("[FGTS-OFF][PREVIEW] Concluída (job={$job->id}) linhas={$processedCount} size={$sizeBytes}B path={$path}");
        } catch (Throwable $e) {
            Log::warning("[FGTS-OFF][PREVIEW] Job {$job->id} falhou: " . $e->getMessage());
            $this->markError($job, $e->getMessage());
        }
    }

    private function markNone(FgtsOfflineJob $job): void
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

    private function markError(FgtsOfflineJob $job, string $message): void
    {
        $job->update([
            'preview_status' => 'error',
            'preview_finished_at' => Carbon::now(),
            'preview_error' => mb_strimwidth($message, 0, 1000, '…', 'UTF-8'),
        ]);
    }
}
