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

    /** Storage / Spool */
    private string $disk;
    private string $dirReports;
    private string $dirPreviews;
    private string $dirSpool;
    private string $finalPrefix;

    /** Arquivo(s) de pendências por tentativa (para reduzir RAM). */
    private array $pendFiles = [];

    /** Writer persistente do spool */
    private $spoolFp = null;
    private string $spoolReal = '';

    /** Throttling de updates */
    private int $flushEveryRows = 4000;
    private int $flushEverySecs = 5;
    private int $flushBytesStep = 262144;   // 256 KiB
    private float $nextFlushAt = 0.0;
    private int $rowsSinceFlush = 0;
    private int $lastFlushedBytes = 0;

    /** Acumuladores de contadores (evita increment() miúdo) */
    private int $accSuccess = 0;
    private int $accNotAuth = 0;
    private int $accFail = 0;

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;

        $this->onQueue((string) config('facta_off.job.queue', 'fgts'));

        // Configs
        $this->timeout     = (int) config('facta_off.job.timeout_seconds', 115200);
        $this->disk        = (string) config('facta_off.storage.reports_disk', 'public');
        $this->dirReports  = (string) config('facta_off.storage.dir_reports', 'fgts-off-reports');
        $this->dirPreviews = (string) config('facta_off.storage.dir_previews', 'fgts-off-previews');
        $this->dirSpool    = (string) (config('facta_off.storage.dir_spool') ?? 'fgts-off-spool');
        $this->finalPrefix = (string) config('facta_off.storage.final_prefix', 'fgts-offline');
    }

    public function handle(FactaOfflineApiService $api): void
    {
        /** @var FgtsOfflineJob|null $job */
        $job = FgtsOfflineJob::query()->whereKey($this->jobId)->first();
        if (!$job) {
            Log::info("[FGTS-OFF] Job {$this->jobId} não encontrado (provavelmente removido). Encerrando sem retry.");
            $this->deletePendFiles();
            return;
        }

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
            dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
            $this->deletePendFiles();
            return;
        }

        // Atualiza estado inicial
        $job->update([
            'status' => 'em_progresso',
            'started_at' => Carbon::now(),
            'spool_bytes' => $this->fileSizeSafe($this->disk, $job->spool_path),
            'preview_dirty' => false,
        ]);

        // Abre writer persistente do spool
        $this->spoolReal = $disk->path($job->spool_path);
        $this->spoolFp = @fopen($this->spoolReal, 'a');
        if (!is_resource($this->spoolFp)) {
            Log::error("[FGTS-OFF] Job {$this->jobId} falha ao abrir spool para append persistente.");
            dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
            $this->deletePendFiles();
            return;
        }
        $this->lastFlushedBytes = $this->fileSizeSafe($this->disk, $job->spool_path);
        $this->nextFlushAt = microtime(true) + $this->flushEverySecs;

        try {
            // ==========================
            // 0) DEDUP EXTERNO (em disco)
            // ==========================
            $cpfsReal = $disk->path($job->spool_cpfs_path);
            $uniqRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.cpfs.uniq.txt";
            $uniqReal = $disk->path($uniqRel);
            $this->pendFiles[] = $uniqRel;

            $uniqueCount = $this->buildUniqueCpfsFile($cpfsReal, $uniqRel);
            if ($uniqueCount === 0) {
                dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
                return;
            }

            // ==========================
            // 1) CLASSIFICAÇÃO STREAMING (com lista única)
            // ==========================
            $pend1Rel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.pend.a1.txt";
            $pend1Real = $disk->path($pend1Rel);
            $this->pendFiles[] = $pend1Rel;

            $invalidCnt = 0;
            $batchRows = [];
            $batchSize = 500;
            $snapRows = []; // snapshots para upsert em lote

            $pf = fopen($pend1Real, 'c+');
            if ($pf === false) {
                Log::error("[FGTS-OFF] Job {$this->jobId} não conseguiu criar pendências a1.");
                dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
                $this->deletePendFiles();
                return;
            }
            ftruncate($pf, 0);

            $reader = fopen($uniqReal, 'r');
            if ($reader === false) {
                fclose($pf);
                Log::error("[FGTS-OFF] Job {$this->jobId} não conseguiu abrir lista única de CPFs.");
                dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
                $this->deletePendFiles();
                return;
            }

            try {
                while (($line = fgets($reader)) !== false) {
                    if ($this->finishIfCancelled($job)) {
                        fclose($reader);
                        fclose($pf);
                        $this->deletePendFiles();
                        return;
                    }
                    if ($this->isExpired($deadlineUtc)) {
                        fclose($reader);
                        fclose($pf);
                        dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'expirado'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
                        $this->deletePendFiles();
                        return;
                    }

                    $cpf = preg_replace('/\D+/', '', (string) $line);
                    if ($cpf === '' || strlen($cpf) !== 11) {
                        continue;
                    }

                    if (!Cpf::isValid($cpf)) {
                        // spool
                        $row = $this->baseRow($cpf);
                        $row['situacao'] = 'Não autorizado - CPF inválido (dígitos verificadores)';
                        $row['consultadoEm'] = $this->nowBrString();
                        $batchRows[] = $row;

                        // snapshot (final) → updated_at (agora UTC) será usado
                        $snapRows[] = [
                            'cpf'        => $cpf,
                            'situacao'   => $row['situacao'],
                            'authorized' => false,
                        ];

                        $invalidCnt++;

                        if (count($batchRows) >= $batchSize) {
                            $this->spoolAppendManyPersist($job, $batchRows);
                            $this->persistSnapshots($snapRows);
                            $batchRows = [];
                            $snapRows  = [];
                        }
                    } else {
                        fwrite($pf, $cpf . "\n"); // válidos únicos para pendências
                    }
                }

                if (!empty($batchRows)) {
                    $this->spoolAppendManyPersist($job, $batchRows);
                    $this->persistSnapshots($snapRows);
                    $batchRows = [];
                    $snapRows  = [];
                }
            } finally {
                fclose($reader);
                fflush($pf);
                fclose($pf);
            }

            // Atualiza contadores e total únicos
            $this->accFail += $invalidCnt;
            if ($uniqueCount > 0) {
                $this->updateTotalsThrottled($job, $job->spool_path, ['total_cpfs' => $uniqueCount], true);
            }

            Log::info("[FGTS-OFF] Job {$this->jobId} classificado – únicos={$uniqueCount}, inválidos={$invalidCnt}");

            if ($this->isExpired($deadlineUtc)) {
                dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'expirado'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
                $this->deletePendFiles();
                return;
            }

            // ======================================
            // 2) TENTATIVAS COM TEIMOSINHA (STREAM)
            // ======================================
            $maxAttempts = (int) config('facta_off.job.max_attempts', 5);
            $retryDelay = (int) config('facta_off.job.retry_delay_seconds', 30);
            $chunkSize = (int) config('facta_off.job.chunk', 6);
            $minChunk = max(1, (int) config('facta_off.job.min_chunk', 2));
            $retryAfterCap = (int) config('facta_off.job.retry_after_max', 120);

            $currPendRel = $pend1Rel;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($this->finishIfCancelled($job)) {
                    $this->deletePendFiles();
                    return;
                }
                if ($this->isExpired($deadlineUtc)) {
                    dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'expirado'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
                    $this->deletePendFiles();
                    return;
                }

                if (!$disk->exists($currPendRel) || ((int) $disk->size($currPendRel)) === 0) {
                    break; // nada a processar
                }

                $nextPendRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.pend.a" . ($attempt + 1) . ".txt";
                $this->pendFiles[] = $nextPendRel;
                $currPendReal = $disk->path($currPendRel);
                $nextPendReal = $disk->path($nextPendRel);

                $nf = fopen($nextPendReal, 'c+');
                if ($nf === false) {
                    Log::error("[FGTS-OFF] Job {$this->jobId} falhou ao criar arquivo de pendências da próxima tentativa.");
                    dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
                    $this->deletePendFiles();
                    return;
                }
                ftruncate($nf, 0);

                $seen429InAttempt = 0;
                $retryAfterMaxSeen = 0;
                $successThisAttempt = 0;
                $semRespTotal = 0;
                $totalInAttempt = 0;

                Log::debug(config('app.debug') ? "[FGTS-OFF] Job {$this->jobId} tentativa {$attempt} – chunkSize={$chunkSize}" : '');

                $reader2 = fopen($currPendReal, 'r');
                if ($reader2 === false) {
                    fclose($nf);
                    Log::error("[FGTS-OFF] Job {$this->jobId} não conseguiu abrir pendências atuais.");
                    dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
                    $this->deletePendFiles();
                    return;
                }

                try {
                    $buf = [];
                    while (($line = fgets($reader2)) !== false) {
                        if ($this->finishIfCancelled($job)) {
                            fclose($reader2);
                            fclose($nf);
                            $this->deletePendFiles();
                            return;
                        }
                        if ($this->isExpired($deadlineUtc)) {
                            fclose($reader2);
                            fclose($nf);
                            dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'expirado'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
                            $this->deletePendFiles();
                            return;
                        }

                        $cpf = preg_replace('/\D+/', '', (string) $line);
                        if ($cpf === '' || strlen($cpf) !== 11)
                            continue;
                        $buf[] = $cpf;

                        if (count($buf) >= max(1, $chunkSize)) {
                            $this->processChunk(
                                $api,
                                $job,
                                $buf,
                                $nf,
                                $seen429InAttempt,
                                $retryAfterMaxSeen,
                                $semRespTotal,
                                $totalInAttempt,
                                $successThisAttempt
                            );
                            $buf = [];
                        }
                    }
                    if (!empty($buf)) {
                        $this->processChunk(
                            $api,
                            $job,
                            $buf,
                            $nf,
                            $seen429InAttempt,
                            $retryAfterMaxSeen,
                            $semRespTotal,
                            $totalInAttempt,
                            $successThisAttempt
                        );
                        $buf = [];
                    }
                } finally {
                    fclose($reader2);
                    fflush($nf);
                    fclose($nf);
                }

                // Ajustes de chunk conforme qualidade das respostas
                $semRespRatio = $totalInAttempt > 0 ? ($semRespTotal / $totalInAttempt) : 0.0;
                if ($semRespRatio >= 0.50 && $chunkSize > $minChunk) {
                    $old = $chunkSize;
                    $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                    Log::warning("[FGTS-OFF] Job {$this->jobId} – muitos sem_resposta (ratio=" . round($semRespRatio, 2) . "). Reduzindo chunk {$old} → {$chunkSize}.");
                }
                if ($seen429InAttempt > 0 && $chunkSize > $minChunk) {
                    $old = $chunkSize;
                    $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                    Log::warning("[FGTS-OFF] Job {$this->jobId} – 429 vistos. Reduzindo chunk {$old} → {$chunkSize}.");
                }
                if ($semRespRatio >= 0.80) {
                    Log::warning("[FGTS-OFF] Job {$this->jobId} – MODO DEGRADADO: sem_resposta ratio=" . number_format($semRespRatio, 2));
                }

                Log::debug(
                    config('app.debug')
                    ? "[FGTS-OFF] Job {$this->jobId} tentativa {$attempt} – resumo: sem_resp_ratio=" . number_format($semRespRatio, 2) . " seen429={$seen429InAttempt} retry_after_max={$retryAfterMaxSeen}"
                    : ''
                );

                // Backoff entre tentativas (sleep)
                if ($attempt < $maxAttempts) {
                    if ($this->finishIfCancelled($job)) {
                        $this->deletePendFiles();
                        return;
                    }
                    if ($this->isExpired($deadlineUtc)) {
                        dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'expirado'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
                        $this->deletePendFiles();
                        return;
                    }

                    $baseRetryAfter = $retryAfterMaxSeen > 0 ? min($retryAfterMaxSeen, $retryAfterCap) : 0;
                    $base = max(1, $retryDelay, $baseRetryAfter);

                    $sleepFactor = 1.0;
                    if ($semRespRatio >= 0.90)
                        $sleepFactor = 2.0;
                    elseif ($semRespRatio >= 0.50)
                        $sleepFactor = 1.5;

                    $withFactor = (int) ceil($base * $sleepFactor);
                    $jitter = random_int(0, (int) max(1, ceil($withFactor * 0.15)));
                    $sleepSecs = $withFactor + $jitter;

                    Log::debug(config('app.debug') ? "[FGTS-OFF] Job {$this->jobId} – dormindo {$sleepSecs}s." : '');
                    sleep($sleepSecs);
                }

                // Limpeza: se próxima pendência ficou vazia, apaga já e quebra
                $nextSize = (int) ($disk->exists($nextPendRel) ? $disk->size($nextPendRel) : 0);
                try {
                    if ($disk->exists($currPendRel))
                        $disk->delete($currPendRel);
                } catch (Throwable) {
                }
                $currPendRel = $nextPendRel;

                if ($nextSize === 0) {
                    try {
                        if ($disk->exists($currPendRel))
                            $disk->delete($currPendRel);
                    } catch (Throwable) {
                    }
                    break;
                }

                if (function_exists('gc_collect_cycles')) {
                    @gc_collect_cycles();
                }
            }

            // ==========================
            // Varredura final de pendências (100% garantido)
            // ==========================
            $leftoverCount = 0;
            if ($disk->exists($currPendRel) && ((int) $disk->size($currPendRel)) > 0) {
                $currPendReal = $disk->path($currPendRel);

                $reader = fopen($currPendReal, 'r');
                if ($reader !== false) {
                    $rows = [];
                    $snapRows = [];
                    $batchSize = 500;

                    while (($line = fgets($reader)) !== false) {
                        $cpf = preg_replace('/\D+/', '', (string) $line);
                        if ($cpf === '' || strlen($cpf) !== 11)
                            continue;

                        // spool
                        $row = $this->baseRow($cpf);
                        $row['situacao'] = 'Não autorizado - Sem resposta após ' . $maxAttempts . ' tentativas';
                        $row['consultadoEm'] = $this->nowBrString();
                        $rows[] = $row;
                        $leftoverCount++;

                        // snapshot (final) → updated_at (agora UTC) será usado
                        $snapRows[] = [
                            'cpf'        => $cpf,
                            'situacao'   => $row['situacao'],
                            'authorized' => false,
                        ];

                        if (count($rows) >= $batchSize) {
                            $this->spoolAppendManyPersist($job, $rows);
                            $this->persistSnapshots($snapRows);
                            $rows = [];
                            $snapRows = [];
                        }
                    }

                    if (!empty($rows)) {
                        $this->spoolAppendManyPersist($job, $rows);
                        $this->persistSnapshots($snapRows);
                        $rows = [];
                        $snapRows = [];
                    }

                    fclose($reader);
                }

                try {
                    $disk->delete($currPendRel);
                } catch (Throwable $e) {
                    Log::debug(config('app.debug') ? "[FGTS-OFF] Job {$this->jobId} falha ao remover pendência final {$currPendRel}: " . $e->getMessage() : '');
                }

                if ($leftoverCount > 0) {
                    $this->accFail += $leftoverCount;
                }
            }

            // Flush final de contadores/bytes
            $this->updateTotalsThrottled($job, $job->spool_path, [], true);

            // Finalização: delega geração do FINAL
            dispatch(new FinalizeFgtsOffReportJob($this->jobId, 'concluido'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
        } finally {
            if (is_resource($this->spoolFp)) {
                @fflush($this->spoolFp);
                @fclose($this->spoolFp);
            }
            $this->deletePendFiles();
        }
    }

    /** Processa um chunk de CPFs: consulta, escreve spool (em lote) e encaminha pendentes retriáveis ao arquivo. */
    private function processChunk(
        FactaOfflineApiService $api,
        FgtsOfflineJob $job,
        array $chunkCpfs,
        $nextPendHandle,
        int &$seen429InAttempt,
        int &$retryAfterMaxSeen,
        int &$semRespTotal,
        int &$totalInAttempt,
        int &$successThisAttempt
    ): void {
        $t0 = microtime(true);

        try {
            $batchResults = $api->consultaCpfLote($chunkCpfs);
        } catch (\Throwable $e) {
            Log::error("[FGTS-OFF] Job {$this->jobId} erro no consultaCpfLote para chunk (" . count($chunkCpfs) . "): " . $e->getMessage(), ['exception' => $e]);
            foreach ($chunkCpfs as $cpf) {
                fwrite($nextPendHandle, $cpf . "\n");
            }
            $semRespTotal += count($chunkCpfs);
            $totalInAttempt += count($chunkCpfs);
            return;
        }

        $authorizedInChunk = 0;
        $notAuthorizedInChunk = 0;
        $terminalFailsInChunk = 0;
        $rows = [];
        $snapRows = [];

        foreach ($chunkCpfs as $cpf) {
            $res = $batchResults[$cpf] ?? [
                'ok' => false,
                'mensagem' => 'Sem resposta do serviço',
                'authorized' => null,
                'retriable' => true,
                'http_status' => null,
                'retry_after' => null,
                'consultado_at' => null,
            ];

            $http = $res['http_status'] ?? null;
            if ($http === 429)
                $seen429InAttempt++;
            if ($http === null)
                $semRespTotal++;

            if (!empty($res['retry_after'])) {
                $retryAfterMaxSeen = max($retryAfterMaxSeen, (int) $res['retry_after']);
            }

            if (!empty($res['ok'])) {
                $row = $this->baseRow($cpf);
                $row['situacao'] = ($res['authorized'] ?? null) === true ? 'Autorizado' : 'Não autorizado';
                if ($row['situacao'] === 'Autorizado')
                    $authorizedInChunk++;
                else
                    $notAuthorizedInChunk++;

                $row['consultadoEm'] = $this->nowBrString();
                $rows[] = $row;
                $successThisAttempt++;

                // snapshot (final para "ok") → updated_at (agora UTC) será usado
                $snapRows[] = [
                    'cpf'        => $cpf,
                    'situacao'   => $row['situacao'],
                    'authorized' => ($res['authorized'] ?? null) === true,
                ];
            } else {
                $msg = (string) ($res['mensagem'] ?? 'Falha na consulta');
                $retriable = $res['retriable'] ?? true;

                if ($retriable === false) {
                    $row = $this->baseRow($cpf);
                    $row['situacao'] = 'Não autorizado - ' . $msg;
                    $row['consultadoEm'] = $this->nowBrString();
                    $rows[] = $row;
                    $terminalFailsInChunk++;

                    // snapshot (falha terminal) → updated_at (agora UTC)
                    $snapRows[] = [
                        'cpf'        => $cpf,
                        'situacao'   => $row['situacao'],
                        'authorized' => false,
                    ];
                } else {
                    // mantém para próxima tentativa
                    fwrite($nextPendHandle, $cpf . "\n");
                }
            }
        }

        if (!empty($rows)) {
            $this->spoolAppendManyPersist($job, $rows);
        }
        if (!empty($snapRows)) {
            $this->persistSnapshots($snapRows);
        }

        $this->accSuccess += $authorizedInChunk;
        $this->accNotAuth += $notAuthorizedInChunk;
        $this->accFail += $terminalFailsInChunk;

        $totalInAttempt += count($chunkCpfs);

        if (config('app.debug')) {
            $elapsed = max(0.001, microtime(true) - $t0);
            $rps = count($chunkCpfs) / $elapsed;
            Log::debug("[FGTS-OFF] Job {$this->jobId} chunk size=" . count($chunkCpfs) . " auth={$authorizedInChunk} nao_auth={$notAuthorizedInChunk} fail_term={$terminalFailsInChunk} elapsed=" . number_format($elapsed, 3) . "s rps=" . number_format($rps, 2));
        }
    }

    /** ----------------------- Helpers ----------------------- */

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
            $this->cleanupSpool($job);
            Log::info("[FGTS-OFF] Job {$this->jobId} interrompido por cancelamento (spool removido).");
            $this->deletePendFiles();
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
        static $cols = null;
        if ($cols === null)
            $cols = FgtsOfflineExport::COLS;

        $row = array_fill_keys($cols, null);
        $row['cpf'] = $cpf;
        return $row;
    }

    private function spoolAppendManyPersist(FgtsOfflineJob $job, array $rows): void
    {
        if (!is_resource($this->spoolFp)) {
            throw new \RuntimeException("Writer do spool não inicializado.");
        }

        if (flock($this->spoolFp, LOCK_EX)) {
            foreach ($rows as $row) {
                $ordered = [];
                foreach (FgtsOfflineExport::COLS as $key) {
                    $ordered[] = $row[$key] ?? null;
                }
                fputcsv($this->spoolFp, $ordered, ';');
            }
            fflush($this->spoolFp);
            flock($this->spoolFp, LOCK_UN);
        }

        $this->rowsSinceFlush += count($rows);
        $this->updateTotalsThrottled($job, $job->spool_path);
    }

    private function updateTotalsThrottled(FgtsOfflineJob $job, string $spoolRel, array $extraSet = [], bool $force = false): void
    {
        $now = microtime(true);
        $needBytesCheck = $force || $this->rowsSinceFlush >= $this->flushEveryRows || $now >= $this->nextFlushAt;

        $bytes = $this->lastFlushedBytes;
        if ($needBytesCheck) {
            try {
                clearstatcache(true, $this->spoolReal);
                $bytes = file_exists($this->spoolReal) ? (int) filesize($this->spoolReal) : 0;
            } catch (Throwable) {
                $bytes = $this->lastFlushedBytes;
            }
        }

        $shouldFlush = $force
            || $this->rowsSinceFlush >= $this->flushEveryRows
            || $now >= $this->nextFlushAt
            || ($bytes - $this->lastFlushedBytes) >= $this->flushBytesStep;

        if (!$shouldFlush) {
            return;
        }

        $updates = [
            'spool_bytes' => $bytes,
            'preview_dirty' => true,
            'updated_at' => Carbon::now(),
        ];
        foreach ($extraSet as $k => $v) {
            $updates[$k] = $v;
        }

        if ($this->accSuccess > 0) {
            $updates['success_count'] = DB::raw('success_count + ' . $this->accSuccess);
        }
        if ($this->accNotAuth > 0) {
            $updates['not_authorized_count'] = DB::raw('not_authorized_count + ' . $this->accNotAuth);
        }
        if ($this->accFail > 0) {
            $updates['fail_count'] = DB::raw('fail_count + ' . $this->accFail);
        }

        DB::table('fgts_off_consult_jobs')
            ->where('id', $job->id)
            ->update($updates);

        $job->spool_bytes = $bytes;
        $job->preview_dirty = true;

        $this->rowsSinceFlush = 0;
               $this->nextFlushAt = $now + $this->flushEverySecs;
        $this->lastFlushedBytes = $bytes;

        $this->accSuccess = 0;
        $this->accNotAuth = 0;
        $this->accFail = 0;
    }

    private function cleanupSpool(FgtsOfflineJob $job): void
    {
        try {
            $disk = Storage::disk($this->disk);
            foreach (['spool_path', 'spool_cpfs_path'] as $field) {
                $p = $job->{$field} ?? null;
                if ($p && $disk->exists($p)) {
                    try {
                        $disk->delete($p);
                    } catch (Throwable $e) {
                        Log::warning("[FGTS-OFF] Job {$this->jobId} – falha ao deletar {$field}: " . $e->getMessage());
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
            Log::warning("[FGTS-OFF] Job {$this->jobId} falha ao apagar prévia: " . $e->getMessage());
        } finally {
            $job->updateQuietly([
                'preview_disk' => null,
                'preview_path' => null,
                'preview_name' => null,
                'preview_updated_at' => null,
                'preview_dirty' => false,
            ]);
        }
    }

    private function deletePendFiles(): void
    {
        try {
            $disk = Storage::disk($this->disk);
            foreach ($this->pendFiles as $rel) {
                if ($rel && $disk->exists($rel)) {
                    try {
                        $disk->delete($rel);
                    } catch (Throwable $e) {
                        Log::debug(config('app.debug') ? "[FGTS-OFF] Job {$this->jobId} falha ao remover pendência {$rel}: " . $e->getMessage() : '');
                    }
                }
            }
        } catch (Throwable $e) {
            Log::debug(config('app.debug') ? "[FGTS-OFF] Job {$this->jobId} erro ao limpar pendências: " . $e->getMessage() : '');
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

    private function buildUniqueCpfsFile(string $cpfsReal, string $uniqRel): int
    {
        $disk = Storage::disk($this->disk);
        $uniqReal = $disk->path($uniqRel);

        $blockSize = 10000;
        $chunks = [];

        $r = fopen($cpfsReal, 'r');
        if ($r === false) {
            return 0;
        }

        try {
            $block = [];
            while (($line = fgets($r)) !== false) {
                $cpf = preg_replace('/\D+/', '', $line);
                if ($cpf === '' || strlen($cpf) !== 11)
                    continue;

                $block[$cpf] = true;

                if (count($block) >= $blockSize || $this->shouldSpill(count($block))) {
                    $chunks[] = $this->writeSortedChunk($block);
                    $block = [];
                }
            }
            if (!empty($block)) {
                $chunks[] = $this->writeSortedChunk($block);
                $block = [];
            }
        } finally {
            fclose($r);
        }

        if (empty($chunks)) {
            $w = fopen($uniqReal, 'w');
            if ($w !== false)
                fclose($w);
            return 0;
        }

        if (count($chunks) === 1) {
            @rename($chunks[0], $uniqReal);
            $cnt = 0;
            $fh = fopen($uniqReal, 'r');
            if ($fh !== false) {
                while (!feof($fh)) {
                    $line = fgets($fh);
                    if ($line !== false)
                        $cnt++;
                }
                fclose($fh);
            }
            return $cnt;
        }

        $writers = fopen($uniqReal, 'w');
        if ($writers === false) {
            foreach ($chunks as $c)
                @unlink($c);
            return 0;
        }

        $handles = [];
        $heads = [];
        foreach ($chunks as $idx => $path) {
            $h = fopen($path, 'r');
            if ($h !== false) {
                $handles[$idx] = $h;
                $heads[$idx] = fgets($h);
            }
        }

        $written = 0;
        $last = null;
        while (!empty($handles)) {
            $minIdx = null;
            $minVal = null;
            foreach ($heads as $idx => $val) {
                if ($val === false || $val === null)
                    continue;
                $val = trim($val);
                if ($minVal === null || strcmp($val, $minVal) < 0) {
                    $minVal = $val;
                    $minIdx = $idx;
                }
            }

            if ($minIdx === null)
                break;

            if ($minVal !== '' && $minVal !== $last) {
                fwrite($writers, $minVal . "\n");
                $written++;
                $last = $minVal;
            }

            $heads[$minIdx] = fgets($handles[$minIdx]);
            if ($heads[$minIdx] === false) {
                fclose($handles[$minIdx]);
                unset($handles[$minIdx], $heads[$minIdx]);
            }
        }

        fclose($writers);

        foreach ($chunks as $c) {
            @unlink($c);
        }

        return $written;
    }

    private function writeSortedChunk(array $block): string
    {
        $disk = Storage::disk($this->disk);
        $rel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.cpfs.chunk." . uniqid('', true) . ".txt";
        $real = $disk->path($rel);
        $this->pendFiles[] = $rel;

        ksort($block, SORT_STRING);

        $w = fopen($real, 'w');
        if ($w !== false) {
            foreach ($block as $cpf => $_) {
                fwrite($w, $cpf . "\n");
            }
            fclose($w);
        }

        return $real;
    }

    private function shouldSpill(int $currentCount): bool
    {
        if ($currentCount <= 0)
            return false;
        $limit = $this->memoryLimitBytes();
        if ($limit <= 0)
            return false;
        $usage = memory_get_usage(true);
        return $usage > (int) ($limit * 0.70);
    }

    private function memoryLimitBytes(): int
    {
        $val = ini_get('memory_limit');
        if ($val === false || $val === '' || $val === '-1') {
            return PHP_INT_MAX;
        }
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int) $val;
        switch ($last) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return $num > 0 ? $num : PHP_INT_MAX;
    }

    /* ===================== SNAPSHOTS (helpers) ===================== */

    /** Upsert em lote na fgts_off_snapshots + lookup de lead_id em UMA query. */
    private function persistSnapshots(array $snapRows): void
    {
        if (empty($snapRows)) return;

        try {
            $now = Carbon::now('UTC');
            $cpfs = [];
            $payload = [];

            foreach ($snapRows as $r) {
                $cpf = (string) ($r['cpf'] ?? '');
                if ($cpf === '' || strlen($cpf) !== 11) continue;
                $cpfs[] = $cpf;

                $payload[] = [
                    'cpf'        => $cpf,
                    'situacao'   => $r['situacao'] ?? null,
                    'authorized' => array_key_exists('authorized', $r) ? (bool)$r['authorized'] : null,
                    'job_id'     => $this->jobId,
                    'updated_at' => $now, // ← passa a ser o "consultado em"
                    // lead_id será preenchido abaixo
                ];
            }
            if (empty($payload)) return;

            // lookup de lead_id (1 query por batch)
            $leadMap = DB::table('leads')
                ->whereIn('cpf', array_values(array_unique($cpfs)))
                ->pluck('id', 'cpf'); // [cpf => id]

            foreach ($payload as &$row) {
                $row['lead_id'] = $leadMap[$row['cpf']] ?? null;
            }
            unset($row);

            DB::table('fgts_off_snapshots')->upsert(
                $payload,
                ['cpf'],
                ['situacao','authorized','job_id','updated_at','lead_id'] // removido consultado_em
            );
        } catch (\Throwable $e) {
            Log::warning("[FGTS-OFF] Upsert snapshots falhou no job {$this->jobId}: ".$e->getMessage());
        }
    }
}
