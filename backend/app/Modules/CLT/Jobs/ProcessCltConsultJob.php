<?php

namespace App\Modules\CLT\Jobs;

use App\Modules\CLT\Models\CltConsultJob;
use App\Modules\CLT\Services\Exceptions\FactaFatalAuthException;
use App\Modules\CLT\Support\CltLog;
use App\Modules\CLT\Support\CltSchema;
use App\Modules\CLT\Support\CltSpool;
use App\Modules\CLT\Support\CltVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessCltConsultJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const STAGE_PHASE1 = 'phase1';
    private const STAGE_PHASE2 = 'phase2';

    public int $uniqueFor = 115260;
    public function uniqueId(): string
    {
        return $this->jobId . ':' . $this->stage;
    }

    public int $timeout;
    private int $jobId;
    private string $stage = self::STAGE_PHASE1;

    private string $disk;
    private string $dirSpool;
    private string $finalPrefix;
    private bool $finalizationTriggered = false;

    private array $pendFiles = [];
    private $spoolFp = null;
    private string $spoolReal = '';

    // ====== FLUSH: mantemos apenas por tempo ======
    private int $flushEverySecs = 20;
    private float $lastFlushAt = 0.0;
    private int $statusCheckIntervalMs;
    private float $lastStatusCheckAt = 0.0;
    private ?string $cachedStatus = null;

    private int $accEligible = 0;
    private int $accIneligible = 0;
    private int $accNotFound = 0;
    private int $accFail = 0;

    /** Pacing */
    private int $chunkDelayMs;
    private int $subchunkSize;
    private int $subchunkDelayMs;
    private int $rowsBufferFlush;
    private int $snapBufferFlush;
    private int $memorySpillThresholdPercent;
    private ?int $runtimeWorkerMemoryLimitBytes = null;
    private bool $backoffLog;
    private bool $chunkPerfDebug;
    private bool $flushProgressLog;
    private float $semResponseChunkThreshold;
    private int $semResponseChunkCooldownSeconds;
    private int $phaseOneCoordLockTtl;
    private int $phaseOneCoordLockWait;
    private int $phaseOneCoordRetryDelaySeconds;

    /** Guarda a variante (online|offline|hybrid) para a regra de snapshot */
    private string $variant = 'online';
    private int $hybridOfflineMaxAgeDays;
    private array $baseRowTemplate = [];
    private int $phase2MaxAttempts;
    private int $phase2RetryDelaySeconds;
    private int $phase2ProgressFlushIntervalMs;
    private int $phase2ProgressFlushEveryRows;
    private float $lastPhase2ProgressFlushAt = 0.0;
    private int $phase2DeltaFlushIntervalMs;
    private int $phase2DeltaFlushEveryRows;
    private float $lastPhase2DeltaFlushAt = 0.0;
    private string $phase2DeltaReal = '';
    /** @var array<int,string> */
    private array $phase2DeltaBuffer = [];
    private int $phase2LastAttemptProcessed = 0;
    private int $phase2DeltaCurrentAttempt = 0;
    private string $phase2DeltaAttemptReal = '';
    /** @var array<int,string> */
    private array $phase2DeltaAttemptBuffer = [];
    private string $phase2PendingReal = '';
    private string $phase2PendingNextReal = '';
    private int $phase2PendingFlushEveryRows;
    /** @var array<int,string> */
    private array $phase2PendingBuffer = [];
    private bool $phase2CpfValidationAuditLogEnabled;
    /**
     * Acumulador de requests por linha da fase 2.
     *
     * @var array<int,array{total:int,operacoes:int,politica:int}>
     */
    private array $phase2CpfValidationAuditReqByLine = [];

    public function __construct(int $jobId, string $stage = self::STAGE_PHASE1)
    {
        $this->jobId = $jobId;
        $this->stage = in_array($stage, [self::STAGE_PHASE1, self::STAGE_PHASE2], true)
            ? $stage
            : self::STAGE_PHASE1;

        // Nota: a fila é definida no dispatch (controller) por variante.
        $this->timeout = (int) config('cltfacta.job.timeout_seconds', 115200);
        $this->uniqueFor = max(3600, $this->timeout + 3600);
        $this->disk = (string) config('cltfacta.storage.reports_disk', 'local');
        $this->dirSpool = (string) (config('cltfacta.storage.dir_spool') ?? 'clt-spool');
        $this->finalPrefix = (string) config('cltfacta.storage.final_prefix', 'clt-consulta');

        // pacing (configurável por env)
        $this->chunkDelayMs = (int) config('cltfacta.job.chunk_delay_ms', 200);
        $this->subchunkSize = max(1, (int) config('cltfacta.job.subchunk', 5));
        $this->subchunkDelayMs = (int) config('cltfacta.job.subchunk_delay_ms', 120);
        $this->rowsBufferFlush = max(1, (int) config('cltfacta.job.rows_buffer_flush', 300));
        $this->snapBufferFlush = max(1, (int) config('cltfacta.job.snap_buffer_flush', 300));
        $this->memorySpillThresholdPercent = max(40, min(90, (int) config('cltfacta.job.memory_spill_threshold_percent', 70)));
        $this->runtimeWorkerMemoryLimitBytes = $this->detectRuntimeWorkerMemoryLimitBytes();
        $this->statusCheckIntervalMs = max(100, (int) config('cltfacta.job.status_check_interval_ms', 1000));
        $this->flushEverySecs = max(1, (int) config('cltfacta.job.progress_flush_interval_seconds', 20));
        $this->backoffLog = (bool) config('cltfacta.logging.backoff_log', false);
        $this->chunkPerfDebug = (bool) config('cltfacta.logging.chunk_perf_debug', false);
        $this->flushProgressLog = (bool) config('cltfacta.logging.flush_progress_log', false);
        $this->semResponseChunkThreshold = max(0.05, min(1.0, (float) config('cltfacta.job.sem_response_chunk_threshold', 0.5)));
        $this->semResponseChunkCooldownSeconds = max(0, (int) config('cltfacta.job.sem_response_chunk_cooldown_seconds', 10));
        $this->phaseOneCoordLockTtl = max(1, (int) config('cltfacta.job.phase1_coord_lock_ttl', 10));
        $this->phaseOneCoordLockWait = max(1, (int) config('cltfacta.job.phase1_coord_lock_wait', 5));
        $this->phaseOneCoordRetryDelaySeconds = max(1, (int) config('cltfacta.job.phase1_coord_retry_delay_seconds', 15));
        $this->hybridOfflineMaxAgeDays = max(0, (int) config('cltfacta.hybrid.offline_max_age_days', 7));
        $this->baseRowTemplate = array_fill_keys(CltSchema::COLS, null);
        $this->phase2MaxAttempts = max(1, (int) config('cltfacta.credit_worker.phase2_max_attempts', 3));
        $this->phase2RetryDelaySeconds = max(1, (int) config('cltfacta.credit_worker.phase2_retry_delay_seconds', 30));
        $phase2ConfiguredIntervalMs = (int) config('cltfacta.credit_worker.phase2_progress_flush_interval_ms', 20000);
        $this->phase2ProgressFlushIntervalMs = max(
            $this->flushEverySecs * 1000,
            max(200, $phase2ConfiguredIntervalMs)
        );
        $this->phase2ProgressFlushEveryRows = max(20, (int) config('cltfacta.credit_worker.phase2_progress_flush_every_rows', 200));
        $this->phase2DeltaFlushIntervalMs = max(500, (int) config('cltfacta.credit_worker.phase2_delta_flush_interval_ms', 2000));
        $this->phase2DeltaFlushEveryRows = max(10, (int) config('cltfacta.credit_worker.phase2_delta_flush_every_rows', 20));
        $this->phase2PendingFlushEveryRows = max(10, (int) config('cltfacta.credit_worker.phase2_pending_flush_every_rows', 50));
        $this->phase2CpfValidationAuditLogEnabled = (bool) config('cltfacta.logging.phase2_cpf_validation_audit_log_enabled', false);
    }

    public function handle(): void
    {
        /** @var CltConsultJob|null $job */
        $job = CltConsultJob::query()->whereKey($this->jobId)->first();
        if (!$job) {
            $this->deletePendFiles();
            return;
        }

        // variante para snapshots
        $this->variant = CltVariant::normalizeStored($job->variant);
        $this->cachedStatus = $job->status;
        $this->lastStatusCheckAt = microtime(true);

        $api = $this->variant === 'offline'
            ? app(\App\Modules\CLT\Services\CltOfflineApiService::class)
            : app(\App\Modules\CLT\Services\FactaApiService::class);
        $hybridOfflineApi = $this->variant === 'hybrid'
            ? app(\App\Modules\CLT\Services\CltOfflineApiService::class)
            : null;
        if ($api instanceof \App\Modules\CLT\Services\FactaApiService) {
            $api->setRuntimeJobId($this->jobId);
        }

        if ($this->isPaused($job)) {
            return;
        }

        if ($this->isCancelled($job)) {
            if ($this->cachedStatus === 'cancelado') {
                $this->finalizeCancelledJob($job);
            }
            return;
        }

        $disk = Storage::disk($this->disk);
        $hasSpool = !empty($job->spool_path) && $disk->exists($job->spool_path);
        $hasCpfsSpool = !empty($job->spool_cpfs_path) && $disk->exists($job->spool_cpfs_path);
        if (!$hasSpool || ($this->stage === self::STAGE_PHASE1 && !$hasCpfsSpool)) {
            CltLog::error("[CLT] Job {$this->jobId} sem spool pré-criado.");
            $this->dispatchFinalize('falhou');
            $this->deletePendFiles();
            return;
        }

        $this->spoolReal = $disk->path($job->spool_path);
        $this->phase2DeltaReal = $this->spoolReal . '.phase2.delta.ndjson';
        $this->phase2PendingReal = $this->spoolReal . '.phase2.pending.ndjson';
        $this->phase2PendingNextReal = $this->phase2PendingReal . '.next';

        if ($this->stage === self::STAGE_PHASE2) {
            if (!$this->supportsCreditPhaseTwo() || !$api instanceof \App\Modules\CLT\Services\FactaApiService) {
                CltLog::warning('[CLT] Job recebido na fila da fase 2 com variante não suportada.', [
                    'job_id' => $this->jobId,
                    'variant' => $this->variant,
                    'stage' => $this->stage,
                ]);
                $this->dispatchFinalize('concluido');
                return;
            }

            if (!in_array($job->status, ['pendente', 'em_progresso'], true)) {
                CltLog::info('[CLT] Job da fase 2 ignorado por estado final.', [
                    'job_id' => $this->jobId,
                    'status' => $job->status,
                ]);
                return;
            }

            $job->update([
                'status' => 'em_progresso',
                'phase' => 'fase_2',
                'started_at' => $job->started_at ?? Carbon::now(),
                'spool_bytes' => $this->fileSizeSafe($this->disk, $job->spool_path),
            ]);

            if (!$this->runCreditPhaseTwo($api, $job)) {
                $statusAfterPhaseTwo = $this->currentStatusCached($job->id, true);
                if ($statusAfterPhaseTwo === null || in_array($statusAfterPhaseTwo, ['pausado', 'cancelado', 'falhou', 'concluido'], true)) {
                    return;
                }

                $this->failFinalize();
                return;
            }

            $this->dispatchFinalize('concluido');
            return;
        }

        if (!$this->beginPhaseOneIfAllowed($job)) {
            return;
        }

        CltLog::warning($this->variant === 'online'
            ? '[CLT] Fase 1 iniciada (consulta de trabalhadores)'
            : ($this->variant === 'hybrid'
                ? '[CLT-HYB] Fase 1 iniciada (triagem offline + fallback online)'
                : '[CLT-OFF] Processamento iniciado (sem fases)'), [
            'job_id' => $this->jobId,
            'variant' => $this->variant,
        ]);

        $this->spoolFp = @fopen($this->spoolReal, 'a');
        if (!is_resource($this->spoolFp)) {
            $this->dispatchFinalize('falhou');
            $this->deletePendFiles();
            return;
        }

        $this->lastFlushAt = microtime(true);

        try {
            // 0) DEDUP externo
            $cpfsReal = $disk->path($job->spool_cpfs_path);
            $uniqRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.cpfs.uniq.txt";
            $this->pendFiles[] = $uniqRel;

            $uniqueCount = $this->buildUniqueCpfsFile($cpfsReal, $uniqRel);
            if ($uniqueCount === 0) {
                $this->dispatchFinalize('falhou');
                return;
            }
            $this->updateTotalsThrottled($job, ['total_cpfs' => $uniqueCount], true);
            $processedCpfs = $this->loadProcessedCpfsFromSpool();

            // 1) Classificação inicial
            $pend1Rel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.pend.a1.txt";
            $pend1Real = $disk->path($pend1Rel);
            $this->pendFiles[] = $pend1Rel;

            $pf = fopen($pend1Real, 'c+');
            if ($pf === false) {
                $this->failFinalize();
                return;
            }
            if (!ftruncate($pf, 0)) {
                fclose($pf);
                $this->failFinalize();
                return;
            }

            $invCount = 0;
            $reader = fopen($disk->path($uniqRel), 'r');
            if ($reader === false) {
                fclose($pf);
                $this->failFinalize();
                return;
            }
            try {
                $batch = [];
                $snapSz = 500;

                // Formato BR leve calculado uma vez
                $nowBr = date('d/m/Y H:i:s');

                while (($line = fgets($reader)) !== false) {
                    if ($this->finishIfStopped($job))
                        return;

                    $cpf = preg_replace('/\D+/', '', (string) $line);
                    if ($cpf === '' || strlen($cpf) !== 11)
                        continue;

                    if (isset($processedCpfs[$cpf])) {
                        continue;
                    }

                    if (!\App\Support\Cpf::isValid($cpf)) {
                        $row = $this->baseRow($cpf);
                        $row['numeroVinculos'] = 0;
                        $row['mensagem'] = 'CPF inválido (dígitos verificadores)';
                        $row['consulted_at'] = $nowBr;
                        $row['fonteConsulta'] = null;
                        $batch[] = $row;
                        $invCount++;
                        if (count($batch) >= $snapSz) {
                            $this->spoolAppendManyPersist($job, $batch);
                            $batch = [];
                            $nowBr = date('d/m/Y H:i:s'); // Atualiza relógio a cada lote
                        }
                    } else {
                        $this->writeAllOrFail($pf, $cpf . "\n", 'pendência inicial da fase 1');
                    }
                }
                if (!empty($batch)) {
                    $this->spoolAppendManyPersist($job, $batch);
                    $batch = [];
                }
            } finally {
                fclose($reader);
                fflush($pf);
                fclose($pf);
            }
            unset($processedCpfs);

            if ($invCount > 0) {
                $this->accFail += $invCount;
                $this->updateTotalsThrottled($job);
            }

            // 2) Teimosinha
            $maxAttempts = (int) config('cltfacta.job.max_attempts', 5);
            $retryDelay = (int) config('cltfacta.job.retry_delay_seconds', 60);
            $baseChunkSize = max(1, (int) config('cltfacta.job.chunk', 24));
            $minChunk = max(1, (int) config('cltfacta.job.min_chunk', 8));
            $retryAfterCap = (int) config('cltfacta.job.retry_after_max', 120);
            $adaptiveChunkSize = $baseChunkSize;

            $currPendRel = $pend1Rel;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($this->finishIfStopped($job))
                    return;

                if (!$disk->exists($currPendRel) || ((int) $disk->size($currPendRel)) === 0)
                    break;

                $chunkSize = max($minChunk, min($baseChunkSize, $adaptiveChunkSize));
                $healthyChunkStreak = 0;
                $chunkRecoverySemRespThreshold = max(0.0, min($this->semResponseChunkThreshold, 0.15));
                $chunkRecoveryStep = max(1, (int) ceil($baseChunkSize * 0.10));
                $chunkRecoveryHealthyStreakTarget = 2;

                $nextPendRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.pend.a" . ($attempt + 1) . ".txt";
                $this->pendFiles[] = $nextPendRel;
                $currPendReal = $disk->path($currPendRel);
                $nextPendReal = $disk->path($nextPendRel);

                $nf = fopen($nextPendReal, 'c+');
                if ($nf === false) {
                    $this->failFinalize();
                    return;
                }
                if (!ftruncate($nf, 0)) {
                    fclose($nf);
                    $this->failFinalize();
                    return;
                }

                $retryAfterMaxSeen = 0;
                $semRespTotal = 0;
                $totalInAttempt = 0;

                $r2 = fopen($currPendReal, 'r');
                if ($r2 === false) {
                    fclose($nf);
                    $this->failFinalize();
                    return;
                }

                try {
                    $buf = [];
                    while (($line = fgets($r2)) !== false) {
                        if ($this->finishIfStopped($job))
                            return;

                        $pending = $this->decodePhaseOnePendingEntry((string) $line);
                        $cpf = $pending['cpf'] ?? null;
                        if (!is_string($cpf) || strlen($cpf) !== 11)
                            continue;

                        $buf[] = $cpf;
                        if (count($buf) >= max(1, $chunkSize)) {
                            $chunkSemResp = 0;
                            $chunkProcessed = 0;
                            $this->processChunk(
                                $api,
                                $job,
                                $buf,
                                $nf,
                                $retryAfterMaxSeen,
                                $semRespTotal,
                                $totalInAttempt,
                                $chunkSemResp,
                                $chunkProcessed,
                                $hybridOfflineApi,
                                $this->variant === 'hybrid' && $attempt === 1
                            );

                            $chunkSemRespRatio = $chunkProcessed > 0 ? ($chunkSemResp / $chunkProcessed) : 0.0;
                            if ($chunkProcessed > 0 && $chunkSemRespRatio >= $this->semResponseChunkThreshold) {
                                $previousChunkSize = $chunkSize;
                                $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                                $healthyChunkStreak = 0;
                                CltLog::warning("[CLT] Job {$this->jobId} – chunk problemático por sem_resposta.", [
                                    'attempt' => $attempt,
                                    'chunk_size_before' => $previousChunkSize,
                                    'chunk_size_after' => $chunkSize,
                                    'chunk_sem_resposta' => $chunkSemResp,
                                    'chunk_total' => $chunkProcessed,
                                    'chunk_sem_resposta_ratio' => round($chunkSemRespRatio, 4),
                                    'cooldown_seconds' => $this->semResponseChunkCooldownSeconds,
                                ]);

                                if ($this->semResponseChunkCooldownSeconds > 0) {
                                    if ($this->cooperativeSleep($this->semResponseChunkCooldownSeconds, $job)) {
                                        return;
                                    }
                                }
                            } elseif (
                                $chunkProcessed > 0
                                && $chunkSize < $baseChunkSize
                                && $chunkSemRespRatio <= $chunkRecoverySemRespThreshold
                            ) {
                                $healthyChunkStreak++;
                                if ($healthyChunkStreak >= $chunkRecoveryHealthyStreakTarget) {
                                    $previousChunkSize = $chunkSize;
                                    $chunkSize = min($baseChunkSize, $chunkSize + $chunkRecoveryStep);
                                    $healthyChunkStreak = 0;

                                    CltLog::info("[CLT] Job {$this->jobId} – recuperação gradual de chunk.", [
                                        'attempt' => $attempt,
                                        'chunk_size_before' => $previousChunkSize,
                                        'chunk_size_after' => $chunkSize,
                                        'chunk_sem_resposta_ratio' => round($chunkSemRespRatio, 4),
                                        'healthy_streak_target' => $chunkRecoveryHealthyStreakTarget,
                                    ]);
                                }
                            } else {
                                $healthyChunkStreak = 0;
                            }

                            $buf = [];
                            if ($this->chunkDelayMs > 0 && $this->microSleepCoop($this->chunkDelayMs, $job))
                                return;
                        }
                    }
                    if (!empty($buf)) {
                        $chunkSemResp = 0;
                        $chunkProcessed = 0;
                        $this->processChunk(
                            $api,
                            $job,
                            $buf,
                            $nf,
                            $retryAfterMaxSeen,
                            $semRespTotal,
                            $totalInAttempt,
                            $chunkSemResp,
                            $chunkProcessed,
                            $hybridOfflineApi,
                            $this->variant === 'hybrid' && $attempt === 1
                        );

                        $chunkSemRespRatio = $chunkProcessed > 0 ? ($chunkSemResp / $chunkProcessed) : 0.0;
                        if ($chunkProcessed > 0 && $chunkSemRespRatio >= $this->semResponseChunkThreshold) {
                            $previousChunkSize = $chunkSize;
                            $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                            $healthyChunkStreak = 0;
                            CltLog::warning("[CLT] Job {$this->jobId} – chunk final problemático por sem_resposta.", [
                                'attempt' => $attempt,
                                'chunk_size_before' => $previousChunkSize,
                                'chunk_size_after' => $chunkSize,
                                'chunk_sem_resposta' => $chunkSemResp,
                                'chunk_total' => $chunkProcessed,
                                'chunk_sem_resposta_ratio' => round($chunkSemRespRatio, 4),
                                'cooldown_applied' => false,
                            ]);
                        } elseif (
                            $chunkProcessed > 0
                            && $chunkSize < $baseChunkSize
                            && $chunkSemRespRatio <= $chunkRecoverySemRespThreshold
                        ) {
                            $healthyChunkStreak++;
                            if ($healthyChunkStreak >= $chunkRecoveryHealthyStreakTarget) {
                                $previousChunkSize = $chunkSize;
                                $chunkSize = min($baseChunkSize, $chunkSize + $chunkRecoveryStep);
                                $healthyChunkStreak = 0;

                                CltLog::info("[CLT] Job {$this->jobId} – recuperação gradual de chunk (final).", [
                                    'attempt' => $attempt,
                                    'chunk_size_before' => $previousChunkSize,
                                    'chunk_size_after' => $chunkSize,
                                    'chunk_sem_resposta_ratio' => round($chunkSemRespRatio, 4),
                                    'healthy_streak_target' => $chunkRecoveryHealthyStreakTarget,
                                ]);
                            }
                        } else {
                            $healthyChunkStreak = 0;
                        }

                        $buf = [];
                        if ($this->chunkDelayMs > 0 && $this->microSleepCoop($this->chunkDelayMs, $job))
                            return;
                    }
                } catch (FactaFatalAuthException $e) {
                    $this->abortPhaseOneDueToFatalTokenAuth(
                        $job,
                        $e->pendingCpfs(),
                        $e->abortCsvMessage(),
                        $r2,
                        $nf,
                        $nextPendReal
                    );
                    return;
                } finally {
                    fclose($r2);
                    fflush($nf);
                    fclose($nf);
                }

                $semRespRatio = $totalInAttempt > 0 ? ($semRespTotal / $totalInAttempt) : 0.0;
                $adaptiveChunkSize = max($minChunk, min($baseChunkSize, $chunkSize));

                if ($attempt < $maxAttempts && $disk->exists($nextPendRel) && ((int) $disk->size($nextPendRel)) > 0) {
                    $baseRetryAfter = $retryAfterMaxSeen > 0 ? min($retryAfterMaxSeen, $retryAfterCap) : 0;
                    $base = max(1, $retryDelay, $baseRetryAfter);
                    $sleepFactor = $semRespRatio >= 0.90 ? 2.0 : ($semRespRatio >= 0.50 ? 1.5 : 1.0);
                    $withFactor = (int) ceil($base * $sleepFactor);
                    $jitter = random_int(0, (int) max(1, ceil($withFactor * 0.15)));
                    $sleepSecs = $withFactor + $jitter;
                    if ($this->cooperativeSleep($sleepSecs, $job))
                        return;
                }

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

                if (function_exists('gc_collect_cycles'))
                    @gc_collect_cycles();
            }

            // 3) Fechamento de pendências (CSV)
            if ($disk->exists($currPendRel) && ((int) $disk->size($currPendRel)) > 0) {
                $r = fopen($disk->path($currPendRel), 'r');
                if ($r !== false) {
                    $rows = [];
                    $batch = 500;
                    $left = 0;

                    // Formato BR leve
                    $nowBr = date('d/m/Y H:i:s');

                    while (($line = fgets($r)) !== false) {
                        $pending = $this->decodePhaseOnePendingEntry((string) $line);
                        $cpf = $pending['cpf'] ?? null;
                        if (!is_string($cpf) || strlen($cpf) !== 11)
                            continue;
                        $row = $this->baseRow($cpf);
                        $row['numeroVinculos'] = 0;
                        $row['mensagem'] = $this->buildExhaustedAttemptsMensagem($pending['mensagem'] ?? null);
                        $row['consulted_at'] = $nowBr;
                        $row['fonteConsulta'] = $this->formatConsultaSource($this->finalConsultaSourceForPendingFailures());
                        $rows[] = $row;
                        $left++;
                        if (count($rows) >= $batch) {
                            $this->spoolAppendManyPersist($job, $rows);
                            $rows = [];
                            $nowBr = date('d/m/Y H:i:s');
                        }
                    }
                    if (!empty($rows)) {
                        $this->spoolAppendManyPersist($job, $rows);
                        $rows = [];
                    }
                    fclose($r);
                    if ($left > 0)
                        $this->accFail += $left;
                }
                try {
                    $disk->delete($currPendRel);
                } catch (Throwable) {
                }
            }

            $this->updateTotalsThrottled($job, [], true);

            // A fase 2 trabalha com delta incremental e consolida no spool ao final.
            // Fecha o writer append antes da fase 2 para evitar contenção de arquivo.
            $this->closeSpoolWriter();
            if ($this->supportsCreditPhaseTwo() && $api instanceof \App\Modules\CLT\Services\FactaApiService) {
                $this->dispatchPhaseTwo($job);
                return;
            }

            $this->dispatchFinalize('concluido');
        } catch (Throwable $e) {
            CltLog::error("[CLT] Job {$this->jobId} finalizado por exceção não tratada: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            try {
                $this->failFinalize();
            } catch (Throwable) {
            }
        } finally {
            if ($api instanceof \App\Modules\CLT\Services\FactaApiService) {
                try {
                    $api->flushRuntimeHttpCounters();
                } catch (Throwable $e) {
                    CltLog::warning('[CLT] Falha ao flush final dos contadores HTTP FACTA por job.', [
                        'job_id' => $this->jobId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            if (!$this->finalizationTriggered) {
                $this->flushPhase2DeltaBuffer(true);
            } else {
                $this->phase2DeltaBuffer = [];
            }
            $this->flushPhase2PendingBuffer(true);
            if (is_resource($this->spoolFp)) {
                @fflush($this->spoolFp);
                @fclose($this->spoolFp);
            }
            $this->deletePendFiles();
        }
    }

    /**
     * Aborta o job imediatamente quando o /gera-token entra em estado terminal.
     * Escreve em streaming os CPFs ainda pendentes no CSV com mensagem de aborto.
     *
     * @param array<int,string> $chunkPendingCpfs
     * @param resource|null $currentPendReader
     * @param resource|null $nextPendHandle
     */
    private function abortPhaseOneDueToFatalTokenAuth(
        CltConsultJob $job,
        array $chunkPendingCpfs,
        string $abortMessage,
        $currentPendReader,
        $nextPendHandle,
        string $nextPendReal
    ): void {
        $batchSize = 500;
        $rows = [];
        $abortedCount = 0;
        $nowBr = date('d/m/Y H:i:s');

        CltLog::warning('[FACTA] /gera-token aborto de processamento; finalizando job e pendências.', [
            'job_id' => $this->jobId,
            'stage' => 'token_abort',
            'mensagem' => $abortMessage,
        ]);

        if (is_resource($nextPendHandle)) {
            @fflush($nextPendHandle);
        }

        $appendCpf = function (string $rawCpf) use (&$rows, &$abortedCount, &$nowBr, $batchSize, $abortMessage, $job): void {
            $pending = $this->decodePhaseOnePendingEntry($rawCpf);
            $cpf = $pending['cpf'] ?? null;
            if (!is_string($cpf) || strlen($cpf) !== 11) {
                return;
            }

            $row = $this->baseRow($cpf);
            $row['numeroVinculos'] = 0;
            $row['mensagem'] = $abortMessage;
            $row['consulted_at'] = $nowBr;
            $row['fonteConsulta'] = $this->formatConsultaSource($this->finalConsultaSourceForPendingFailures());
            $rows[] = $row;
            $abortedCount++;

            if (count($rows) >= $batchSize) {
                $this->spoolAppendManyPersist($job, $rows);
                $rows = [];
                $nowBr = date('d/m/Y H:i:s');
            }
        };

        foreach ($chunkPendingCpfs as $chunkPendingCpf) {
            $appendCpf((string) $chunkPendingCpf);
        }

        if (is_resource($currentPendReader)) {
            while (($line = fgets($currentPendReader)) !== false) {
                $appendCpf((string) $line);
            }
        }

        if ($nextPendReal !== '' && is_file($nextPendReal)) {
            $nextReader = @fopen($nextPendReal, 'r');
            if (is_resource($nextReader)) {
                try {
                    while (($line = fgets($nextReader)) !== false) {
                        $appendCpf((string) $line);
                    }
                } finally {
                    @fclose($nextReader);
                }
            }
        }

        if (!empty($rows)) {
            $this->spoolAppendManyPersist($job, $rows);
            $rows = [];
        }

        if ($abortedCount > 0) {
            $this->accFail += $abortedCount;
        }
        $this->updateTotalsThrottled($job, [], true);

        CltLog::error('[CLT] Job encerrado com abort no /gera-token (status concluido).', [
            'job_id' => $this->jobId,
            'remaining_failed_count' => $abortedCount,
            'abort_message' => $abortMessage,
        ]);

        $this->dispatchFinalize('concluido');
    }

    private function processChunk(
        $api,
        CltConsultJob $job,
        array $chunkCpfs,
        $nextPendHandle,
        int &$retryAfterMaxSeen,
        int &$semRespTotal,
        int &$totalInAttempt,
        int &$semRespInChunkOut,
        int &$chunkProcessedOut,
        ?\App\Modules\CLT\Services\CltOfflineApiService $hybridOfflineApi = null,
        bool $allowHybridOfflineReuse = false
    ): void {
        $t0 = microtime(true);
        $chunkCount = count($chunkCpfs);
        $semRespInChunkOut = 0;
        $chunkProcessedOut = 0;

        $micro = max(1, min($this->subchunkSize, $chunkCount));
        $slices = ($micro >= $chunkCount) ? [$chunkCpfs] : array_chunk($chunkCpfs, $micro);
        $sliceTotal = count($slices);

        $rows = [];
        $snapRows = [];
        $eligibleInChunk = 0;
        $ineligibleInChunk = 0;
        $notFoundInChunk = 0;
        $failTermInChunk = 0;
        $semRespInChunk = 0;
        $onlineAttemptsInChunk = 0;

        // Captura o timestamp BR uma vez por chunk para reutilizar nas linhas.
        $nowStr = Carbon::now('America/Sao_Paulo')->format('d/m/Y H:i:s');

        foreach ($slices as $idx => $slice) {
            if ($this->finishIfStopped($job))
                return;

            $onlineSlice = $slice;
            if (
                $allowHybridOfflineReuse
                && $api instanceof \App\Modules\CLT\Services\FactaApiService
                && $hybridOfflineApi instanceof \App\Modules\CLT\Services\CltOfflineApiService
            ) {
                $onlineSlice = $this->precheckHybridSlice(
                    $hybridOfflineApi,
                    $slice,
                    $nowStr,
                    $rows,
                    $snapRows,
                    $eligibleInChunk,
                    $ineligibleInChunk
                );
                $this->flushPhaseOneBuffers($job, $rows, $snapRows);
            }

            if (empty($onlineSlice)) {
                if ($this->subchunkDelayMs > 0 && $idx < $sliceTotal - 1) {
                    if ($this->microSleepCoop($this->subchunkDelayMs, $job))
                        return;
                }
                continue;
            }

            $onlineAttemptsInChunk += count($onlineSlice);
            $consultaSource = $api instanceof \App\Modules\CLT\Services\CltOfflineApiService
                ? 'offline'
                : 'online';

            try {
                $batchResults = $api->autorizaConsultaLote($onlineSlice);
            } catch (FactaFatalAuthException $e) {
                $pendingCpfs = [];
                for ($pendingSliceIdx = $idx; $pendingSliceIdx < $sliceTotal; $pendingSliceIdx++) {
                    $pendingSlice = $pendingSliceIdx === $idx
                        ? $onlineSlice
                        : ($slices[$pendingSliceIdx] ?? []);
                    if (!is_array($pendingSlice)) {
                        continue;
                    }
                    foreach ($pendingSlice as $pendingCpf) {
                        $digits = preg_replace('/\D+/', '', (string) $pendingCpf);
                        if (is_string($digits) && strlen($digits) === 11) {
                            $pendingCpfs[] = $digits;
                        }
                    }
                }

                throw new FactaFatalAuthException(
                    $e->getMessage(),
                    $pendingCpfs,
                    $e,
                    $e->abortCsvMessage()
                );
            } catch (\Throwable $e) {
                CltLog::error("[CLT] Job {$this->jobId} erro no autorizaConsultaLote: " . $e->getMessage(), ['exception' => $e]);
                foreach ($onlineSlice as $cpf) {
                    $this->appendPhaseOnePendingEntryOrFail($nextPendHandle, $cpf, 'Exceção: ' . $e->getMessage());
                }
                $semRespTotal += count($onlineSlice);
                $semRespInChunk += count($onlineSlice);
                if ($this->subchunkDelayMs > 0 && $this->microSleepCoop($this->subchunkDelayMs, $job))
                    return;
                continue;
            }

            foreach ($onlineSlice as $cpf) {
                $res = $batchResults[$cpf] ?? $this->defaultConsultaErrorResult();

                $http = $res['http_status'] ?? null;
                if ($http === null) {
                    $semRespInChunk++;
                    $semRespTotal++;
                }
                if (!empty($res['retry_after'])) {
                    $retryAfterMaxSeen = max($retryAfterMaxSeen, (int) $res['retry_after']);
                }

                if (!empty($res['ok'])) {
                    $this->appendSuccessfulConsultaResult(
                        $cpf,
                        $res,
                        $nowStr,
                        $rows,
                        $snapRows,
                        $eligibleInChunk,
                        $ineligibleInChunk,
                        $consultaSource
                    );
                } else {
                    $this->appendFailedConsultaResult(
                        $cpf,
                        $res,
                        $nowStr,
                        $nextPendHandle,
                        $rows,
                        $snapRows,
                        $notFoundInChunk,
                        $failTermInChunk,
                        $consultaSource
                    );
                }

                $this->flushPhaseOneBuffers($job, $rows, $snapRows);
            }

            $this->flushPhaseOneBuffers($job, $rows, $snapRows);

            if ($this->subchunkDelayMs > 0 && $idx < $sliceTotal - 1) {
                if ($this->microSleepCoop($this->subchunkDelayMs, $job))
                    return;
            }
        }

        if ($eligibleInChunk > 0)
            $this->accEligible += $eligibleInChunk;
        if ($ineligibleInChunk > 0)
            $this->accIneligible += $ineligibleInChunk;
        if ($notFoundInChunk > 0)
            $this->accNotFound += $notFoundInChunk;
        if ($failTermInChunk > 0)
            $this->accFail += $failTermInChunk;

        if (!empty($rows)) {
            $this->spoolAppendManyPersist($job, $rows);
            $rows = [];
        }
        if (!empty($snapRows)) {
            $this->persistSnapshots($snapRows);
            $snapRows = [];
        }

        // Fase 1: persistir progresso no fim de cada chunk (sem depender da janela temporal).
        $this->updateTotalsThrottled($job, [], true);
        $processedForHealth = $allowHybridOfflineReuse ? $onlineAttemptsInChunk : $chunkCount;
        $totalInAttempt += $processedForHealth;
        $semRespInChunkOut = $semRespInChunk;
        $chunkProcessedOut = $processedForHealth;

        if ($this->chunkPerfDebug) {
            $elapsed = microtime(true) - $t0;
            $rps = $elapsed > 0 ? number_format($chunkCount / $elapsed, 1, ',', '.') : 'inf';
            CltLog::debug("[CLT] job={$this->jobId} chunk=OK size={$chunkCount} sub={$micro} time=" . number_format($elapsed, 3, ',', '.') . "s rate={$rps} cps");
        }
    }

    private function defaultConsultaErrorResult(string $message = 'Sem resposta do serviço'): array
    {
        return [
            'ok' => false,
            'mensagem' => $message,
            'vinculos' => null,
            'retriable' => true,
            'not_found' => false,
            'http_status' => null,
            'retry_after' => null,
        ];
    }

    private function encodePhaseOnePendingEntry(string $cpf, ?string $mensagem = null): string
    {
        $digits = preg_replace('/\D+/', '', $cpf);
        if (!is_string($digits) || strlen($digits) !== 11) {
            return '';
        }

        $normalizedMensagem = $this->normalizePendingRetryMensagem($mensagem);
        if ($normalizedMensagem === null) {
            return $digits . "\n";
        }

        return $digits . "\t" . base64_encode($normalizedMensagem) . "\n";
    }

    /**
     * @return array{cpf:?string,mensagem:?string}
     */
    private function decodePhaseOnePendingEntry(string $line): array
    {
        $raw = rtrim($line, "\r\n");
        if ($raw === '') {
            return ['cpf' => null, 'mensagem' => null];
        }

        [$cpfRaw, $encodedMensagem] = array_pad(explode("\t", $raw, 2), 2, null);
        $cpf = preg_replace('/\D+/', '', (string) $cpfRaw);
        if (!is_string($cpf) || strlen($cpf) !== 11) {
            return ['cpf' => null, 'mensagem' => null];
        }

        $mensagem = null;
        if (is_string($encodedMensagem) && $encodedMensagem !== '') {
            $decoded = base64_decode($encodedMensagem, true);
            if (is_string($decoded) && $decoded !== '') {
                $mensagem = $this->normalizePendingRetryMensagem($decoded);
            }
        }

        return ['cpf' => $cpf, 'mensagem' => $mensagem];
    }

    private function normalizePendingRetryMensagem(?string $mensagem): ?string
    {
        if (!is_string($mensagem)) {
            return null;
        }

        $normalized = trim($mensagem);
        if ($normalized === '') {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $normalized);
        if (is_string($collapsed) && $collapsed !== '') {
            $normalized = trim($collapsed);
        }

        return $normalized !== '' ? $normalized : null;
    }

    private function buildExhaustedAttemptsMensagem(?string $ultimaMensagem): string
    {
        $base = 'Não foi possível consultar após múltiplas tentativas';
        $normalized = $this->normalizePendingRetryMensagem($ultimaMensagem);
        if ($normalized === null) {
            return $base;
        }

        return $base . ' (' . $normalized . ')';
    }

    private function flushPhaseOneBuffers(CltConsultJob $job, array &$rows, array &$snapRows): void
    {
        if (count($rows) >= $this->rowsBufferFlush) {
            $this->spoolAppendManyPersist($job, $rows);
            $rows = [];
        }
        if (count($snapRows) >= $this->snapBufferFlush) {
            $this->persistSnapshots($snapRows);
            $snapRows = [];
        }
    }

    private function appendSuccessfulConsultaResult(
        string $cpf,
        array $res,
        string $nowStr,
        array &$rows,
        array &$snapRows,
        int &$eligibleCount,
        int &$ineligibleCount,
        string $consultaSource
    ): void {
        $vinculos = $res['vinculos'] ?? [];
        $total = is_array($vinculos) ? count($vinculos) : 0;

        if ($total > 0) {
            $bestIdx = $this->pickLatestVinculoIndex($vinculos);
            $best = ($bestIdx !== null && isset($vinculos[$bestIdx]) && is_array($vinculos[$bestIdx]))
                ? $vinculos[$bestIdx]
                : null;

            foreach ($vinculos as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $row = $this->baseRow($cpf);
                $row['numeroVinculos'] = $total;

                $row['elegivel'] = $v['elegivel'] ?? null;
                $row['valorMargemDisponivel'] = $v['valorMargemDisponivel'] ?? null;
                $row['valorMaximoPrestacao'] = $this->computeValorMaxPrest($v['valorMargemDisponivel'] ?? null);
                $row['valorBaseMargem'] = $v['valorBaseMargem'] ?? null;
                $row['valorTotalVencimentos'] = $v['valorTotalVencimentos'] ?? null;

                $row['nomeEmpregador'] = $v['nomeEmpregador'] ?? null;
                $row['numeroInscricaoEmpregador'] = $v['numeroInscricaoEmpregador'] ?? null;
                $row['inscricaoEmpregador_descricao'] = $v['inscricaoEmpregador_descricao'] ?? null;
                $row['matricula'] = $v['matricula'] ?? null;
                $row['dataAdmissao'] = $v['dataAdmissao'] ?? null;
                $row['tempoAdmissaoMeses'] = $this->computeTempoAdmissaoMeses($v['dataAdmissao'] ?? null, $v['dataDesligamento'] ?? null);
                $row['dataDesligamento'] = $v['dataDesligamento'] ?? null;
                $row['codigoMotivoDesligamento'] = $v['codigoMotivoDesligamento'] ?? null;

                $row['codigoCategoriaTrabalhador'] = $v['codigoCategoriaTrabalhador'] ?? null;
                $row['cbo_descricao'] = $v['cbo_descricao'] ?? null;
                $row['cnae_descricao'] = $v['cnae_descricao'] ?? null;
                $row['dataInicioAtividadeEmpregador'] = $v['dataInicioAtividadeEmpregador'] ?? null;
                $row['mesesEmpresaEmpregador'] = $this->computeMesesEmpresaEmpregador($v['dataInicioAtividadeEmpregador'] ?? null);

                $row['possuiAlertas'] = $v['possuiAlertas'] ?? null;
                $row['qtdEmprestimosAtivosSuspensos'] = $v['qtdEmprestimosAtivosSuspensos'] ?? null;
                $row['emprestimosLegados'] = $v['emprestimosLegados'] ?? null;
                $row['pessoaExpostaPoliticamente_descricao'] = $v['pessoaExpostaPoliticamente_descricao'] ?? null;

                $row['nome'] = $v['nome'] ?? null;
                $row['dataNascimento'] = $v['dataNascimento'] ?? null;
                $row['idade'] = $this->computeIdadeAnos($v['dataNascimento'] ?? null);
                $row['sexo_descricao'] = $v['sexo_descricao'] ?? null;

                $row['status_code'] = $v['status_code'] ?? null;
                $row['mensagem'] = $this->normalizeConsultaMensagemForCsv($res['mensagem'] ?? 'OK') ?? 'OK';
                $row['fonteConsulta'] = $this->formatConsultaSource($consultaSource);

                $rawUpdated = $v['updated_at'] ?? ($v['created_at'] ?? null);
                $row['updated_at'] = $this->toBrDateTime($rawUpdated);
                $row['consulted_at'] = $nowStr;

                $rows[] = $row;
            }

            if ($best) {
                $margemFloat = $this->toFloatSmart($best['valorMargemDisponivel'] ?? null);
                $valorMaxFloat = $this->computeValorMaxPrestFloat($best['valorMargemDisponivel'] ?? null);

                $snapRows[] = [
                    'cpf' => $cpf,
                    'nome' => $best['nome'] ?? null,
                    'elegivel' => $this->simNaoToBool($best['elegivel'] ?? null),
                    'data_nasc' => $this->parseDateFlexible($best['dataNascimento'] ?? null),
                    'idade' => $this->computeIdadeAnos($best['dataNascimento'] ?? null),
                    'sexo' => $best['sexo_descricao'] ?? null,
                    'data_adm' => $this->parseDateFlexible($best['dataAdmissao'] ?? null),
                    'meses_adm' => $this->computeTempoAdmissaoMeses($best['dataAdmissao'] ?? null, $best['dataDesligamento'] ?? null),
                    'valor_renda' => $this->toFloatSmart($best['valorTotalVencimentos'] ?? null),
                    'valor_base' => $this->toFloatSmart($best['valorBaseMargem'] ?? null),
                    'margem_disp' => $margemFloat,
                    'valor_max' => $valorMaxFloat,
                    'cat_cod' => $best['codigoCategoriaTrabalhador'] ?? null,
                    'inicio_emp' => $this->parseDateFlexible($best['dataInicioAtividadeEmpregador'] ?? null),
                    'meses_emp' => $this->computeMesesEmpresaEmpregador($best['dataInicioAtividadeEmpregador'] ?? null),
                    'qtd_ems' => isset($best['qtdEmprestimosAtivosSuspensos']) ? (int) $best['qtdEmprestimosAtivosSuspensos'] : null,
                    'legados' => array_key_exists('emprestimosLegados', $best)
                        ? $this->simNaoToBool($best['emprestimosLegados'])
                        : null,
                    'src_updated_at' => $best['updated_at'] ?? ($best['created_at'] ?? null),
                    'not_found' => false,
                    'snapshot_mode' => $consultaSource,
                ];
            }
        } else {
            $row = $this->baseRow($cpf);
            $row['numeroVinculos'] = 0;
            $row['mensagem'] = $this->normalizeConsultaMensagemForCsv($res['mensagem'] ?? 'Sem vínculos') ?? 'Sem vínculos';
            $row['consulted_at'] = $nowStr;
            $row['fonteConsulta'] = $this->formatConsultaSource($consultaSource);
            $rows[] = $row;
        }

        if ($this->isCpfEligibleByVinculos($vinculos)) {
            $eligibleCount++;
        } else {
            $ineligibleCount++;
        }
    }

    private function appendFailedConsultaResult(
        string $cpf,
        array $res,
        string $nowStr,
        $nextPendHandle,
        array &$rows,
        array &$snapRows,
        int &$notFoundCount,
        int &$failTermCount,
        string $consultaSource
    ): void {
        $msg = $this->normalizeConsultaMensagemForCsv($res['mensagem'] ?? 'Falha na consulta') ?? 'Falha na consulta';

        if (!empty($res['not_found'])) {
            $row = $this->baseRow($cpf);
            $row['numeroVinculos'] = 0;
            $row['mensagem'] = $msg;
            $row['consulted_at'] = $nowStr;
            $row['fonteConsulta'] = $this->formatConsultaSource($consultaSource);
            $rows[] = $row;

            $snapRows[] = [
                'cpf' => $cpf,
                'not_found' => true,
                'src_updated_at' => null,
                'snapshot_mode' => $consultaSource,
            ];

            $notFoundCount++;
        } elseif (($res['retriable'] ?? true) === false) {
            $row = $this->baseRow($cpf);
            $row['numeroVinculos'] = 0;
            $row['mensagem'] = $msg;
            $row['consulted_at'] = $nowStr;
            $row['fonteConsulta'] = $this->formatConsultaSource($consultaSource);
            $rows[] = $row;
            $failTermCount++;
        } else {
            $this->appendPhaseOnePendingEntryOrFail($nextPendHandle, $cpf, $msg);
        }
    }

    private function precheckHybridSlice(
        \App\Modules\CLT\Services\CltOfflineApiService $offlineApi,
        array $slice,
        string $nowStr,
        array &$rows,
        array &$snapRows,
        int &$eligibleCount,
        int &$ineligibleCount
    ): array {
        try {
            $offlineResults = $offlineApi->autorizaConsultaLote($slice);
        } catch (\Throwable) {
            return $slice;
        }

        $onlineCpfs = [];
        foreach ($slice as $cpf) {
            $res = $offlineResults[$cpf] ?? $this->defaultConsultaErrorResult();
            if (!$this->shouldReuseOfflineResultInHybrid($res)) {
                $onlineCpfs[] = $cpf;
                continue;
            }

            $this->appendSuccessfulConsultaResult(
                $cpf,
                $res,
                $nowStr,
                $rows,
                $snapRows,
                $eligibleCount,
                $ineligibleCount,
                'offline'
            );
        }

        return $onlineCpfs;
    }

    private function shouldReuseOfflineResultInHybrid(array $res): bool
    {
        if (empty($res['ok'])) {
            return false;
        }

        $vinculos = $res['vinculos'] ?? null;
        if (!is_array($vinculos) || empty($vinculos)) {
            return false;
        }

        $latestUpdatedAt = $this->latestHybridOfflineUpdatedAt($vinculos);
        if ($latestUpdatedAt === null) {
            return false;
        }

        if ($this->hybridOfflineMaxAgeDays > 0) {
            $cutoff = Carbon::now('UTC')
                ->subDays($this->hybridOfflineMaxAgeDays)
                ->format('Y-m-d H:i:s');

            if (strcmp($latestUpdatedAt, $cutoff) < 0) {
                return false;
            }
        }

        return $this->hybridOfflineEligibleRowsHaveRequiredFields($vinculos);
    }

    private function latestHybridOfflineUpdatedAt(array $vinculos): ?string
    {
        $latest = null;
        $foundRelevantVinculo = false;

        foreach ($vinculos as $vinculo) {
            if (!is_array($vinculo)) {
                return null;
            }

            if (!$this->isRelevantHybridOfflineVinculo($vinculo)) {
                continue;
            }

            $foundRelevantVinculo = true;

            $updatedAt = $this->parseDateTimeFlexible($vinculo['updated_at'] ?? ($vinculo['created_at'] ?? null));
            if ($updatedAt === null) {
                return null;
            }

            if ($latest === null || strcmp($updatedAt, $latest) > 0) {
                $latest = $updatedAt;
            }
        }

        return $foundRelevantVinculo ? $latest : null;
    }

    private function hybridOfflineEligibleRowsHaveRequiredFields(array $vinculos): bool
    {
        foreach ($vinculos as $vinculo) {
            if (!is_array($vinculo)) {
                return false;
            }

            if ($this->simNaoToBool($vinculo['elegivel'] ?? null) !== true) {
                continue;
            }

            if (!$this->hasHybridCreditPhaseRequiredFields($vinculo)) {
                return false;
            }
        }

        return true;
    }

    private function isRelevantHybridOfflineVinculo(array $vinculo): bool
    {
        if ($this->simNaoToBool($vinculo['elegivel'] ?? null) !== null) {
            return true;
        }

        foreach ([
            'nome',
            'dataNascimento',
            'dataAdmissao',
            'valorTotalVencimentos',
            'valorBaseMargem',
            'valorMargemDisponivel',
            'numeroInscricaoEmpregador',
            'nomeEmpregador',
            'codigoCategoriaTrabalhador',
        ] as $field) {
            $value = $vinculo[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
            if ($value !== null && !is_string($value)) {
                return true;
            }
        }

        return false;
    }

    private function hasHybridCreditPhaseRequiredFields(array $vinculo): bool
    {
        $matricula = trim((string) ($vinculo['matricula'] ?? ''));
        if ($matricula === '') {
            return false;
        }

        if ($this->parseCarbonDateFlexible($vinculo['dataNascimento'] ?? null) === null) {
            return false;
        }

        if ($this->parseCarbonDateFlexible($vinculo['dataAdmissao'] ?? null) === null) {
            return false;
        }

        if ($this->toFloatSmart($vinculo['valorTotalVencimentos'] ?? null) === null) {
            return false;
        }

        return $this->computeValorMaxPrestFloat($vinculo['valorMargemDisponivel'] ?? null) !== null;
    }

    /**
     * Helper ultra leve para formatar data (d/m/Y H:i:s) sem instanciar Carbon.
     */
    private function toBrDateTime(?string $val): ?string
    {
        if (!$val) return null;
        try {
            // strtotime lida bem com formatos ISO/SQL, inclusive com milissegundos
            $ts = strtotime($val);
            if ($ts === false) return null;
            return date('d/m/Y H:i:s', $ts);
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatConsultaSource(?string $source): ?string
    {
        return match ($source) {
            'online' => 'ONLINE',
            'offline' => 'OFFLINE',
            default => null,
        };
    }

    /**
     * Contagem por CPF:
     * - elegível: existe ao menos um vínculo com elegivel=true
     * - inelegível: sem vínculos elegíveis (inclui lista vazia)
     */
    private function isCpfEligibleByVinculos($vinculos): bool
    {
        if (!is_array($vinculos) || empty($vinculos)) {
            return false;
        }

        foreach ($vinculos as $v) {
            if (!is_array($v)) {
                continue;
            }

            if ($this->simNaoToBool($v['elegivel'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    private function baseRow(string $cpf): array
    {
        $row = $this->baseRowTemplate;
        $row['cpf'] = $cpf;
        return $row;
    }

    private function spoolAppendManyPersist(CltConsultJob $job, array $rows): void
    {
        if (!is_resource($this->spoolFp))
            throw new \RuntimeException("Writer do spool não inicializado.");

        if (!flock($this->spoolFp, LOCK_EX)) {
            throw new \RuntimeException("Não foi possível bloquear o spool para escrita.");
        }

        try {
            foreach ($rows as $row) {
                $row = CltSchema::normalizeAssocRowForCsv($row);
                $ordered = [];
                foreach (CltSchema::COLS as $key) {
                    $ordered[] = $row[$key] ?? null;
                }
                $this->writeCsvRowOrFail($this->spoolFp, $ordered, 'spool principal');
            }

            if (!fflush($this->spoolFp)) {
                throw new \RuntimeException("Falha ao sincronizar o spool principal em disco.");
            }
        } finally {
            flock($this->spoolFp, LOCK_UN);
        }

        // apenas flush por tempo/force (nenhum contador de linhas)
        $this->updateTotalsThrottled($job);
    }

    private function updateTotalsThrottled(CltConsultJob $job, array $extra = [], bool $force = false): void
    {
        $now = microtime(true);

        $triggerTime = ($now - $this->lastFlushAt) >= $this->flushEverySecs;
        $shouldFlush = $force || $triggerTime;
        if (!$shouldFlush)
            return;

        // lê bytes apenas quando realmente houver flush de progresso
        try {
            clearstatcache(true, $this->spoolReal);
            $bytes = file_exists($this->spoolReal) ? (int) filesize($this->spoolReal) : 0;
        } catch (Throwable) {
            $bytes = 0;
        }

        $updates = array_merge([
            'spool_bytes' => $bytes,
            'updated_at' => Carbon::now(),
        ], $extra);

        if ($this->accEligible > 0)
            $updates['elegivel_count'] = DB::raw('COALESCE(elegivel_count,0) + ' . $this->accEligible);
        if ($this->accIneligible > 0)
            $updates['inelegivel_count'] = DB::raw('COALESCE(inelegivel_count,0) + ' . $this->accIneligible);
        if ($this->accNotFound > 0)
            $updates['not_found_count'] = DB::raw('COALESCE(not_found_count,0) + ' . $this->accNotFound);
        if ($this->accFail > 0)
            $updates['fail_count'] = DB::raw('COALESCE(fail_count,0) + ' . $this->accFail);

        if ($this->flushProgressLog) {
            try {
                CltLog::info('[CLT][FLUSH]', [
                    'job' => $this->jobId,
                    'force' => $force,
                    'bytes_now' => $bytes,
                ]);
            } catch (Throwable $e) {
            }
        }

        DB::table('clt_consult_jobs')->where('id', $job->id)->update($updates);

        $job->spool_bytes = $bytes;
        $this->lastFlushAt = $now;
        $this->accEligible = $this->accIneligible = $this->accNotFound = $this->accFail = 0;
    }

    private function computeValorMaxPrest($valor): ?string
    {
        $n = $this->computeValorMaxPrestFloat($valor);
        if ($n === null)
            return null;
        return number_format($n, 2, ',', '');
    }

    private function computeValorMaxPrestFloat($valor): ?float
    {
        $f = $this->toFloatSmart($valor);
        if ($f === null)
            return null;
        return round($f * 0.70, 2);
    }

    private function computeTempoAdmissaoMeses(?string $admissao, ?string $deslig): ?int
    {
        try {
            $a = $this->parseCarbonDateFlexible($admissao);
            if (!$a)
                return null;
            $b = $this->parseCarbonDateFlexible($deslig) ?? Carbon::now('America/Sao_Paulo');
            return $a->diffInMonths($b);
        } catch (\Throwable) {
            return null;
        }
    }

    private function computeMesesEmpresaEmpregador(?string $inicioEmp): ?int
    {
        try {
            $d = $this->parseCarbonDateFlexible($inicioEmp);
            if (!$d)
                return null;
            $now = Carbon::now('America/Sao_Paulo');
            return $d->diffInMonths($now);
        } catch (\Throwable) {
            return null;
        }
    }

    private function computeIdadeAnos(?string $nasc): ?int
    {
        try {
            $d = $this->parseCarbonDateFlexible($nasc);
            if (!$d)
                return null;
            return $d->age;
        } catch (\Throwable) {
            return null;
        }
    }

    private function toFloatSmart($val): ?float
    {
        if ($val === null)
            return null;
        if (is_float($val) || is_int($val))
            return (float) $val;

        $s = trim((string) $val);
        if ($s === '')
            return null;

        $s = preg_replace('/[^\d,.\-+]/', '', $s);
        if ($s === '' || $s === '-' || $s === '+')
            return null;

        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');

        $decimalSep = null;
        if ($lastComma === false && $lastDot === false) {
            return is_numeric($s) ? (float) $s : null;
        } elseif ($lastComma === false) {
            $decimalSep = '.';
        } elseif ($lastDot === false) {
            $decimalSep = ',';
        } else {
            $decimalSep = ($lastComma > $lastDot) ? ',' : '.';
        }

        if ($decimalSep === ',') {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            $s = str_replace(',', '', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }

    private function pickLatestVinculoIndex(array $vinculos): ?int
    {
        $bestIdx = null;
        $bestKey = null;
        foreach ($vinculos as $i => $v) {
            if (!is_array($v))
                continue;
            $d = $v['dataAdmissao'] ?? null;
            $key = null;
            try {
                $c = $this->parseCarbonDateFlexible($d);
                if ($c)
                    $key = $c->timestamp;
            } catch (\Throwable) {
                $key = null;
            }
            if ($key === null)
                continue;
            if ($bestIdx === null || $key > $bestKey) {
                $bestIdx = $i;
                $bestKey = $key;
            }
        }
        if ($bestIdx !== null)
            return $bestIdx;

        foreach ($vinculos as $i => $v) {
            if (is_array($v))
                return $i;
        }

        return null;
    }

    private function parseDateFlexible(?string $s): ?string
    {
        $c = $this->parseCarbonDateFlexible($s);
        return $c ? $c->toDateString() : null;
    }

    private function parseCarbonDateFlexible(?string $s): ?Carbon
    {
        if (!$s || !is_string($s))
            return null;
        $t = trim($s);
        if ($t === '')
            return null;

        try {
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $t)) {
                return Carbon::createFromFormat('d/m/Y', $t);
            }

            if (preg_match('/^\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}(:\d{2})?$/', $t)) {
                if (strlen($t) === 16) {
                    return Carbon::createFromFormat('d/m/Y H:i', $t);
                }
                return Carbon::createFromFormat('d/m/Y H:i:s', $t);
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $t)) {
                return Carbon::createFromFormat('Y-m-d', $t);
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}[ T].+$/', $t)) {
                return Carbon::parse($t);
            }

            // Evita interpretações ambíguas de datas com barra em locale diferente.
            if (str_contains($t, '/')) {
                return null;
            }

            return Carbon::parse($t);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDateTimeFlexible(?string $s): ?string
    {
        if (!$s || !is_string($s))
            return null;
        $t = trim($s);
        if ($t === '')
            return null;
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+$/', $t)) {
                $c = Carbon::createFromFormat('Y-m-d H:i:s.u', $t, 'UTC');
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $t)) {
                $c = Carbon::createFromFormat('Y-m-d H:i:s', $t, 'UTC');
            } else {
                $c = Carbon::parse($t, 'UTC');
            }
            return $c->setTimezone('UTC')->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function persistSnapshots(array $snapRows): void
    {
        if (empty($snapRows))
            return;

        try {
            $cpfs = [];
            $payload = [];
            $needsExistingLookup = false;

            foreach ($snapRows as $r) {
                $cpf = (string) ($r['cpf'] ?? '');
                if ($cpf === '' || strlen($cpf) !== 11)
                    continue;

                $cpfs[] = $cpf;

                $srcUpdated = $this->parseDateTimeFlexible($r['src_updated_at'] ?? null);
                $snapshotMode = (($r['snapshot_mode'] ?? ($this->variant === 'online' ? 'online' : 'offline')) === 'online')
                    ? 'online'
                    : 'offline';
                if ($snapshotMode !== 'online') {
                    $needsExistingLookup = true;
                }

                $payload[] = [
                    'cpf' => $cpf,
                    'nome' => $r['nome'] ?? null,
                    'elegivel' => array_key_exists('elegivel', $r) ? $this->simNaoToBool($r['elegivel']) : null,
                    'data_nascimento' => $r['data_nasc'] ?? null,
                    'idade' => $r['idade'] ?? null,
                    'sexo' => $r['sexo'] ?? null,

                    'data_admissao' => $r['data_adm'] ?? null,
                    'meses_admissao' => $r['meses_adm'] ?? null,

                    'valor_renda' => $r['valor_renda'] ?? null,
                    'valor_base_margem' => $r['valor_base'] ?? null,
                    'margem_disponivel' => $r['margem_disp'] ?? null,
                    'valor_max_prestacao' => $r['valor_max'] ?? null,

                    'categoria_trabalhador_codigo' => $r['cat_cod'] ?? null,
                    'inicio_atividade_empregador' => $r['inicio_emp'] ?? null,

                    // NOVO: persistir meses da empresa
                    'meses_empresa_empregador' => $r['meses_emp'] ?? null,

                    'qtd_emprestimos_ativos_suspensos' => $r['qtd_ems'] ?? null,
                    'emprestimos_legados' => $r['legados'] ?? null,

                    'not_found' => !empty($r['not_found']),

                    '_src_updated_at' => $srcUpdated,
                    '_snapshot_mode' => $snapshotMode,
                ];
            }
            if (empty($payload))
                return;

            $leadMap = DB::table('leads')
                ->whereIn('cpf', array_values(array_unique($cpfs)))
                ->pluck('id', 'cpf');

            foreach ($payload as &$row) {
                $row['lead_id'] = $leadMap[$row['cpf']] ?? null;
            }
            unset($row);

            $nowUtc = Carbon::now('UTC')->format('Y-m-d H:i:s');
            $existing = collect();
            if ($needsExistingLookup) {
                $existing = DB::table('clt_snapshots')
                    ->whereIn('cpf', array_values(array_unique($cpfs)))
                    ->select('cpf', 'updated_at', 'not_found')
                    ->get()
                    ->keyBy('cpf');
            }

            $toUpsert = [];
            foreach ($payload as $row) {
                $snapshotMode = $row['_snapshot_mode'] ?? 'offline';
                $srcUpdated = $row['_src_updated_at'] ?? null;
                unset($row['_src_updated_at'], $row['_snapshot_mode']);

                if ($snapshotMode === 'online') {
                    $row['job_id'] = $this->jobId;
                    $row['updated_at'] = $srcUpdated ?? $nowUtc;
                    $row['consulted_at'] = $nowUtc;
                    $toUpsert[] = $row;
                    continue;
                }

                $cpf = $row['cpf'];
                $rowExists = $existing->has($cpf);
                $existingRow = $rowExists ? $existing[$cpf] : null;

                $existingUpdated = null;
                if ($existingRow && isset($existingRow->updated_at) && $existingRow->updated_at !== null) {
                    $existingUpdated = $this->parseDateTimeFlexible((string) $existingRow->updated_at);
                }

                $incomingNotFound = (bool) ($row['not_found'] ?? false);

                if ($incomingNotFound) {
                    if ($rowExists) {
                        continue;
                    }
                    $row['job_id'] = $this->jobId;
                    $row['updated_at'] = $nowUtc;
                    $row['consulted_at'] = $nowUtc;
                    $toUpsert[] = $row;
                    continue;
                }

                if (!$rowExists) {
                    $row['job_id'] = $this->jobId;
                    $row['updated_at'] = $srcUpdated ?? $nowUtc;
                    $row['consulted_at'] = $nowUtc;
                    $toUpsert[] = $row;
                    continue;
                }

                if ($srcUpdated === null) {
                    continue;
                }
                if ($existingUpdated === null || strcmp($srcUpdated, $existingUpdated) > 0) {
                    $row['job_id'] = $this->jobId;
                    $row['updated_at'] = $srcUpdated;
                    $row['consulted_at'] = $nowUtc;
                    $toUpsert[] = $row;
                }
            }

            if (!empty($toUpsert)) {
                DB::table('clt_snapshots')->upsert(
                    $toUpsert,
                    ['cpf'],
                    [
                        'lead_id',
                        'nome',
                        'elegivel',
                        'data_nascimento',
                        'idade',
                        'sexo',
                        'data_admissao',
                        'meses_admissao',
                        'valor_renda',
                        'valor_base_margem',
                        'margem_disponivel',
                        'valor_max_prestacao',
                        'categoria_trabalhador_codigo',
                        'inicio_atividade_empregador',
                        // NOVO
                        'meses_empresa_empregador',
                        'qtd_emprestimos_ativos_suspensos',
                        'emprestimos_legados',
                        'not_found',
                        'job_id',
                        'updated_at',
                        'consulted_at',
                    ]
                );
            }
        } catch (\Throwable $e) {
            CltLog::warning("[CLT] Upsert snapshots falhou no job {$this->jobId}: " . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function cooperativeSleep(int $seconds, CltConsultJob $job): bool
    {
        $total = max(0, $seconds);
        if ($total <= 0)
            return $this->finishIfStopped($job);

        if ($this->backoffLog) {
            CltLog::info("[CLT] job={$this->jobId} backoff:start sleep={$total}s");
        }
        $remaining = $total;
        $start = microtime(true);

        while ($remaining > 0) {
            if ($this->finishIfStopped($job)) {
                $slept = (int) floor($total - $remaining);
                if ($this->backoffLog) {
                    CltLog::info("[CLT] job={$this->jobId} backoff:aborted slept={$slept}s");
                }
                return true;
            }
            sleep(1);
            $remaining -= 1;
        }

        $elapsed = (int) floor(microtime(true) - $start);
        if ($this->backoffLog) {
            CltLog::info("[CLT] job={$this->jobId} backoff:done slept={$elapsed}s");
        }
        return $this->finishIfStopped($job);
    }

    private function microSleepCoop(int $ms, CltConsultJob $job): bool
    {
        $remain = max(0, $ms) * 1000;
        if ($remain <= 0)
            return $this->finishIfStopped($job);

        $step = 50000; // 50ms
        while ($remain > 0) {
            if ($this->finishIfStopped($job))
                return true;
            $slice = $remain >= $step ? $step : $remain;
            usleep($slice);
            $remain -= $slice;
        }
        return $this->finishIfStopped($job);
    }

    private function closeSpoolWriter(): void
    {
        if (!is_resource($this->spoolFp)) {
            return;
        }

        @fflush($this->spoolFp);
        @fclose($this->spoolFp);
        $this->spoolFp = null;
    }

    private function runCreditPhaseTwo(\App\Modules\CLT\Services\FactaApiService $api, CltConsultJob $job): bool
    {
        if ($this->finishIfStopped($job)) {
            return false;
        }

        if (!$this->consolidateExistingPhaseTwoDeltaIfPresent($job)) {
            return false;
        }

        $phase2State = $this->countPhaseTwoRowsState($job);
        if (!is_array($phase2State)) {
            return false;
        }

        $phase2Total = (int) $phase2State['total'];
        $phase2ApprovedCount = (int) $phase2State['approved'];
        $phase2NotApprovedCount = (int) $phase2State['not_approved'];
        $phase2PendingCount = (int) $phase2State['pending'];

        DB::table('clt_consult_jobs')->where('id', $job->id)->update([
            'phase' => 'fase_2',
            'phase2_total' => $phase2Total,
            'phase2_attempt' => 0,
            'phase2_aprovado_count' => $phase2ApprovedCount,
            'phase2_nao_aprovado_count' => $phase2NotApprovedCount,
        ]);
        $this->cachedStatus = 'em_progresso';
        $this->lastStatusCheckAt = microtime(true);
        $this->lastPhase2ProgressFlushAt = microtime(true);
        $this->lastPhase2DeltaFlushAt = microtime(true);
        $this->phase2DeltaBuffer = [];
        $this->resetPhaseTwoDeltaFile();
        $this->resetPhaseTwoPendingFiles();
        $this->phase2LastAttemptProcessed = 0;
        $this->phase2CpfValidationAuditReqByLine = [];

        CltLog::warning('[CLT] Fase 2 iniciada (validação de política de crédito)', [
            'job_id' => $this->jobId,
            'phase2_total' => $phase2Total,
            'phase2_pending' => $phase2PendingCount,
            'max_attempts' => $this->phase2MaxAttempts,
            'retry_delay_seconds' => $this->phase2RetryDelaySeconds,
        ]);

        if ($phase2PendingCount === 0) {
            $this->flushPhaseTwoProgress($job, 0, $phase2Total, $phase2ApprovedCount, $phase2NotApprovedCount, true);
            $this->removePhaseTwoDeltaFile();
            $this->removePhaseTwoPendingFiles();
            return true;
        }

        $usePendingSource = false;
        for ($attempt = 1; $attempt <= $this->phase2MaxAttempts; $attempt++) {
            if ($this->finishIfStopped($job)) {
                return false;
            }
            $this->phase2LastAttemptProcessed = $attempt;
            $this->preparePhase2PendingOutput();

            $result = $this->processCreditPhaseTwoAttempt(
                $api,
                $job,
                $attempt,
                $usePendingSource,
                $phase2Total,
                $phase2ApprovedCount,
                $phase2NotApprovedCount
            );
            if (($result['aborted'] ?? false) === true) {
                return false;
            }

            $phase2ApprovedCount += (int) ($result['resolved_approved'] ?? 0);
            $phase2NotApprovedCount += (int) ($result['resolved_not_approved'] ?? 0);
            if (($result['token_abort'] ?? false) === true) {
                $abortMessage = trim((string) ($result['token_abort_message'] ?? ''));
                if ($abortMessage === '') {
                    $abortMessage = 'Processamento abortado: Não foi possível concluir a validação da política de crédito.';
                }

                CltLog::warning('[CLT] Fase 2 abortada por falha de token; consolidando parcial e encerrando.', [
                    'job_id' => $this->jobId,
                    'attempt' => $attempt,
                    'phase2_total' => $phase2Total,
                    'abort_message' => $abortMessage,
                ]);

                $this->flushPhase2DeltaBuffer(true);
                if (!$this->applyPhase2DeltaToSpool($job)) {
                    return false;
                }

                $markedAsAborted = $this->markRemainingPhaseTwoRowsAsAbortedInSpool($job, $abortMessage);
                if ($markedAsAborted < 0) {
                    return false;
                }
                if ($markedAsAborted > 0) {
                    $phase2NotApprovedCount += $markedAsAborted;
                }

                $this->flushPhaseTwoProgress(
                    $job,
                    $attempt,
                    $phase2Total,
                    $phase2ApprovedCount,
                    $phase2NotApprovedCount,
                    true
                );
                $this->removePhaseTwoDeltaFile();
                $this->removePhaseTwoPendingFiles();
                return true;
            }

            if (!$this->promotePhase2PendingOutput()) {
                return false;
            }

            $pendingCount = max(0, (int) ($result['pending_count'] ?? 0));
            $this->flushPhaseTwoProgress(
                $job,
                $attempt,
                $phase2Total,
                $phase2ApprovedCount,
                $phase2NotApprovedCount,
                true
            );
            $this->flushPhase2DeltaBuffer(true);

            $this->updateTotalsThrottled($job, [], true);

            if ($pendingCount === 0) {
                if (!$this->applyPhase2DeltaToSpool($job)) {
                    return false;
                }
                $this->removePhaseTwoDeltaFile();
                $this->removePhaseTwoPendingFiles();
                return true;
            }

            if ($attempt >= $this->phase2MaxAttempts) {
                if (!$this->applyPhase2DeltaToSpool($job)) {
                    return false;
                }
                $this->removePhaseTwoDeltaFile();
                $this->removePhaseTwoPendingFiles();
                return true;
            }

            if ($this->cooperativeSleep($this->phase2RetryDelaySeconds, $job)) {
                return false;
            }
            $usePendingSource = true;
        }

        $this->removePhaseTwoDeltaFile();
        $this->removePhaseTwoPendingFiles();
        return true;
    }

    /**
     * @param int $baseApprovedCount quantidade já consolidada em rodadas anteriores
     * @param int $baseNotApprovedCount quantidade já consolidada em rodadas anteriores
     * @return array{aborted:bool,token_abort:bool,token_abort_message:?string,pending_count:int,processed_rows:int,skipped_rows:int,resolved_approved:int,resolved_not_approved:int}
     */
    private function processCreditPhaseTwoAttempt(
        \App\Modules\CLT\Services\FactaApiService $api,
        CltConsultJob $job,
        int $attempt,
        bool $usePendingSource,
        int $phase2Total,
        int $baseApprovedCount,
        int $baseNotApprovedCount
    ): array {
        $sourceReal = $usePendingSource ? $this->phase2PendingReal : $this->spoolReal;
        $pendingCount = 0;
        $processedRows = 0;
        $skippedRows = 0;
        $resolvedApprovedRows = 0;
        $resolvedNotApprovedRows = 0;

        $in = @fopen($sourceReal, 'rb');
        if (!is_resource($in)) {
            if ($usePendingSource) {
                return [
                    'aborted' => false,
                    'token_abort' => false,
                    'token_abort_message' => null,
                    'pending_count' => 0,
                    'processed_rows' => 0,
                    'skipped_rows' => 0,
                    'resolved_approved' => 0,
                    'resolved_not_approved' => 0,
                ];
            }
            throw new \RuntimeException("Falha ao abrir spool da fase 2 (job {$this->jobId}).");
        }

        try {
            if (!$usePendingSource) {
                // Descarta cabeçalho.
                fgetcsv($in, 0, ';');

                $lineNo = 0;
                while (($csvRow = fgetcsv($in, 0, ';')) !== false) {
                    $lineNo++;

                    if ($this->finishIfStopped($job)) {
                        return [
                            'aborted' => true,
                            'token_abort' => false,
                            'token_abort_message' => null,
                            'pending_count' => $pendingCount,
                            'processed_rows' => $processedRows,
                            'skipped_rows' => $skippedRows,
                            'resolved_approved' => $resolvedApprovedRows,
                            'resolved_not_approved' => $resolvedNotApprovedRows,
                        ];
                    }

                    $row = $this->csvToAssocRow($csvRow);
                    if (!$this->shouldProcessCreditPhaseRow($row)) {
                        $skippedRows++;
                        continue;
                    }

                    $processedRows++;
                    try {
                        $creditOutcome = $this->applyCreditPhaseToRow($api, $job, $row, $lineNo, $attempt);
                    } catch (FactaFatalAuthException $e) {
                        return [
                            'aborted' => false,
                            'token_abort' => true,
                            'token_abort_message' => $e->abortCsvMessage(),
                            'pending_count' => $pendingCount,
                            'processed_rows' => $processedRows,
                            'skipped_rows' => $skippedRows,
                            'resolved_approved' => $resolvedApprovedRows,
                            'resolved_not_approved' => $resolvedNotApprovedRows,
                        ];
                    }

                    $row = $creditOutcome['row'];
                    $this->accumulatePhase2CpfValidationAuditCounts($lineNo, $creditOutcome);
                    $this->queuePhase2DeltaRow($lineNo, $row, $attempt);

                    if (($creditOutcome['aborted'] ?? false) === true) {
                        return [
                            'aborted' => true,
                            'token_abort' => false,
                            'token_abort_message' => null,
                            'pending_count' => $pendingCount,
                            'processed_rows' => $processedRows,
                            'skipped_rows' => $skippedRows,
                            'resolved_approved' => $resolvedApprovedRows,
                            'resolved_not_approved' => $resolvedNotApprovedRows,
                        ];
                    }

                    if (!empty($creditOutcome['pending'])) {
                        $pendingCount++;
                        $this->queuePhase2PendingRow($lineNo, $row);
                    } else {
                        $this->logPhase2CpfValidationAudit($lineNo, $row, $attempt, $creditOutcome);
                        if ($this->isCreditApprovedFlag($row['politicaCreditoAprovado'] ?? null)) {
                            $resolvedApprovedRows++;
                        } else {
                            $resolvedNotApprovedRows++;
                        }
                    }

                    $approvedEstimate = max(0, $baseApprovedCount + $resolvedApprovedRows);
                    $notApprovedEstimate = max(0, $baseNotApprovedCount + $resolvedNotApprovedRows);
                    $elapsedSinceFlushMs = $this->lastPhase2ProgressFlushAt > 0
                        ? (int) ((microtime(true) - $this->lastPhase2ProgressFlushAt) * 1000)
                        : PHP_INT_MAX;
                    $shouldCheckpoint =
                        ($processedRows % $this->phase2ProgressFlushEveryRows === 0)
                        || $elapsedSinceFlushMs >= $this->phase2ProgressFlushIntervalMs;

                    if ($shouldCheckpoint) {
                        $this->flushPhaseTwoProgress(
                            $job,
                            $attempt,
                            $phase2Total,
                            $approvedEstimate,
                            $notApprovedEstimate,
                            false
                        );
                    }
                }
            } else {
                while (($line = fgets($in)) !== false) {
                    if ($this->finishIfStopped($job)) {
                        return [
                            'aborted' => true,
                            'token_abort' => false,
                            'token_abort_message' => null,
                            'pending_count' => $pendingCount,
                            'processed_rows' => $processedRows,
                            'skipped_rows' => $skippedRows,
                            'resolved_approved' => $resolvedApprovedRows,
                            'resolved_not_approved' => $resolvedNotApprovedRows,
                        ];
                    }

                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    $decoded = json_decode($line, true);
                    if (!is_array($decoded)) {
                        continue;
                    }

                    $lineNo = (int) ($decoded['l'] ?? 0);
                    if ($lineNo <= 0) {
                        continue;
                    }

                    $row = $this->phase2PendingPayloadToRow($decoded);
                    if (!is_array($row)) {
                        $skippedRows++;
                        continue;
                    }

                    $processedRows++;
                    try {
                        $creditOutcome = $this->applyCreditPhaseToRow($api, $job, $row, $lineNo, $attempt);
                    } catch (FactaFatalAuthException $e) {
                        return [
                            'aborted' => false,
                            'token_abort' => true,
                            'token_abort_message' => $e->abortCsvMessage(),
                            'pending_count' => $pendingCount,
                            'processed_rows' => $processedRows,
                            'skipped_rows' => $skippedRows,
                            'resolved_approved' => $resolvedApprovedRows,
                            'resolved_not_approved' => $resolvedNotApprovedRows,
                        ];
                    }

                    $row = $creditOutcome['row'];
                    $this->accumulatePhase2CpfValidationAuditCounts($lineNo, $creditOutcome);
                    $this->queuePhase2DeltaRow($lineNo, $row, $attempt);

                    if (($creditOutcome['aborted'] ?? false) === true) {
                        return [
                            'aborted' => true,
                            'token_abort' => false,
                            'token_abort_message' => null,
                            'pending_count' => $pendingCount,
                            'processed_rows' => $processedRows,
                            'skipped_rows' => $skippedRows,
                            'resolved_approved' => $resolvedApprovedRows,
                            'resolved_not_approved' => $resolvedNotApprovedRows,
                        ];
                    }

                    if (!empty($creditOutcome['pending'])) {
                        $pendingCount++;
                        $this->queuePhase2PendingRow($lineNo, $row);
                    } else {
                        $this->logPhase2CpfValidationAudit($lineNo, $row, $attempt, $creditOutcome);
                        if ($this->isCreditApprovedFlag($row['politicaCreditoAprovado'] ?? null)) {
                            $resolvedApprovedRows++;
                        } else {
                            $resolvedNotApprovedRows++;
                        }
                    }

                    $approvedEstimate = max(0, $baseApprovedCount + $resolvedApprovedRows);
                    $notApprovedEstimate = max(0, $baseNotApprovedCount + $resolvedNotApprovedRows);
                    $elapsedSinceFlushMs = $this->lastPhase2ProgressFlushAt > 0
                        ? (int) ((microtime(true) - $this->lastPhase2ProgressFlushAt) * 1000)
                        : PHP_INT_MAX;
                    $shouldCheckpoint =
                        ($processedRows % $this->phase2ProgressFlushEveryRows === 0)
                        || $elapsedSinceFlushMs >= $this->phase2ProgressFlushIntervalMs;

                    if ($shouldCheckpoint) {
                        $this->flushPhaseTwoProgress(
                            $job,
                            $attempt,
                            $phase2Total,
                            $approvedEstimate,
                            $notApprovedEstimate,
                            false
                        );
                    }
                }
            }

            return [
                'aborted' => false,
                'token_abort' => false,
                'token_abort_message' => null,
                'pending_count' => $pendingCount,
                'processed_rows' => $processedRows,
                'skipped_rows' => $skippedRows,
                'resolved_approved' => $resolvedApprovedRows,
                'resolved_not_approved' => $resolvedNotApprovedRows,
            ];
        } finally {
            @fclose($in);
            $this->flushPhase2PendingBuffer(true);
            $this->flushPhase2DeltaBuffer(true);
        }
    }

    /**
     * Consolida os patches incrementais da fase 2 no spool final.
     */
    private function applyPhase2DeltaToSpool(CltConsultJob $job, bool $allowStopCheck = true): bool
    {
        $this->flushPhase2DeltaBuffer(true);
        $hasGlobalDelta = $this->phase2DeltaReal !== '' && is_file($this->phase2DeltaReal);
        $hasAttemptDelta = false;
        foreach ($this->phase2AttemptDeltaFilesExpected() as $path) {
            if ($path !== '' && is_file($path)) {
                $hasAttemptDelta = true;
                break;
            }
        }

        if (!$hasGlobalDelta && !$hasAttemptDelta) {
            return true;
        }

        return $this->applyPhase2DeltaToSpoolByAttemptStreams($job, $allowStopCheck);
    }

    /**
     * Consolidação em streaming com arquivos de delta por tentativa.
     */
    private function applyPhase2DeltaToSpoolByAttemptStreams(CltConsultJob $job, bool $allowStopCheck = true): bool
    {
        $attemptFiles = $this->phase2AttemptDeltaFilesExpected();
        if (empty($attemptFiles)) {
            throw new \RuntimeException("Fase 2 sem arquivos de delta por tentativa para consolidar (job {$this->jobId}).");
        }

        foreach ($attemptFiles as $attempt => $path) {
            if ($path === '' || !is_file($path)) {
                throw new \RuntimeException("Fase 2 sem delta da tentativa {$attempt} para consolidar (job {$this->jobId}).");
            }
        }

        $sourceReal = $this->spoolReal;
        $tmpReal = $sourceReal . '.phase2.tmp';
        $cleanupTmp = true;
        $in = null;
        $out = null;
        /** @var array<int,resource> $attemptHandles */
        $attemptHandles = [];
        /** @var array<int,array{line:int,patch:array{ap:mixed,mg:mixed,vm:mixed,pm:mixed}}|null> $attemptCurrents */
        $attemptCurrents = [];

        try {
            $in = @fopen($sourceReal, 'rb');
            $out = @fopen($tmpReal, 'wb');
            if (!is_resource($in) || !is_resource($out)) {
                if (is_resource($in)) {
                    @fclose($in);
                }
                if (is_resource($out)) {
                    @fclose($out);
                }
                throw new \RuntimeException("Falha ao preparar streams para consolidar fase 2 (job {$this->jobId}).");
            }

            $hasAnyPatch = false;
            foreach ($attemptFiles as $attempt => $path) {
                $fh = @fopen($path, 'rb');
                if (!is_resource($fh)) {
                    throw new \RuntimeException("Falha ao abrir delta da tentativa {$attempt} (job {$this->jobId}).");
                }

                $attemptHandles[$attempt] = $fh;
                $attemptCurrents[$attempt] = $this->readNextPhase2DeltaAttemptPatch($fh);
                if (is_array($attemptCurrents[$attempt])) {
                    $hasAnyPatch = true;
                }
            }

            if (!$hasAnyPatch) {
                throw new \RuntimeException("Fase 2 sem patches válidos nos deltas por tentativa (job {$this->jobId}).");
            }

            fgetcsv($in, 0, ';');
            $this->writeCsvRowOrFail($out, CltSchema::TITLES, 'spool consolidado da fase 2');

            $lineNo = 0;
            while (($csvRow = fgetcsv($in, 0, ';')) !== false) {
                $lineNo++;
                if ($allowStopCheck && $this->finishIfStopped($job)) {
                    return false;
                }

                $row = $this->csvToAssocRow($csvRow);
                $bestAttempt = 0;
                $bestPatch = null;

                foreach ($attemptCurrents as $attempt => $current) {
                    $curr = $current;
                    $fh = $attemptHandles[$attempt] ?? null;
                    if (!is_resource($fh)) {
                        throw new \RuntimeException("Handle inválido no delta da tentativa {$attempt} (job {$this->jobId}).");
                    }

                    while (is_array($curr) && (int) ($curr['line'] ?? 0) < $lineNo) {
                        $curr = $this->readNextPhase2DeltaAttemptPatch($fh);
                    }

                    if (is_array($curr) && (int) ($curr['line'] ?? 0) === $lineNo) {
                        $latestPatch = is_array($curr['patch'] ?? null) ? $curr['patch'] : null;
                        while (true) {
                            $next = $this->readNextPhase2DeltaAttemptPatch($fh);
                            if (!is_array($next) || (int) ($next['line'] ?? 0) !== $lineNo) {
                                $curr = $next;
                                break;
                            }
                            $latestPatch = is_array($next['patch'] ?? null) ? $next['patch'] : $latestPatch;
                        }

                        if (is_array($latestPatch) && $attempt >= $bestAttempt) {
                            $bestAttempt = $attempt;
                            $bestPatch = $latestPatch;
                        }
                    }

                    $attemptCurrents[$attempt] = $curr;
                }

                if (is_array($bestPatch)) {
                    $row = $this->applyPhase2PatchToAssocRow($row, $bestPatch);
                }

                $this->writeCsvRowOrFail($out, $this->assocToCsvRow($row), 'spool consolidado da fase 2');
            }

            if (!fflush($out)) {
                throw new \RuntimeException("Falha ao sincronizar spool consolidado da fase 2 em disco.");
            }
            if (!@rename($tmpReal, $sourceReal)) {
                throw new \RuntimeException("Falha ao promover spool consolidado da fase 2 (job {$this->jobId}).");
            }

            $cleanupTmp = false;
            return true;
        } finally {
            foreach ($attemptHandles as $fh) {
                if (is_resource($fh)) {
                    @fclose($fh);
                }
            }
            if (is_resource($in)) {
                @fclose($in);
            }
            if (is_resource($out)) {
                @fclose($out);
            }
            if ($cleanupTmp && is_file($tmpReal)) {
                @unlink($tmpReal);
            }
        }
    }

    /**
     * @param resource $fh
     * @return array{line:int,patch:array{ap:mixed,mg:mixed,vm:mixed,pm:mixed,ta:mixed}}|null
     */
    private function readNextPhase2DeltaAttemptPatch($fh): ?array
    {
        if (!is_resource($fh)) {
            return null;
        }

        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            $lineNo = (int) ($decoded['l'] ?? 0);
            if ($lineNo <= 0) {
                continue;
            }

            return [
                'line' => $lineNo,
                'patch' => [
                    'ap' => array_key_exists('ap', $decoded) ? $decoded['ap'] : null,
                    'mg' => array_key_exists('mg', $decoded) ? $decoded['mg'] : null,
                    'vm' => array_key_exists('vm', $decoded) ? $decoded['vm'] : null,
                    'pm' => array_key_exists('pm', $decoded) ? $decoded['pm'] : null,
                    'ta' => array_key_exists('ta', $decoded) ? $decoded['ta'] : null,
                ],
            ];
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function phase2AttemptDeltaFilesExpected(): array
    {
        if ($this->phase2LastAttemptProcessed <= 0) {
            return [];
        }

        $files = [];
        for ($attempt = 1; $attempt <= $this->phase2LastAttemptProcessed; $attempt++) {
            $path = $this->phase2DeltaAttemptFileReal($attempt);
            if ($path !== '') {
                $files[$attempt] = $path;
            }
        }

        return $files;
    }

    /**
     * @param array<string,mixed> $row
     * @param array{ap:mixed,mg:mixed,vm:mixed,pm:mixed,ta?:mixed} $patch
     * @return array<string,mixed>
     */
    private function applyPhase2PatchToAssocRow(array $row, array $patch): array
    {
        $row['politicaCreditoAprovado'] = $patch['ap'] ?? null;
        $row['politicaCreditoMensagem'] = $patch['mg'] ?? null;
        $row['politicaCreditoValorMaximoDisponivel'] = $patch['vm'] ?? null;
        $row['politicaCreditoPrazoMaximoDisponivel'] = $patch['pm'] ?? null;
        $row['politicaCreditoTabelaAprovada'] = $patch['ta'] ?? null;
        return $row;
    }

    /**
     * Marca como NÃO APROVADO os elegíveis da fase 2 que ainda estavam pendentes
     * e grava mensagem de aborto em "Política de Crédito Mensagem".
     *
     * @return int quantidade de linhas marcadas; -1 se job foi interrompido/cancelado.
     */
    private function markRemainingPhaseTwoRowsAsAbortedInSpool(CltConsultJob $job, string $abortMessage): int
    {
        $sourceReal = $this->spoolReal;
        $tmpReal = $sourceReal . '.phase2.abort.tmp';
        $cleanupTmp = true;
        $markedCount = 0;

        try {
            $in = @fopen($sourceReal, 'rb');
            $out = @fopen($tmpReal, 'wb');
            if (!is_resource($in) || !is_resource($out)) {
                if (is_resource($in)) {
                    @fclose($in);
                }
                if (is_resource($out)) {
                    @fclose($out);
                }
                throw new \RuntimeException("Falha ao preparar streams para marcar pendentes da fase 2 (job {$this->jobId}).");
            }

            try {
                fgetcsv($in, 0, ';');
                $this->writeCsvRowOrFail($out, CltSchema::TITLES, 'spool abortado da fase 2');

                while (($csvRow = fgetcsv($in, 0, ';')) !== false) {
                    if ($this->finishIfStopped($job)) {
                        return -1;
                    }

                    $row = $this->csvToAssocRow($csvRow);
                    if (
                        $this->shouldProcessCreditPhaseRow($row)
                        && $this->isPhaseTwoRowPending($row)
                    ) {
                        $row['politicaCreditoAprovado'] = 'NÃO';
                        $row['politicaCreditoMensagem'] = $abortMessage;
                        $row['politicaCreditoValorMaximoDisponivel'] = null;
                        $row['politicaCreditoPrazoMaximoDisponivel'] = null;
                        $row['politicaCreditoTabelaAprovada'] = null;
                        $markedCount++;
                    }

                    $this->writeCsvRowOrFail($out, $this->assocToCsvRow($row), 'spool abortado da fase 2');
                }
            } finally {
                if (!@fflush($out)) {
                    throw new \RuntimeException("Falha ao sincronizar spool abortado da fase 2 em disco.");
                }
                @fclose($in);
                @fclose($out);
            }

            if (!@rename($tmpReal, $sourceReal)) {
                throw new \RuntimeException("Falha ao promover spool marcado com abort da fase 2 (job {$this->jobId}).");
            }

            $cleanupTmp = false;
            return $markedCount;
        } finally {
            if ($cleanupTmp && is_file($tmpReal)) {
                @unlink($tmpReal);
            }
        }
    }

    private function isPhaseTwoRowPending(array $row): bool
    {
        $approved = $this->simNaoToBool($row['politicaCreditoAprovado'] ?? null);
        return $approved === null;
    }

    private function consolidateExistingPhaseTwoDeltaIfPresent(CltConsultJob $job): bool
    {
        $highestAttempt = 0;
        for ($attempt = 1; $attempt <= $this->phase2MaxAttempts; $attempt++) {
            $path = $this->phase2DeltaAttemptFileReal($attempt);
            if ($path !== '' && is_file($path)) {
                $highestAttempt = $attempt;
            }
        }

        if ($highestAttempt <= 0) {
            return true;
        }

        $this->phase2LastAttemptProcessed = $highestAttempt;
        try {
            if (!$this->applyPhase2DeltaToSpool($job, false)) {
                return false;
            }
            $this->removePhaseTwoDeltaFile();
            $this->removePhaseTwoPendingFiles();
            return true;
        } catch (Throwable $e) {
            CltLog::error("[CLT] Falha ao consolidar delta existente da fase 2 (job {$this->jobId}): " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * @return array{total:int,pending:int,approved:int,not_approved:int}|null
     */
    private function countPhaseTwoRowsState(CltConsultJob $job, bool $allowStopCheck = true): ?array
    {
        $in = @fopen($this->spoolReal, 'rb');
        if (!is_resource($in)) {
            throw new \RuntimeException("Falha ao abrir spool para contar fase 2 (job {$this->jobId}).");
        }

        $total = 0;
        $pending = 0;
        $approved = 0;
        $notApproved = 0;
        try {
            fgetcsv($in, 0, ';');
            while (($csvRow = fgetcsv($in, 0, ';')) !== false) {
                if ($allowStopCheck && $this->finishIfStopped($job)) {
                    return null;
                }

                $row = $this->csvToAssocRow($csvRow);
                if (!$this->isCreditPhaseEligibleRow($row)) {
                    continue;
                }

                $total++;
                $approvedFlag = $this->simNaoToBool($row['politicaCreditoAprovado'] ?? null);
                if ($approvedFlag === true) {
                    $approved++;
                } elseif ($approvedFlag === false) {
                    $notApproved++;
                } else {
                    $pending++;
                }
            }
        } finally {
            @fclose($in);
        }

        return [
            'total' => $total,
            'pending' => $pending,
            'approved' => $approved,
            'not_approved' => $notApproved,
        ];
    }

    private function flushPhaseTwoProgress(
        CltConsultJob $job,
        int $attempt,
        int $total,
        int $approvedCount,
        int $notApprovedCount,
        bool $force
    ): void {
        $totalSafe = max(0, $total);
        $attemptSafe = max(0, $attempt);
        $approvedSafe = max(0, $approvedCount);
        $notApprovedSafe = max(0, $notApprovedCount);

        $now = microtime(true);
        if (!$force && $this->lastPhase2ProgressFlushAt > 0) {
            $elapsedMs = (int) (($now - $this->lastPhase2ProgressFlushAt) * 1000);
            if ($elapsedMs < $this->phase2ProgressFlushIntervalMs) {
                return;
            }
        }

        DB::table('clt_consult_jobs')->where('id', $job->id)->update([
            'phase2_total' => $totalSafe,
            'phase2_attempt' => $attemptSafe,
            'phase2_aprovado_count' => $approvedSafe,
            'phase2_nao_aprovado_count' => $notApprovedSafe,
            'updated_at' => Carbon::now(),
        ]);

        $this->lastPhase2ProgressFlushAt = $now;
    }

    /**
     * Persiste mudanças de fase 2 em arquivo delta (append-only) para a prévia refletir progresso
     * sem aguardar o fim da rodada completa de rewrite do spool.
     *
     * @param array<string,mixed> $row
     */
    private function queuePhase2DeltaRow(int $lineNo, array $row, int $attempt): void
    {
        if ($lineNo <= 0 || $attempt <= 0) {
            return;
        }

        $payload = [
            'l' => $lineNo,
            'a' => max(0, $attempt),
            'ap' => $row['politicaCreditoAprovado'] ?? null,
            'mg' => $row['politicaCreditoMensagem'] ?? null,
            'vm' => $row['politicaCreditoValorMaximoDisponivel'] ?? null,
            'pm' => $row['politicaCreditoPrazoMaximoDisponivel'] ?? null,
            'ta' => $row['politicaCreditoTabelaAprovada'] ?? null,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return;
        }

        $this->preparePhase2DeltaAttemptOutput($attempt);
        $this->phase2DeltaBuffer[] = $json;
        $this->phase2DeltaAttemptBuffer[] = $json;
        $this->flushPhase2DeltaBuffer(false);
    }

    private function flushPhase2DeltaBuffer(bool $force): void
    {
        $hasGlobalBuffer = !empty($this->phase2DeltaBuffer) && $this->phase2DeltaReal !== '';
        $hasAttemptBuffer = !empty($this->phase2DeltaAttemptBuffer) && $this->phase2DeltaAttemptReal !== '';
        if (!$hasGlobalBuffer && !$hasAttemptBuffer) {
            return;
        }

        $nowMs = (int) floor(microtime(true) * 1000);
        $lastFlushMs = $this->lastPhase2DeltaFlushAt > 0
            ? (int) floor($this->lastPhase2DeltaFlushAt * 1000)
            : 0;
        $elapsedMs = $lastFlushMs > 0 ? ($nowMs - $lastFlushMs) : PHP_INT_MAX;

        $bufferedRows = max(count($this->phase2DeltaBuffer), count($this->phase2DeltaAttemptBuffer));
        if (
            !$force
            && $bufferedRows < $this->phase2DeltaFlushEveryRows
            && $elapsedMs < $this->phase2DeltaFlushIntervalMs
        ) {
            return;
        }

        $globalWritten = true;
        if ($hasGlobalBuffer) {
            $data = implode("\n", $this->phase2DeltaBuffer) . "\n";
            $written = @file_put_contents($this->phase2DeltaReal, $data, FILE_APPEND | LOCK_EX);
            if ($written === false) {
                $globalWritten = false;
                CltLog::warning("[CLT] Job {$this->jobId} falha ao persistir delta incremental da fase 2.");
            } else {
                $this->phase2DeltaBuffer = [];
            }
        }

        if ($hasAttemptBuffer) {
            $attemptData = implode("\n", $this->phase2DeltaAttemptBuffer) . "\n";
            $attemptWritten = @file_put_contents($this->phase2DeltaAttemptReal, $attemptData, FILE_APPEND | LOCK_EX);
            if ($attemptWritten === false) {
                CltLog::warning("[CLT] Job {$this->jobId} falha ao persistir delta da fase 2 por tentativa.", [
                    'attempt' => $this->phase2DeltaCurrentAttempt,
                ]);
            } else {
                $this->phase2DeltaAttemptBuffer = [];
            }
        }

        if (!$globalWritten && !$force) {
            return;
        }
        $this->lastPhase2DeltaFlushAt = microtime(true);
    }

    private function resetPhaseTwoDeltaFile(): void
    {
        $this->phase2DeltaBuffer = [];
        $this->phase2DeltaAttemptBuffer = [];
        $this->phase2DeltaCurrentAttempt = 0;
        $this->phase2DeltaAttemptReal = '';
        foreach ($this->phase2DeltaFilesForCleanup() as $file) {
            if ($file === '') {
                continue;
            }
            try {
                if (is_file($file)) {
                    @unlink($file);
                }
            } catch (Throwable) {
            }
        }
    }

    private function removePhaseTwoDeltaFile(): void
    {
        $this->resetPhaseTwoDeltaFile();
    }

    private function preparePhase2DeltaAttemptOutput(int $attempt): void
    {
        if ($attempt <= 0) {
            return;
        }

        if ($this->phase2DeltaCurrentAttempt === $attempt && $this->phase2DeltaAttemptReal !== '') {
            return;
        }

        $this->flushPhase2DeltaBuffer(true);
        $this->phase2DeltaCurrentAttempt = $attempt;
        $this->phase2DeltaAttemptReal = $this->phase2DeltaAttemptFileReal($attempt);
        $this->phase2DeltaAttemptBuffer = [];
    }

    private function phase2DeltaAttemptFileReal(int $attempt): string
    {
        if ($attempt <= 0 || $this->spoolReal === '') {
            return '';
        }

        return $this->spoolReal . ".phase2.delta.a{$attempt}.ndjson";
    }

    /**
     * @return array<int,string>
     */
    private function phase2DeltaFilesForCleanup(): array
    {
        $files = [];
        if ($this->phase2DeltaReal !== '') {
            $files[] = $this->phase2DeltaReal;
        }

        for ($attempt = 1; $attempt <= $this->phase2MaxAttempts; $attempt++) {
            $attemptFile = $this->phase2DeltaAttemptFileReal($attempt);
            if ($attemptFile !== '') {
                $files[] = $attemptFile;
            }
        }

        return $files;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function queuePhase2PendingRow(int $lineNo, array $row): void
    {
        if ($lineNo <= 0 || $this->phase2PendingNextReal === '') {
            return;
        }

        $cpf = preg_replace('/\D+/', '', (string) ($row['cpf'] ?? ''));
        if (!is_string($cpf) || strlen($cpf) !== 11) {
            return;
        }

        $payload = [
            'l' => $lineNo,
            'cpf' => $cpf,
            'mt' => $row['matricula'] ?? null,
            'dn' => $row['dataNascimento'] ?? null,
            'da' => $row['dataAdmissao'] ?? null,
            'vp' => $row['valorMaximoPrestacao'] ?? null,
            'vr' => $row['valorTotalVencimentos'] ?? null,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return;
        }

        $this->phase2PendingBuffer[] = $json;
        $this->flushPhase2PendingBuffer(false);
    }

    private function flushPhase2PendingBuffer(bool $force): void
    {
        if (empty($this->phase2PendingBuffer) || $this->phase2PendingNextReal === '') {
            return;
        }

        if (!$force && count($this->phase2PendingBuffer) < $this->phase2PendingFlushEveryRows) {
            return;
        }

        $data = implode("\n", $this->phase2PendingBuffer) . "\n";
        $written = @file_put_contents($this->phase2PendingNextReal, $data, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            CltLog::warning("[CLT] Job {$this->jobId} falha ao persistir pendências da fase 2.");
            return;
        }

        $this->phase2PendingBuffer = [];
    }

    private function preparePhase2PendingOutput(): void
    {
        $this->phase2PendingBuffer = [];
        if ($this->phase2PendingNextReal === '') {
            return;
        }

        try {
            if (is_file($this->phase2PendingNextReal)) {
                @unlink($this->phase2PendingNextReal);
            }
        } catch (Throwable) {
        }
    }

    private function promotePhase2PendingOutput(): bool
    {
        $this->flushPhase2PendingBuffer(true);

        if ($this->phase2PendingNextReal === '' || $this->phase2PendingReal === '') {
            return true;
        }

        if (!is_file($this->phase2PendingNextReal)) {
            try {
                if (is_file($this->phase2PendingReal)) {
                    @unlink($this->phase2PendingReal);
                }
            } catch (Throwable) {
            }
            return true;
        }

        if (@rename($this->phase2PendingNextReal, $this->phase2PendingReal)) {
            return true;
        }

        CltLog::warning("[CLT] Job {$this->jobId} falha ao promover pendências da fase 2.");
        return false;
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>|null
     */
    private function phase2PendingPayloadToRow(array $decoded): ?array
    {
        $cpf = preg_replace('/\D+/', '', (string) ($decoded['cpf'] ?? ''));
        if (!is_string($cpf) || strlen($cpf) !== 11) {
            return null;
        }

        $row = $this->baseRow($cpf);
        $row['matricula'] = $decoded['mt'] ?? null;
        $row['dataNascimento'] = $decoded['dn'] ?? null;
        $row['dataAdmissao'] = $decoded['da'] ?? null;
        $row['valorMaximoPrestacao'] = $decoded['vp'] ?? null;
        $row['valorTotalVencimentos'] = $decoded['vr'] ?? null;

        return $row;
    }

    private function resetPhaseTwoPendingFiles(): void
    {
        $this->phase2PendingBuffer = [];
        foreach ([$this->phase2PendingReal, $this->phase2PendingNextReal] as $file) {
            if ($file === '') {
                continue;
            }
            try {
                if (is_file($file)) {
                    @unlink($file);
                }
            } catch (Throwable) {
            }
        }
    }

    private function removePhaseTwoPendingFiles(): void
    {
        $this->resetPhaseTwoPendingFiles();
    }

    private function shouldProcessCreditPhaseRow(array $row): bool
    {
        return $this->isCreditPhaseEligibleRow($row)
            && $this->isPhaseTwoRowPending($row);
    }

    private function isCreditPhaseEligibleRow(array $row): bool
    {
        $cpf = preg_replace('/\D+/', '', (string) ($row['cpf'] ?? ''));
        if (strlen($cpf) !== 11) {
            return false;
        }

        return $this->simNaoToBool($row['elegivel'] ?? null) === true;
    }

    private function isCreditApprovedFlag($val): bool
    {
        return $this->simNaoToBool($val) === true;
    }

    /**
     * @return array{row:array<string,mixed>,pending:bool,aborted:bool,phase2_request_count:int,phase2_operacoes_request_count:int,phase2_politica_request_count:int,phase2_approved_table:?array,phase2_approved_table_name:?string}
     */
    private function applyCreditPhaseToRow(
        \App\Modules\CLT\Services\FactaApiService $api,
        CltConsultJob $job,
        array $row,
        int $lineNo,
        int $attempt
    ): array {
        $cpf = preg_replace('/\D+/', '', (string) ($row['cpf'] ?? ''));
        $matricula = trim((string) ($row['matricula'] ?? ''));
        $dataNascimento = trim((string) ($row['dataNascimento'] ?? ''));
        $dataAdmissao = trim((string) ($row['dataAdmissao'] ?? ''));
        $valorParcela = $row['valorMaximoPrestacao'] ?? null;
        $valorRenda = $row['valorTotalVencimentos'] ?? null;

        if (
            $matricula === ''
            || $dataNascimento === ''
            || $dataAdmissao === ''
            || $valorParcela === null
            || trim((string) $valorParcela) === ''
            || $valorRenda === null
            || trim((string) $valorRenda) === ''
        ) {
            $row['politicaCreditoAprovado'] = 'NÃO';
            $row['politicaCreditoMensagem'] = 'Dados insuficientes para continuação da análise de crédito.';
            $row['politicaCreditoValorMaximoDisponivel'] = null;
            $row['politicaCreditoPrazoMaximoDisponivel'] = null;
            $row['politicaCreditoTabelaAprovada'] = null;

            return [
                'row' => $row,
                'pending' => false,
                'aborted' => false,
                'phase2_request_count' => 0,
                'phase2_operacoes_request_count' => 0,
                'phase2_politica_request_count' => 0,
                'phase2_approved_table' => null,
                'phase2_approved_table_name' => null,
            ];
        }

        $ctx = [
            'cpf' => $cpf,
            'matricula' => $matricula,
            'dataNascimento' => $dataNascimento,
            'dataAdmissao' => $dataAdmissao,
            'valorParcela' => $valorParcela,
            'valorRenda' => $valorRenda,
        ];

        $credit = $api->continuarCreditoTrabalhadorElegivel($ctx);

        $row = $this->applyCreditResultToRow($row, $credit);
        $pending = !empty($credit['retriable']);

        return [
            'row' => $row,
            'pending' => $pending,
            'aborted' => false,
            'phase2_request_count' => max(0, (int) ($credit['phase2_request_count'] ?? 0)),
            'phase2_operacoes_request_count' => max(0, (int) ($credit['phase2_operacoes_request_count'] ?? 0)),
            'phase2_politica_request_count' => max(0, (int) ($credit['phase2_politica_request_count'] ?? 0)),
            'phase2_approved_table' => is_array($credit['phase2_approved_table'] ?? null) ? $credit['phase2_approved_table'] : null,
            'phase2_approved_table_name' => is_string($credit['phase2_approved_table_name'] ?? null) ? $credit['phase2_approved_table_name'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $creditOutcome
     */
    private function accumulatePhase2CpfValidationAuditCounts(int $lineNo, array $creditOutcome): void
    {
        if (!$this->phase2CpfValidationAuditLogEnabled || $lineNo <= 0) {
            return;
        }

        $total = max(0, (int) ($creditOutcome['phase2_request_count'] ?? 0));
        $operacoes = max(0, (int) ($creditOutcome['phase2_operacoes_request_count'] ?? 0));
        $politica = max(0, (int) ($creditOutcome['phase2_politica_request_count'] ?? 0));

        if ($total === 0 && $operacoes === 0 && $politica === 0) {
            return;
        }

        if (!isset($this->phase2CpfValidationAuditReqByLine[$lineNo])) {
            $this->phase2CpfValidationAuditReqByLine[$lineNo] = [
                'total' => 0,
                'operacoes' => 0,
                'politica' => 0,
            ];
        }

        $this->phase2CpfValidationAuditReqByLine[$lineNo]['total'] += $total;
        $this->phase2CpfValidationAuditReqByLine[$lineNo]['operacoes'] += $operacoes;
        $this->phase2CpfValidationAuditReqByLine[$lineNo]['politica'] += $politica;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function logPhase2CpfValidationAudit(int $lineNo, array $row, int $attempt, array $creditOutcome): void
    {
        if (!$this->phase2CpfValidationAuditLogEnabled) {
            return;
        }

        $reqCounts = $this->phase2CpfValidationAuditReqByLine[$lineNo] ?? [
            'total' => 0,
            'operacoes' => 0,
            'politica' => 0,
        ];
        unset($this->phase2CpfValidationAuditReqByLine[$lineNo]);

        if ($reqCounts['total'] <= 0 && ($reqCounts['operacoes'] > 0 || $reqCounts['politica'] > 0)) {
            $reqCounts['total'] = $reqCounts['operacoes'] + $reqCounts['politica'];
        }

        $cpf = preg_replace('/\D+/', '', (string) ($row['cpf'] ?? ''));
        $resultado = $this->isCreditApprovedFlag($row['politicaCreditoAprovado'] ?? null)
            ? 'aprovado'
            : 'nao_aprovado';

        $context = [
            'job_id' => $this->jobId,
            'line' => $lineNo,
            'attempt_final' => max(1, $attempt),
            'cpf' => $cpf !== '' ? $cpf : null,
            'resultado' => $resultado,
            'req_total' => max(0, (int) ($reqCounts['total'] ?? 0)),
            'req_operacoes' => max(0, (int) ($reqCounts['operacoes'] ?? 0)),
            'req_politica' => max(0, (int) ($reqCounts['politica'] ?? 0)),
        ];

        if ($resultado === 'aprovado') {
            $approvedTable = $creditOutcome['phase2_approved_table'] ?? null;
            if (is_array($approvedTable) && !empty($approvedTable)) {
                $context['tabela_aprovada'] = $approvedTable;
            }
        }

        CltLog::warning('[CLT] Fase 2 CPF validado', $context);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $credit
     * @return array<string,mixed>
     */
    private function applyCreditResultToRow(array $row, array $credit): array
    {
        if (empty($credit['attempted'])) {
            return $row;
        }

        // Quando o resultado é retriable, mantemos a linha como pendente lógica da fase 2
        // (aprovado=null) para permitir marcação correta em aborto/finalização.
        if (!empty($credit['retriable'])) {
            $row['politicaCreditoAprovado'] = null;
        } else {
            $row['politicaCreditoAprovado'] = !empty($credit['aprovado']) ? 'SIM' : 'NÃO';
        }
        $row['politicaCreditoMensagem'] = $credit['mensagem'] ?? null;
        $row['politicaCreditoValorMaximoDisponivel'] = $credit['valor_maximo_disponivel'] ?? null;
        $row['politicaCreditoPrazoMaximoDisponivel'] = $credit['prazo_maximo_disponivel'] ?? null;
        $row['politicaCreditoTabelaAprovada'] = $credit['phase2_approved_table_name'] ?? null;

        return $row;
    }

    /**
     * @param array<int,mixed> $csvRow
     * @return array<string,mixed>
     */
    private function csvToAssocRow(array $csvRow): array
    {
        $row = $this->baseRowTemplate;
        foreach (CltSchema::COLS as $idx => $key) {
            $row[$key] = $csvRow[$idx] ?? null;
        }
        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<int,mixed>
     */
    private function assocToCsvRow(array $row): array
    {
        $row = CltSchema::normalizeAssocRowForCsv($row);
        $ordered = [];
        foreach (CltSchema::COLS as $key) {
            $ordered[] = $row[$key] ?? null;
        }
        return $ordered;
    }

    private function finishIfStopped(CltConsultJob $job): bool
    {
        $status = $this->currentStatusCached($job->id);
        if ($status === null) {
            $this->cleanupSpool($job);
            CltLog::info("[CLT] Job {$this->jobId} removido durante execução. Encerrando.");
            return true;
        }

        if ($status === 'cancelado') {
            $this->flushPhase2DeltaBuffer(true);
            $this->flushPhase2PendingBuffer(true);
            $this->closeSpoolWriter();
            $this->finalizeCancelledJob($job);
            CltLog::info("[CLT] Job {$this->jobId} cancelado.");
            return true;
        }

        if ($status === 'pausado') {
            $this->pauseCurrentJob($job);
            CltLog::info("[CLT] Job {$this->jobId} pausado.");
            return true;
        }

        return false;
    }

    private function currentStatus(int $id): ?string
    {
        return DB::table('clt_consult_jobs')->where('id', $id)->value('status');
    }

    private function currentStatusCached(int $id, bool $force = false): ?string
    {
        $now = microtime(true);
        $intervalSecs = max(0, $this->statusCheckIntervalMs) / 1000;
        $expired = ($now - $this->lastStatusCheckAt) >= $intervalSecs;

        if ($force || $this->lastStatusCheckAt <= 0.0 || $expired) {
            $this->cachedStatus = $this->currentStatus($id);
            $this->lastStatusCheckAt = $now;
        }

        return $this->cachedStatus;
    }

    private function isCancelled(CltConsultJob $job): bool
    {
        $s = $this->currentStatusCached($job->id, true);
        return ($s === 'cancelado') || ($s === null);
    }

    private function isPaused(CltConsultJob $job): bool
    {
        return $this->currentStatusCached($job->id, true) === 'pausado';
    }

    private function supportsCreditPhaseTwo(): bool
    {
        return CltVariant::supportsCreditPhaseTwo($this->variant);
    }

    private function normalizeConsultaMensagemForCsv($mensagem): ?string
    {
        if ($mensagem === null) {
            return null;
        }

        $texto = trim((string) $mensagem);
        if ($texto === '') {
            return null;
        }

        return match ($texto) {
            'Dados retornados com sucesso!',
            'CPF autorizado com sucesso' => 'Sucesso',
            default => $texto,
        };
    }

    private function finalConsultaSourceForPendingFailures(): string
    {
        return $this->variant === 'offline' ? 'offline' : 'online';
    }

    private function cleanupSpool(CltConsultJob $job): void
    {
        try {
            $disk = Storage::disk($this->disk);
            CltSpool::deleteArtifacts($disk, $job->spool_path ?? null, $job->spool_cpfs_path ?? null, $this->phase2MaxAttempts);
        } finally {
            if (DB::table('clt_consult_jobs')->where('id', $job->id)->exists()) {
                try {
                    $job->updateQuietly(['spool_path' => null, 'spool_cpfs_path' => null, 'spool_bytes' => 0, 'phase' => null]);
                } catch (Throwable) {
                }
            }
        }
    }

    private function pauseCurrentJob(CltConsultJob $job): void
    {
        $this->flushPhase2DeltaBuffer(true);
        $this->flushPhase2PendingBuffer(true);
        $this->closeSpoolWriter();

        if ($this->stage === self::STAGE_PHASE2 && $this->supportsCreditPhaseTwo()) {
            try {
                if ($this->applyPhase2DeltaToSpool($job, false)) {
                    $this->removePhaseTwoDeltaFile();
                    $this->removePhaseTwoPendingFiles();
                }

                $state = $this->countPhaseTwoRowsState($job, false);
                if (is_array($state)) {
                    $this->flushPhaseTwoProgress(
                        $job,
                        max(0, $this->phase2LastAttemptProcessed),
                        (int) $state['total'],
                        (int) $state['approved'],
                        (int) $state['not_approved'],
                        true
                    );
                }
            } catch (Throwable $e) {
                CltLog::error("[CLT] Falha ao consolidar fase 2 ao pausar job {$this->jobId}: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        } else {
            $this->updateTotalsThrottled($job, [], true);
        }

        try {
            $spoolBytes = is_string($job->spool_path ?? null) && $job->spool_path !== ''
                ? $this->fileSizeSafe($this->disk, $job->spool_path)
                : 0;

            DB::table('clt_consult_jobs')
                ->where('id', $job->id)
                ->where('status', 'pausado')
                ->update([
                    'paused_at' => $job->paused_at ?? Carbon::now(),
                    'spool_bytes' => $spoolBytes,
                    'updated_at' => Carbon::now(),
                ]);
        } catch (Throwable) {
        }
    }

    private function shouldPreserveSpoolForPhaseTwoRerun(CltConsultJob $job): bool
    {
        return $this->stage === self::STAGE_PHASE2
            && $this->supportsCreditPhaseTwo()
            && is_string($job->spool_path ?? null)
            && $job->spool_path !== '';
    }

    private function preservePhaseTwoSpoolForRerun(CltConsultJob $job): void
    {
        $disk = Storage::disk($this->disk);
        $spoolPath = is_string($job->spool_path ?? null) ? $job->spool_path : null;
        $spoolExists = is_string($spoolPath) && $spoolPath !== '' && $disk->exists($spoolPath);
        $spoolBytes = $spoolExists ? $this->fileSizeSafe($this->disk, $spoolPath) : 0;

        CltSpool::deletePhaseTwoAuxiliaryArtifacts(
            $disk,
            $spoolPath,
            $job->spool_cpfs_path ?? null,
            $this->phase2MaxAttempts
        );

        if (DB::table('clt_consult_jobs')->where('id', $job->id)->exists()) {
            try {
                $job->updateQuietly([
                    'spool_path' => $spoolExists ? $spoolPath : null,
                    'spool_cpfs_path' => null,
                    'spool_bytes' => $spoolBytes,
                    'phase' => $spoolExists ? 'fase_2' : null,
                ]);
            } catch (Throwable) {
            }
        }
    }

    private function finalizeCancelledJob(CltConsultJob $job): void
    {
        if ($this->shouldPreserveSpoolForPhaseTwoRerun($job)) {
            $this->preservePhaseTwoSpoolForRerun($job);
        } else {
            $this->cleanupSpool($job);
        }

        DB::table('clt_consult_jobs')
            ->where('id', $job->id)
            ->whereNull('finished_at')
            ->update([
                'finished_at' => Carbon::now(),
            ]);
    }

    private function fileSizeSafe(string $diskName, string $rel): int
    {
        try {
            $disk = Storage::disk($diskName);
            $real = $disk->path($rel);
            clearstatcache(true, $real);
            return file_exists($real) ? (int) filesize($real) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string,true>
     */
    private function loadProcessedCpfsFromSpool(): array
    {
        if ($this->spoolReal === '' || !is_file($this->spoolReal)) {
            return [];
        }

        $fh = @fopen($this->spoolReal, 'rb');
        if (!is_resource($fh)) {
            return [];
        }

        $processed = [];
        try {
            fgetcsv($fh, 0, ';');
            while (($row = fgetcsv($fh, 0, ';')) !== false) {
                $cpf = preg_replace('/\D+/', '', (string) ($row[0] ?? ''));
                if (is_string($cpf) && strlen($cpf) === 11) {
                    $processed[$cpf] = true;
                }
            }
        } finally {
            @fclose($fh);
        }

        return $processed;
    }

    /**
     * Gera o arquivo único de CPFs.
     * $uniqRel deve ser RELATIVO ao disk. Aceita absoluto e não duplica prefixo.
     */
    private function buildUniqueCpfsFile(string $cpfsReal, string $uniqRel): int
    {
        $disk = Storage::disk($this->disk);
        $uniqReal = $this->isAbsolutePath($uniqRel) ? $uniqRel : $disk->path($uniqRel);

        // garante pasta do spool
        if (!$disk->exists($this->dirSpool)) {
            $disk->makeDirectory($this->dirSpool);
        }

        $blockSize = 10000;
        $chunks = [];

        $r = fopen($cpfsReal, 'r');
        if ($r === false)
            return 0;

        try {
            $block = [];
            while (($line = fgets($r)) !== false) {
                $cpf = preg_replace('/\D+/', '', $line);
                if ($cpf === '' || strlen($cpf) !== 11)
                    continue;
                $block[$cpf] = true;
                if (count($block) >= $blockSize || $this->shouldSpill()) {
                    $chunks[] = $this->writeSortedChunk($block); // retorna caminho REAL
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
            $w = fopen($uniqReal, 'w'); // sempre REAL
            if ($w !== false)
                fclose($w);
            return 0;
        }

        if (count($chunks) === 1) {
            // move REAL -> REAL
            if (!@rename($chunks[0], $uniqReal)) {
                throw new \RuntimeException("Falha ao promover arquivo único de CPFs deduplicados.");
            }

            // conta linhas usando REAL
            $cnt = 0;
            $fh = fopen($uniqReal, 'r');
            if ($fh !== false) {
                while (!feof($fh)) {
                    if (fgets($fh) !== false)
                        $cnt++;
                }
                fclose($fh);
            }
            return $cnt;
        }

        // merge k-way para REAL
        $w = fopen($uniqReal, 'w');
        if ($w === false) {
            foreach ($chunks as $c)
                @unlink($c);
            return 0;
        }

        $handles = [];
        $heads = [];
        foreach ($chunks as $i => $pReal) {
            $h = fopen($pReal, 'r');
            if ($h !== false) {
                $handles[$i] = $h;
                $heads[$i] = fgets($h);
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
                $this->writeAllOrFail($w, $minVal . "\n", 'arquivo único de CPFs deduplicados');
                $written++;
                $last = $minVal;
            }

            $heads[$minIdx] = fgets($handles[$minIdx]);
            if ($heads[$minIdx] === false) {
                fclose($handles[$minIdx]);
                unset($handles[$minIdx], $heads[$minIdx]);
            }
        }
        fclose($w);

        foreach ($chunks as $c) {
            @unlink($c);
        }

        return $written;
    }

    private function writeSortedChunk(array $block): string
    {
        $disk = Storage::disk($this->disk);

        // garante pasta do spool antes de escrever chunk
        if (!$disk->exists($this->dirSpool)) {
            $disk->makeDirectory($this->dirSpool);
        }

        $rel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.cpfs.chunk." . uniqid('', true) . ".txt";
        $real = $disk->path($rel);
        $this->pendFiles[] = $rel;

        ksort($block, SORT_STRING);
        $w = fopen($real, 'w');
        if ($w !== false) {
            foreach ($block as $cpf => $_) {
                $this->writeAllOrFail($w, $cpf . "\n", 'chunk temporário de CPFs deduplicados');
            }
            fclose($w);
        }
        return $real; // sempre REAL
    }

    private function appendPhaseOnePendingEntryOrFail($handle, string $cpf, ?string $mensagem = null): void
    {
        $entry = $this->encodePhaseOnePendingEntry($cpf, $mensagem);
        if ($entry === '') {
            return;
        }

        $this->writeAllOrFail($handle, $entry, 'arquivo de pendências da fase 1');
    }

    private function writeCsvRowOrFail($handle, array $row, string $context): void
    {
        $written = fputcsv($handle, $row, ';');
        if ($written === false) {
            throw new \RuntimeException("Falha ao escrever linha CSV em {$context}.");
        }
    }

    private function writeAllOrFail($handle, string $data, string $context): void
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written <= 0) {
                throw new \RuntimeException("Falha ao escrever dados em {$context}.");
            }

            $offset += $written;
        }
    }

    private function shouldSpill(): bool
    {
        $limit = $this->effectiveMemoryBudgetBytes();
        if ($limit <= 0 || $limit === PHP_INT_MAX)
            return false;
        $usage = memory_get_usage(true);
        $threshold = max(0.10, min(0.95, $this->memorySpillThresholdPercent / 100));
        return $usage > (int) floor($limit * $threshold);
    }

    private function simNaoToBool($val): ?bool
    {
        if (is_bool($val))
            return $val;
        if ($val === null)
            return null;

        if (is_int($val) || is_float($val) || (is_string($val) && is_numeric($val))) {
            $n = (int) $val;
            if ($n === 1)
                return true;
            if ($n === 0)
                return false;
        }

        $s = trim((string) $val);
        if ($s === '')
            return null;

        $u = function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
        $uAscii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $u);
        if ($uAscii === false || $uAscii === null)
            $uAscii = $u;
        $uAscii = preg_replace('/\s+/', '', $uAscii);

        $truthy = ['SIM', 'S', 'TRUE', 'T', 'YES', 'Y', '1'];
        $falsy = ['NAO', 'N', 'FALSE', 'F', 'NO', '0'];

        if (in_array($uAscii, $truthy, true))
            return true;
        if (in_array($uAscii, $falsy, true))
            return false;

        return null;
    }

    private function memoryLimitBytes(): int
    {
        $val = ini_get('memory_limit');
        if ($val === false || $val === '' || $val === '-1')
            return PHP_INT_MAX;
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int) $val;
        switch ($last) {
            case 'g':
                $num *= 1024;
            case 'm':
                $num *= 1024;
            case 'k':
                $num *= 1024;
        }
        return $num > 0 ? $num : PHP_INT_MAX;
    }

    private function effectiveMemoryBudgetBytes(): int
    {
        $limits = [$this->memoryLimitBytes()];

        if (is_int($this->runtimeWorkerMemoryLimitBytes) && $this->runtimeWorkerMemoryLimitBytes > 0) {
            $limits[] = $this->runtimeWorkerMemoryLimitBytes;
        }

        $softMb = (int) config('cltfacta.job.memory_soft_limit_mb', 0);
        if ($softMb > 0) {
            $limits[] = $softMb * 1024 * 1024;
        }

        $effective = PHP_INT_MAX;
        foreach ($limits as $candidate) {
            if ($candidate > 0 && $candidate < $effective) {
                $effective = $candidate;
            }
        }

        return $effective;
    }

    private function detectRuntimeWorkerMemoryLimitBytes(): ?int
    {
        $argv = $_SERVER['argv'] ?? null;
        if (!is_array($argv) || empty($argv)) {
            return null;
        }

        $memoryMb = null;
        foreach ($argv as $idx => $arg) {
            if (!is_string($arg) || $arg === '') {
                continue;
            }

            if (preg_match('/^--memory=(\d+)$/', $arg, $m)) {
                $memoryMb = (int) ($m[1] ?? 0);
                break;
            }

            if ($arg === '--memory') {
                $next = $argv[$idx + 1] ?? null;
                if (is_string($next) && ctype_digit($next)) {
                    $memoryMb = (int) $next;
                    break;
                }
            }
        }

        if (!is_int($memoryMb) || $memoryMb <= 0) {
            return null;
        }

        return $memoryMb * 1024 * 1024;
    }

    private function deletePendFiles(): void
    {
        try {
            $disk = Storage::disk($this->disk);
            foreach ($this->pendFiles as $rel) {
                if ($rel && $disk->exists($rel)) {
                    try {
                        $disk->delete($rel);
                    } catch (Throwable) {
                    }
                }
            }
        } catch (Throwable) {
        }
    }

    public function failed(Throwable $e): void
    {
        CltLog::error("[CLT] Job {$this->jobId} marcado como failed pelo worker: " . $e->getMessage(), [
            'exception' => $e,
        ]);

        try {
            $job = CltConsultJob::query()->whereKey($this->jobId)->first();
            if ($job === null) {
                $this->deletePendFiles();
                return;
            }

            if ($job->status === 'cancelado') {
                $this->flushPhase2DeltaBuffer(true);
                $this->flushPhase2PendingBuffer(true);
                $this->finalizeCancelledJob($job);
                $this->deletePendFiles();
                return;
            }

            if ($job->status === 'pausado') {
                $this->flushPhase2DeltaBuffer(true);
                $this->flushPhase2PendingBuffer(true);
                $this->closeSpoolWriter();
                $this->deletePendFiles();
                return;
            }

            $this->cleanupSpool($job);

            if (!in_array($job->status, ['concluido', 'falhou', 'cancelado'], true)) {
                $job->updateQuietly([
                    'status' => 'falhou',
                    'phase' => null,
                    'finished_at' => Carbon::now(),
                ]);
            }

            $this->deletePendFiles();
        } catch (Throwable) {
        }
    }

    private function failFinalize(): void
    {
        $this->dispatchFinalize('falhou');
        $this->deletePendFiles();
    }

    private function beginPhaseOneIfAllowed(CltConsultJob $job): bool
    {
        $lock = Cache::lock('clt_phase1_coordination_lock', $this->phaseOneCoordLockTtl);

        try {
            $lock->block($this->phaseOneCoordLockWait);
        } catch (LockTimeoutException) {
            $this->requeuePhaseOneForCoordination($job);
            return false;
        }

        try {
            $job->refresh();
            if ($job->status === 'pausado') {
                return false;
            }

            if ($job->status === 'cancelado') {
                $this->finalizeCancelledJob($job);
                return false;
            }

            if ($this->hasPhaseOneConcurrencyConflict($job)) {
                $this->requeuePhaseOneForCoordination($job);
                return false;
            }

            $job->update([
                'status' => 'em_progresso',
                'phase' => $this->supportsCreditPhaseTwo() ? 'fase_1' : null,
                'phase2_total' => 0,
                'phase2_attempt' => 0,
                'phase2_aprovado_count' => 0,
                'phase2_nao_aprovado_count' => 0,
                'started_at' => $job->started_at ?? Carbon::now(),
                'spool_bytes' => $this->fileSizeSafe($this->disk, $job->spool_path),
            ]);
            $this->cachedStatus = 'em_progresso';

            return true;
        } finally {
            optional($lock)->release();
        }
    }

    private function hasPhaseOneConcurrencyConflict(CltConsultJob $job): bool
    {
        $query = CltConsultJob::query()
            ->whereKeyNot($job->id)
            ->where('status', 'em_progresso');

        if ($this->variant === 'hybrid') {
            return $query
                ->where(function ($conflictQuery) {
                    $conflictQuery
                        ->where('variant', 'offline')
                        ->orWhere(function ($phaseOneQuery) {
                            $phaseOneQuery
                                ->whereIn('variant', ['online', 'hybrid'])
                                ->where('phase', 'fase_1');
                        });
                })
                ->exists();
        }

        return $query
            ->where('variant', 'hybrid')
            ->where('phase', 'fase_1')
            ->exists();
    }

    private function requeuePhaseOneForCoordination(CltConsultJob $job): void
    {
        if (!in_array($job->status, ['pausado', 'concluido', 'falhou', 'cancelado'], true)) {
            $job->updateQuietly([
                'status' => 'pendente',
                'phase' => null,
            ]);
            $this->cachedStatus = 'pendente';
        }

        DispatchCltConsultJob::dispatch($job->id, self::STAGE_PHASE1)
            ->delay(now()->addSeconds($this->phaseOneCoordRetryDelaySeconds))
            ->onQueue($this->resolvePhaseOneQueue());
    }

    private function resolvePhaseOneQueue(): string
    {
        return CltVariant::resolvePhaseOneQueue($this->variant);
    }

    private function dispatchPhaseTwo(CltConsultJob $job): void
    {
        if ($this->finishIfStopped($job)) {
            return;
        }

        DB::table('clt_consult_jobs')
            ->where('id', $job->id)
            ->whereIn('status', ['pendente', 'em_progresso'])
            ->update([
                'status' => 'em_progresso',
                'phase' => 'fase_2',
                'phase2_total' => 0,
                'phase2_attempt' => 0,
                'phase2_aprovado_count' => 0,
                'phase2_nao_aprovado_count' => 0,
                'updated_at' => Carbon::now(),
            ]);

        $queue = (string) config('cltfacta.job.queue_phase2', 'clt-valida-politica-cred');
        self::dispatch($this->jobId, self::STAGE_PHASE2)->onQueue($queue);

        CltLog::warning('[CLT] Fase 2 enfileirada em worker dedicado.', [
            'job_id' => $this->jobId,
            'queue' => $queue,
        ]);
    }

    private function dispatchFinalize(string $targetStatus): void
    {
        $this->finalizationTriggered = true;
        // Garante persistir deltas pendentes antes da limpeza final e evita recriação no finally.
        $this->flushPhase2DeltaBuffer(true);

        $queue = (string) config('cltfacta.preview.queue', 'reports');
        $inline = (bool) config('cltfacta.preview.inline', true);

        if ($inline) {
            if (is_resource($this->spoolFp)) {
                @fflush($this->spoolFp);
                @fclose($this->spoolFp);
                $this->spoolFp = null;
            }
            FinalizeCltConsultReportJob::dispatchSync($this->jobId, $targetStatus);
            return;
        }

        FinalizeCltConsultReportJob::dispatch($this->jobId, $targetStatus)->onQueue($queue);
    }

    /** Detecta caminho absoluto em Linux/Windows */
    private function isAbsolutePath(string $path): bool
    {
        if ($path === '')
            return false;
        $first = $path[0];
        if ($first === '/' || $first === '\\')
            return true; // Unix ou root Windows estilo "\"
        // Drive letter "C:\" ou "C:/"
        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path);
    }
}
