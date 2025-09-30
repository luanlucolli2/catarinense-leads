<?php

namespace App\Jobs;

use App\Exports\FgtsOfflineExport;
use App\Models\FgtsOfflineJob;
use App\Services\FactaOfflineApiService;
use App\Support\Cpf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessFgtsOfflineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Timeout por job (segundos). */
    public int $timeout;

    private int $jobId;

    /** Dinâmica */
    private array $cpfs = [];         // válidos
    private array $invalidCpfs = [];  // inválidos (DV inválido)

    /** Storage / Spool */
    private string $disk;
    private string $dirReports;
    private string $dirPreviews;
    private string $dirSpool;
    private string $finalPrefix;

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;

        $this->onQueue((string) config('facta_off.job.queue', 'fgts'));

        // Configs
        $this->timeout     = (int) config('facta_off.job.timeout_seconds', 18000);
        $this->disk        = (string) config('facta_off.storage.reports_disk', 'public');
        $this->dirReports  = (string) config('facta_off.storage.dir_reports', 'fgts-off-reports');
        $this->dirPreviews = (string) config('facta_off.storage.dir_previews', 'fgts-off-previews');
        $this->dirSpool    = (string) (config('facta_off.storage.dir_spool') ?? 'fgts-off-spool');
        $this->finalPrefix = (string) config('facta_off.storage.final_prefix', 'fgts-offline');
    }

    public function handle(FactaOfflineApiService $api): void
    {
        /** @var FgtsOfflineJob $job */
        $job = FgtsOfflineJob::query()->whereKey($this->jobId)->firstOrFail();

        if ($this->isCancelled()) {
            Log::info("[FGTS-OFF] Job {$this->jobId} já cancelado antes do início.");
            $this->deletePreview($job);
            return;
        }

        $deadlineUtc = $job->scheduled_until ? Carbon::parse($job->scheduled_until, 'UTC') : null;

        // Verifica spool criado pelo Controller
        $disk = Storage::disk($this->disk);
        if (
            empty($job->spool_path) || empty($job->spool_cpfs_path) ||
            !$disk->exists($job->spool_path) || !$disk->exists($job->spool_cpfs_path)
        ) {
            Log::error("[FGTS-OFF] Job {$this->jobId} sem spool pré-criado.");
            // fecha como falha sem final, mantendo semântica de erro
            dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue','reports'));
            return;
        }

        // Carrega CPFs (stream) e classifica sem payload no Job
        $this->classifyCpfsFromFile($disk->path($job->spool_cpfs_path));

        $job->update([
            'status'        => 'em_progresso',
            'started_at'    => Carbon::now(),
            'total_cpfs'    => count($this->cpfs) + count($this->invalidCpfs),
            'spool_bytes'   => $this->fileSizeSafe($this->disk, $job->spool_path),
            'preview_dirty' => false,
        ]);

        Log::info("[FGTS-OFF] Job {$this->jobId} iniciado – válidos: ".count($this->cpfs).", inválidos: ".count($this->invalidCpfs).", total: ".$job->total_cpfs);

        // Knobs
        $maxAttempts   = (int) config('facta_off.job.max_attempts', 5);
        $retryDelay    = (int) config('facta_off.job.retry_delay_seconds', 30);
        $chunkSize     = (int) config('facta_off.job.chunk', 6);
        $minChunk      = max(1, (int) config('facta_off.job.min_chunk', 2));
        $retryAfterCap = (int) config('facta_off.job.retry_after_max', 120);

        $pendentes   = $this->cpfs;
        $invalidCnt  = count($this->invalidCpfs);

        try {
            // 1) Inválidos já entram no SPOOL (contam como falha)
            foreach ($this->invalidCpfs as $cpfInv) {
                $row = $this->baseRow($cpfInv);
                $row['situacao']     = 'Não autorizado - CPF inválido (dígitos verificadores)';
                $row['consultadoEm'] = $this->nowBrString();
                $this->spoolAppend($job, $row);
            }
            if ($invalidCnt > 0) {
                $job->increment('fail_count', $invalidCnt);
            }

            if ($this->isExpired($deadlineUtc)) {
                // agenda final como expirado
                dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'expirado'))->onQueue((string) config('facta_off.preview.queue','reports'));
                return;
            }

            // 2) Tentativas com teimosinha
            $prevPendCount = count($pendentes);

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($this->finishIfCancelled($job)) return;
                if ($this->isExpired($deadlineUtc)) {
                    dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'expirado'))->onQueue((string) config('facta_off.preview.queue','reports'));
                    return;
                }
                if (empty($pendentes)) break;

                $toTry  = $pendentes;
                $chunks = array_chunk($toTry, max(1, $chunkSize));
                $chunkIndex = 0;

                $seen429InAttempt   = 0;
                $retryAfterMax      = 0;
                $successThisAttempt = 0;
                $semRespTotalAttempt= 0;
                $totalInAttempt     = 0;

                Log::debug("[FGTS-OFF] Job {$this->jobId} tentativa {$attempt} – pendentes: ".count($pendentes)." – chunkSize={$chunkSize}");

                foreach ($chunks as $chunkCpfs) {
                    $chunkIndex++;
                    $chunkCount = count($chunkCpfs);
                    $t0 = microtime(true);

                    if ($this->finishIfCancelled($job)) return;
                    if ($this->isExpired($deadlineUtc)) {
                        dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'expirado'))->onQueue((string) config('facta_off.preview.queue','reports'));
                        return;
                    }

                    $batchResults = $api->consultaCpfLote($chunkCpfs);

                    $stats = ['2xx'=>0,'401'=>0,'429'=>0,'5xx'=>0,'outros'=>0,'sem_resposta'=>0];
                    $authorizedInChunk     = 0;
                    $notAuthorizedInChunk  = 0;
                    $terminalFailsInChunk  = 0;

                    foreach ($chunkCpfs as $cpf) {
                        $res = $batchResults[$cpf] ?? [
                            'ok'=>false,'mensagem'=>'Sem resposta do serviço','authorized'=>null,
                            'authorized_until'=>null,'retriable'=>true,'http_status'=>null,
                            'retry_after'=>null,'consultado_at'=>$this->nowBrString(),
                        ];

                        $http = $res['http_status'] ?? null;
                        if ($http === 200) $stats['2xx']++;
                        elseif ($http === 401) $stats['401']++;
                        elseif ($http === 429) { $stats['429']++; $seen429InAttempt++; }
                        elseif (is_int($http) && $http >= 500) $stats['5xx']++;
                        elseif ($http === null) $stats['sem_resposta']++; else $stats['outros']++;

                        if (!empty($res['retry_after'])) {
                            $retryAfterMax = max($retryAfterMax, (int) $res['retry_after']);
                        }

                        if (!empty($res['ok'])) {
                            $row = $this->baseRow($cpf);
                            // ↓ Normalização da situação conforme regra nova
                            $aut = $res['authorized'] ?? null;
                            if ($aut === true) {
                                $row['situacao'] = 'Autorizado';
                                $authorizedInChunk++;
                            } else {
                                $row['situacao'] = 'Não autorizado';
                                $notAuthorizedInChunk++;
                            }
                            $row['consultadoEm'] = $res['consultado_at'] ?? $this->nowBrString();
                            $this->spoolAppend($job, $row);

                            $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                            $successThisAttempt++;
                        } else {
                            $msg = (string) ($res['mensagem'] ?? 'Falha na consulta');
                            $retriable = $res['retriable'] ?? true;

                            if ($retriable === false) {
                                $row = $this->baseRow($cpf);
                                $row['situacao']     = 'Não autorizado - ' . $msg;
                                $row['consultadoEm'] = $res['consultado_at'] ?? $this->nowBrString();
                                $this->spoolAppend($job, $row);

                                $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                                $terminalFailsInChunk++;
                            }
                        }
                    }

                    if ($authorizedInChunk > 0)     $job->increment('success_count', $authorizedInChunk);
                    if ($notAuthorizedInChunk > 0)  $job->increment('not_authorized_count', $notAuthorizedInChunk);
                    if ($terminalFailsInChunk > 0)  $job->increment('fail_count', $terminalFailsInChunk);

                    $semRespTotalAttempt += $stats['sem_resposta'];
                    $totalInAttempt      += $chunkCount;

                    $elapsed = max(0.001, microtime(true) - $t0);
                    $rps     = $chunkCount / $elapsed;
                    Log::debug("[FGTS-OFF] Job {$this->jobId} tentativa {$attempt} – chunk #{$chunkIndex} size={$chunkCount} stats=".json_encode($stats).
                        " auth={$authorizedInChunk} nao_auth={$notAuthorizedInChunk} fail_term={$terminalFailsInChunk} ".
                        "pend_rest=".count($pendentes)." elapsed=".number_format($elapsed,3)."s rps=".number_format($rps,2));
                }

                if ($this->finishIfCancelled($job)) return;

                $semRespRatio = $totalInAttempt > 0 ? ($semRespTotalAttempt / $totalInAttempt) : 0.0;
                if ($semRespRatio >= 0.50 && $chunkSize > $minChunk) {
                    $old = $chunkSize; $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                    Log::warning("[FGTS-OFF] Job {$this->jobId} – muitos sem_resposta (ratio=".round($semRespRatio,2)."). Reduzindo chunk {$old} → {$chunkSize}.");
                }
                if ($seen429InAttempt > 0 && $chunkSize > $minChunk) {
                    $old = $chunkSize; $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                    Log::warning("[FGTS-OFF] Job {$this->jobId} – 429 vistos. Reduzindo chunk {$old} → {$chunkSize}.");
                }

                Log::debug("[FGTS-OFF] Job {$this->jobId} tentativa {$attempt} – resumo: pendentes=".count($pendentes).
                    " sem_resp_ratio=".number_format($semRespRatio,2)." seen429={$seen429InAttempt} retry_after_max={$retryAfterMax} chunkSizeAtual={$chunkSize}");

                if (!empty($pendentes) && $attempt < $maxAttempts) {
                    if ($this->finishIfCancelled($job)) return;
                    if ($this->isExpired($deadlineUtc)) {
                        dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'expirado'))->onQueue((string) config('facta_off.preview.queue','reports'));
                        return;
                    }

                    $baseRetryAfter = $retryAfterMax > 0 ? min($retryAfterMax, $retryAfterCap) : 0;
                    $base = max(1, $retryDelay, $baseRetryAfter);

                    $sleepFactor = 1.0;
                    if ($semRespRatio >= 0.90)      $sleepFactor = 2.0;
                    elseif ($semRespRatio >= 0.50)  $sleepFactor = 1.5;

                    $withFactor = (int) ceil($base * $sleepFactor);
                    $jitter     = random_int(0, (int) max(1, ceil($withFactor * 0.15)));
                    $sleepSecs  = $withFactor + $jitter;

                    Log::debug("[FGTS-OFF] Job {$this->jobId} – dormindo {$sleepSecs}s.");
                    sleep($sleepSecs);
                }

                $currPendCount = count($pendentes);
                if ($currPendCount === $prevPendCount && $successThisAttempt === 0 && !empty($pendentes)) {
                    Log::warning("[FGTS-OFF] Job {$this->jobId} – sem progresso na tentativa {$attempt}.");
                }
                $prevPendCount = $currPendCount;
            }

            // 3) Finalização: delega geração do FINAL para fila de reports
            dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'concluido'))->onQueue((string) config('facta_off.preview.queue','reports'));
        } catch (Throwable $e) {
            Log::error("[FGTS-OFF] Job {$this->jobId} falhou durante processamento: ".$e->getMessage());
            // tenta ainda gerar FINAL e fecha como falhou (sem alterar dados)
            dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue','reports'));
        }
    }

    /** ----------------------- Helpers ----------------------- */

    private function classifyCpfsFromFile(string $cpfsRealPath): void
    {
        $fh = fopen($cpfsRealPath, 'r');
        if ($fh === false) return;

        try {
            while (($line = fgets($fh)) !== false) {
                $cpf = preg_replace('/\D+/', '', (string) $line);
                if ($cpf === '' || strlen($cpf) !== 11) continue;
                if (Cpf::isValid($cpf)) $this->cpfs[] = $cpf;
                else $this->invalidCpfs[] = $cpf;
            }
        } finally {
            fclose($fh);
        }
        // dedup
        $this->cpfs        = array_values(array_unique($this->cpfs));
        $this->invalidCpfs = array_values(array_diff(array_unique($this->invalidCpfs), $this->cpfs));
    }

    private function isCancelled(): bool
    {
        $status = DB::table('fgts_off_consult_jobs')->where('id', $this->jobId)->value('status');
        return $status === 'cancelado';
    }

    private function finishIfCancelled(FgtsOfflineJob $job): bool
    {
        if ($this->isCancelled()) {
            $job->update(['finished_at' => Carbon::now()]);
            $this->deletePreview($job);
            // cancelamento remove spool imediatamente
            $this->cleanupSpool($job);
            Log::info("[FGTS-OFF] Job {$this->jobId} interrompido por cancelamento (spool removido).");
            return true;
        }
        return false;
    }

    private function isExpired(?Carbon $deadlineUtc): bool
    {
        return $deadlineUtc !== null && Carbon::now('UTC')->greaterThan($deadlineUtc);
    }

    private function baseRow(string $cpf): array
    {
        $row = [];
        foreach (FgtsOfflineExport::COLS as $col) { $row[$col] = null; }
        $row['cpf'] = $cpf;
        return $row;
    }

    private function spoolAppend(FgtsOfflineJob $job, array $row): void
    {
        $disk = Storage::disk($this->disk);
        $path = $job->spool_path ?? '';
        if ($path === '' || !$disk->exists($path)) {
            throw new \RuntimeException("Spool ausente para job {$job->id}");
        }

        $fp = fopen($disk->path($path), 'a');
        if ($fp === false) {
            throw new \RuntimeException("Falha ao abrir spool para append: {$path}");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                $ordered = [];
                foreach (FgtsOfflineExport::COLS as $key) {
                    $ordered[] = $row[$key] ?? null;
                }
                fputcsv($fp, $ordered, ';');
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        $bytes = $this->fileSizeSafe($this->disk, $path);

        DB::table('fgts_off_consult_jobs')
            ->where('id', $job->id)
            ->update([
                'spool_bytes'   => $bytes,
                'preview_dirty' => true,
                'updated_at'    => Carbon::now(),
            ]);

        $job->spool_bytes   = $bytes;
        $job->preview_dirty = true;
    }

    private function cleanupSpool(FgtsOfflineJob $job): void
    {
        try {
            $disk = Storage::disk($this->disk);
            foreach (['spool_path','spool_cpfs_path'] as $field) {
                $p = $job->{$field} ?? null;
                if ($p && $disk->exists($p)) {
                    try { $disk->delete($p); } catch (Throwable $e) {
                        Log::warning("[FGTS-OFF] Job {$this->jobId} – falha ao deletar {$field}: ".$e->getMessage());
                    }
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
            Log::warning("[FGTS-OFF] Job {$this->jobId} falha ao apagar prévia: ".$e->getMessage());
        } finally {
            $job->updateQuietly([
                'preview_disk'       => null,
                'preview_path'       => null,
                'preview_name'       => null,
                'preview_updated_at' => null,
                'preview_dirty'      => false,
            ]);
        }
    }

    private function nowBrString(): string
    {
        return Carbon::now('America/Sao_Paulo')->format('d/m/Y H:i:s');
    }

    private function fileSizeSafe(string $diskName, string $relativePath): int
    {
        try {
            $disk = Storage::disk($diskName);
            $real = $disk->path($relativePath);
            clearstatcache(true, $real);
            return file_exists($real) ? (int) filesize($real) : 0;
        } catch (Throwable) {
            return 0;
        }
    }
}
