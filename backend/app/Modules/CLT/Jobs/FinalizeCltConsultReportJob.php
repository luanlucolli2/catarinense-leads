<?php

namespace App\Modules\CLT\Jobs;

use App\Modules\CLT\Models\CltConsultJob;
use App\Modules\CLT\Support\CltLog;
use App\Modules\CLT\Support\CltSchema;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FinalizeCltConsultReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var 'concluido'|'falhou' */
    public string $targetStatus;
    public int $timeout = 7200;

    public function __construct(public int $jobId, string $targetStatus)
    {
        $this->onQueue((string) config('cltfacta.preview.queue', 'reports'));
        $this->targetStatus = in_array($targetStatus, ['concluido', 'falhou'], true) ? $targetStatus : 'falhou';
    }

    public function handle(): void
    {
        $job = CltConsultJob::query()->whereKey($this->jobId)->first();
        if (!$job)
            return;
        if ($job->status === 'cancelado') {
            $this->finishWithoutFinal($job, $job->status);
            return;
        }

        $diskName = (string) config('cltfacta.storage.reports_disk', 'local');
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        $spoolPath = $job->spool_path ?? null;
        if (!$spoolPath || !$disk->exists($spoolPath)) {
            $effectiveStatus = $this->targetStatus === 'concluido' ? 'falhou' : $this->targetStatus;
            CltLog::warning("[CLT] FINAL (job {$job->id}) spool ausente.", [
                'target_status' => $this->targetStatus,
                'effective_status' => $effectiveStatus,
                'spool_path' => $spoolPath,
                'disk' => $diskName,
            ]);
            $this->finishWithoutFinal($job, $effectiveStatus);
            return;
        }

        try {
            $finalPrefix = (string) config('cltfacta.storage.final_prefix', 'clt-consulta');
            $dirReports = (string) config('cltfacta.storage.dir_reports', 'clt-reports');
            if (!$disk->exists($dirReports))
                $disk->makeDirectory($dirReports);

            $ts = Carbon::now()->format('Ymd_His');
            $fileName = "{$finalPrefix}_{$job->id}_{$ts}.csv";
            $path = "{$dirReports}/{$fileName}";

            // Normalização (BOM/EOL) + cabeçalho normalizado
            $embedBom = (bool) config('cltfacta.csv.embed_bom', true);
            $finalEol = strtoupper((string) config('cltfacta.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";

            $srcReal = $disk->path($spoolPath);
            $finalReal = $disk->path($path);
            $tmpReal = "{$finalReal}.tmp";

            $in = @fopen($srcReal, 'rb');
            $out = @fopen($tmpReal, 'wb');
            if ($in === false || $out === false) {
                if (is_resource($in))
                    fclose($in);
                if (is_resource($out))
                    fclose($out);
                throw new \RuntimeException("Falha ao abrir streams para promover CSV final.");
            }

            try {
                // Trata possível BOM de origem
                $peek = fread($in, 3);
                if ($peek !== "\xEF\xBB\xBF") {
                    fseek($in, 0);
                }

                // Escreve BOM final (se configurado)
                if ($embedBom) {
                    fwrite($out, "\xEF\xBB\xBF");
                }

                // Escreve cabeçalho normalizado
                fwrite($out, CltSchema::headerCsvLine(';') . $finalEol);

                // Pula a 1ª linha do arquivo de origem (cabeçalho antigo, embora já seja TITLES)
                fgets($in);

                // Copia o restante normalizando EOL
                while (!feof($in)) {
                    $chunk = fread($in, 1024 * 256);
                    if ($chunk === false)
                        break;

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

            // promoção local atômica (evita segunda cópia completa do arquivo)
            if (!@rename($tmpReal, $finalReal)) {
                @unlink($tmpReal);
                throw new \RuntimeException("Falha ao promover CSV final para destino.");
            }

            if (!$disk->exists($path)) {
                throw new \RuntimeException("Arquivo FINAL não encontrado após promover CSV: {$path}");
            }

            $job->update(['file_disk' => $diskName, 'file_path' => $path, 'file_name' => $fileName]);
        } catch (Throwable $e) {
            CltLog::error("[CLT] FINAL (job {$job->id}) falhou: " . $e->getMessage());
            $this->finishWithoutFinal($job, 'falhou');
            return;
        }

        $this->cleanupSpool($job);

        $job->update(['status' => $this->targetStatus, 'phase' => null, 'finished_at' => Carbon::now()]);
        CltLog::info("[CLT] FINAL (job {$job->id}) status={$this->targetStatus} concluído.");
    }

    private function finishWithoutFinal(CltConsultJob $job, string $status): void
    {
        $this->cleanupSpool($job);
        $job->update(['status' => $status, 'phase' => null, 'finished_at' => Carbon::now()]);
    }

    private function cleanupSpool(CltConsultJob $job): void
    {
        try {
            $disk = Storage::disk((string) config('cltfacta.storage.reports_disk', 'local'));
            $spoolPath = $job->spool_path ?? null;
            $targets = [
                $spoolPath,
                $job->spool_cpfs_path ?? null,
                $spoolPath ? "{$spoolPath}.phase2.tmp" : null,
                $spoolPath ? "{$spoolPath}.phase2.delta.ndjson" : null,
                $spoolPath ? "{$spoolPath}.phase2.pending.ndjson" : null,
                $spoolPath ? "{$spoolPath}.phase2.pending.ndjson.next" : null,
            ];
            if ($spoolPath) {
                $maxAttempts = max(1, (int) config('cltfacta.credit_worker.phase2_max_attempts', 3));
                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    $targets[] = "{$spoolPath}.phase2.delta.a{$attempt}.ndjson";
                }
            }
            foreach ($targets as $p) {
                if ($p && $disk->exists($p)) {
                    try {
                        $disk->delete($p);
                    } catch (Throwable) {
                    }
                }
            }
        } finally {
            $job->updateQuietly(['spool_path' => null, 'spool_cpfs_path' => null, 'spool_bytes' => 0, 'phase' => null]);
        }
    }
}
