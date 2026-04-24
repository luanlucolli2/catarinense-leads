<?php

namespace App\Modules\Presenca\Jobs;

use App\Modules\Presenca\Models\PresencaConsultJob;
use App\Modules\Presenca\Support\PresencaLog;
use App\Modules\Presenca\Support\PresencaSchema;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FinalizePresencaConsultReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var 'concluido'|'falhou' */
    public string $targetStatus;
    public int $timeout = 7200;

    public function __construct(public int $jobId, string $targetStatus)
    {
        $this->onQueue((string) config('presenca.preview.queue', 'reports'));
        $this->targetStatus = in_array($targetStatus, ['concluido', 'falhou'], true) ? $targetStatus : 'falhou';
    }

    public function handle(): void
    {
        $job = PresencaConsultJob::query()->whereKey($this->jobId)->first();
        if (!$job) {
            return;
        }

        if ($job->status === 'cancelado') {
            $this->finishWithoutFinal($job, 'cancelado');
            return;
        }

        if ($job->status === 'pausado') {
            return;
        }

        $diskName = (string) config('presenca.storage.reports_disk', 'local');
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        $spoolPath = $job->spool_path ?? null;
        if (!$spoolPath || !$disk->exists($spoolPath)) {
            $effectiveStatus = $this->targetStatus === 'concluido' ? 'falhou' : $this->targetStatus;
            PresencaLog::warning("[PRESENCA] FINAL (job {$job->id}) spool ausente.", [
                'target_status' => $this->targetStatus,
                'effective_status' => $effectiveStatus,
                'spool_path' => $spoolPath,
                'disk' => $diskName,
            ]);
            $this->finishWithoutFinal($job, $effectiveStatus);
            return;
        }

        try {
            $finalPrefix = (string) config('presenca.storage.final_prefix', 'presenca-consulta');
            $dirReports = (string) config('presenca.storage.dir_reports', 'presenca-reports');
            if (!$disk->exists($dirReports)) {
                $disk->makeDirectory($dirReports);
            }
            $this->fixPathPermissions($disk->path($dirReports), true);

            $ts = Carbon::now()->format('Ymd_His');
            $fileName = "{$finalPrefix}_{$job->id}_{$ts}.csv";
            $path = "{$dirReports}/{$fileName}";

            $embedBom = (bool) config('presenca.csv.embed_bom', true);
            $finalEol = strtoupper((string) config('presenca.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";

            $srcReal = $disk->path($spoolPath);
            $finalReal = $disk->path($path);
            $tmpReal = "{$finalReal}.tmp";

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

                fwrite($out, PresencaSchema::headerCsvLine(';') . $finalEol);

                fgets($in); // descarta cabeçalho do spool

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

            if (!@rename($tmpReal, $finalReal)) {
                @unlink($tmpReal);
                throw new \RuntimeException('Falha ao promover CSV final para destino.');
            }

            if (!$disk->exists($path)) {
                throw new \RuntimeException("Arquivo FINAL não encontrado após promover CSV: {$path}");
            }

            $this->fixPathPermissions($finalReal, false);
            $this->fixPathPermissions(\dirname($finalReal), true);

            $job->update([
                'file_disk' => $diskName,
                'file_path' => $path,
                'file_name' => $fileName,
            ]);
        } catch (Throwable $e) {
            PresencaLog::error("[PRESENCA] FINAL (job {$job->id}) falhou: " . $e->getMessage(), [
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

        PresencaLog::info("[PRESENCA] FINAL (job {$job->id}) status={$this->targetStatus} concluído.");
    }

    private function finishWithoutFinal(PresencaConsultJob $job, string $status): void
    {
        $this->cleanupSpool($job);
        $job->update([
            'status' => $status,
            'phase' => null,
            'finished_at' => Carbon::now(),
        ]);
    }

    private function cleanupSpool(PresencaConsultJob $job): void
    {
        try {
            $disk = Storage::disk((string) config('presenca.storage.reports_disk', 'local'));
            foreach (['spool_path', 'spool_inputs_path'] as $field) {
                $path = $job->{$field} ?? null;
                if ($path && $disk->exists($path)) {
                    try {
                        $disk->delete($path);
                    } catch (Throwable) {
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

    private function fixPathPermissions(string $path, bool $isDir): void
    {
        if ($path === '' || !file_exists($path)) {
            return;
        }

        $uid = (int) env('WWWUSER', 1000);
        $gid = (int) env('WWWGROUP', 1000);

        @chown($path, $uid);
        @chgrp($path, $gid);
        @chmod($path, $isDir ? 0775 : 0664);
    }
}
