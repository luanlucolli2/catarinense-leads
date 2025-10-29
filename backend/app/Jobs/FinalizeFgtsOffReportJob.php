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

            // ---- Normalização do CSV final (BOM/EOL) + cabeçalho normalizado
            $embedBom   = (bool) config('facta_off.csv.embed_bom', true);
            $finalEol   = strtoupper((string) config('facta_off.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";

            $srcReal = $disk->path($spoolPath);
            $tmpReal = $disk->path("{$dirReports}/.{$fileName}.tmp");

            $in  = @fopen($srcReal, 'rb');
            $out = @fopen($tmpReal, 'wb');
            if ($in === false || $out === false) {
                if (is_resource($in)) fclose($in);
                if (is_resource($out)) fclose($out);
                throw new \RuntimeException("Falha ao abrir streams para promover CSV final.");
            }

            try {
                // Trata BOM de origem
                $peek = fread($in, 3);
                if ($peek !== "\xEF\xBB\xBF") {
                    // não havia BOM → volta ao início
                    fseek($in, 0);
                }

                // Escreve BOM final (se configurado)
                if ($embedBom) {
                    fwrite($out, "\xEF\xBB\xBF");
                }

                // Escreve cabeçalho normalizado
                fwrite($out, \App\Support\FgtsOffSchema::headerCsvLine(';') . $finalEol);

                // Pula a 1ª linha do arquivo de origem (cabeçalho antigo)
                fgets($in);

                // Copia o restante normalizando EOL
                while (!feof($in)) {
                    $chunk = fread($in, 1024 * 256);
                    if ($chunk === false) break;

                    // normaliza CRLF->LF, depois LF->final
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

            // move tmp -> destino no disk
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
