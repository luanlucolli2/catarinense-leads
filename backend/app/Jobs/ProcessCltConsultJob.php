<?php

namespace App\Jobs;

use App\Exports\CltConsultExport;
use App\Models\CltConsultJob;
use App\Services\FactaApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessCltConsultJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 18300;
    public function uniqueId(): string
    {
        return (string) $this->jobId;
    }

    public int $timeout;
    private int $jobId;

    private string $disk;
    private string $dirReports;
    private string $dirSpool;
    private string $finalPrefix;

    private array $pendFiles = [];

    private $spoolFp = null;
    private string $spoolReal = '';

    private int $flushEveryRows = 10000;
    private int $flushEverySecs = 10;
    private int $flushBytesStep = 1048576; // 1 MiB
    private float $nextFlushAt = 0.0;
    private int $rowsSinceFlush = 0;
    private int $lastFlushedBytes = 0;

    private int $accSuccess = 0;
    private int $accNotFound = 0;
    private int $accFail = 0;

    /** Pacing */
    private int $chunkDelayMs;
    private int $subchunkSize;
    private int $subchunkDelayMs;

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;

        $this->onQueue((string) config('cltfacta.job.queue', 'clt'));

        $this->timeout = (int) config('cltfacta.job.timeout_seconds', 18000);
        $this->disk = (string) config('cltfacta.storage.reports_disk', 'local');
        $this->dirReports = (string) config('cltfacta.storage.dir_reports', 'clt-reports');
        $this->dirSpool = (string) (config('cltfacta.storage.dir_spool') ?? 'clt-spool');
        $this->finalPrefix = (string) config('cltfacta.storage.final_prefix', 'clt-consulta');

        // pacing (configurável por env)
        $this->chunkDelayMs = (int) config('cltfacta.job.chunk_delay_ms', 200);
        $this->subchunkSize = max(1, (int) config('cltfacta.job.subchunk', 5));
        $this->subchunkDelayMs = (int) config('cltfacta.job.subchunk_delay_ms', 120);
    }

    public function handle(FactaApiService $api): void
    {
        /** @var CltConsultJob|null $job */
        $job = CltConsultJob::query()->whereKey($this->jobId)->first();
        if (!$job) {
            $this->deletePendFiles();
            return;
        }

        if ($this->isCancelled($job) || $this->isPaused($job)) {
            $this->deletePreview($job);
            return;
        }

        $disk = Storage::disk($this->disk);
        if (empty($job->spool_path) || empty($job->spool_cpfs_path) || !$disk->exists($job->spool_path) || !$disk->exists($job->spool_cpfs_path)) {
            Log::error("[CLT] Job {$this->jobId} sem spool pré-criado.");
            dispatch(new FinalizeCltConsultReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
            $this->deletePendFiles();
            return;
        }

        $job->update([
            'status' => 'em_progresso',
            'started_at' => $job->started_at ?? Carbon::now(),
            'spool_bytes' => $this->fileSizeSafe($this->disk, $job->spool_path),
            'preview_dirty' => false,
        ]);

        $this->spoolReal = $disk->path($job->spool_path);
        $this->spoolFp = @fopen($this->spoolReal, 'a');
        if (!is_resource($this->spoolFp)) {
            dispatch(new FinalizeCltConsultReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
            $this->deletePendFiles();
            return;
        }
        $this->lastFlushedBytes = $this->fileSizeSafe($this->disk, $job->spool_path);
        $this->nextFlushAt = microtime(true) + $this->flushEverySecs;

        try {
            // 0) DEDUP externo
            $cpfsReal = $disk->path($job->spool_cpfs_path);
            $uniqRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.cpfs.uniq.txt";
            $uniqReal = $disk->path($uniqRel);
            $this->pendFiles[] = $uniqRel;

            $uniqueCount = $this->buildUniqueCpfsFile($cpfsReal, $uniqRel);
            if ($uniqueCount === 0) {
                dispatch(new FinalizeCltConsultReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
                return;
            }
            $this->updateTotalsThrottled($job, $job->spool_path, ['total_cpfs' => $uniqueCount], true);

            // 1) Classificação inicial
            $pend1Rel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.pend.a1.txt";
            $pend1Real = $disk->path($pend1Rel);
            $this->pendFiles[] = $pend1Rel;

            $pf = fopen($pend1Real, 'c+');
            if ($pf === false) {
                $this->failFinalize($job);
                return;
            }
            ftruncate($pf, 0);

            $invCount = 0;
            $reader = fopen($uniqReal, 'r');
            if ($reader === false) {
                fclose($pf);
                $this->failFinalize($job);
                return;
            }
            try {
                $batch = [];
                $snapSz = 500;
                while (($line = fgets($reader)) !== false) {
                    if ($this->finishIfStopped($job)) {
                        // não fechar aqui; o finally fará isso
                        return;
                    }

                    $cpf = preg_replace('/\D+/', '', (string) $line);
                    if ($cpf === '' || strlen($cpf) !== 11)
                        continue;

                    if (!\App\Support\Cpf::isValid($cpf)) {
                        $row = $this->baseRow($cpf);
                        $row['numeroVinculos'] = 0;
                        $row['mensagem'] = 'CPF inválido (dígitos verificadores)';
                        $batch[] = $row;
                        $invCount++;
                        if (count($batch) >= $snapSz) {
                            $this->spoolAppendManyPersist($job, $batch);
                            $batch = [];
                        }
                    } else {
                        fwrite($pf, $cpf . "\n");
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

            if ($invCount > 0) {
                $this->accFail += $invCount;
                $this->updateTotalsThrottled($job, $job->spool_path);
            }

            // 2) Teimosinha
            $maxAttempts = (int) config('cltfacta.job.max_attempts', 5);
            $retryDelay = (int) config('cltfacta.job.retry_delay_seconds', 60);
            $chunkSize = (int) config('cltfacta.job.chunk', 24);
            $minChunk = max(1, (int) config('cltfacta.job.min_chunk', 8));
            $retryAfterCap = (int) config('cltfacta.job.retry_after_max', 120);

            $currPendRel = $pend1Rel;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($this->finishIfStopped($job)) {
                    return;
                }

                if (!$disk->exists($currPendRel) || ((int) $disk->size($currPendRel)) === 0)
                    break;

                $nextPendRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.pend.a" . ($attempt + 1) . ".txt";
                $this->pendFiles[] = $nextPendRel;
                $currPendReal = $disk->path($currPendRel);
                $nextPendReal = $disk->path($nextPendRel);

                $nf = fopen($nextPendReal, 'c+');
                if ($nf === false) {
                    $this->failFinalize($job);
                    return;
                }
                ftruncate($nf, 0);

                $seen429 = 0;
                $retryAfterMaxSeen = 0;
                $successThisAttempt = 0;
                $semRespTotal = 0;
                $totalInAttempt = 0;

                $r2 = fopen($currPendReal, 'r');
                if ($r2 === false) {
                    fclose($nf);
                    $this->failFinalize($job);
                    return;
                }
                try {
                    $buf = [];
                    while (($line = fgets($r2)) !== false) {
                        if ($this->finishIfStopped($job)) {
                            // não fechar aqui; o finally abaixo fechará
                            return;
                        }
                        $cpf = preg_replace('/\D+/', '', (string) $line);
                        if ($cpf === '' || strlen($cpf) !== 11)
                            continue;
                        $buf[] = $cpf;
                        if (count($buf) >= max(1, $chunkSize)) {
                            $this->processChunk($api, $job, $buf, $nf, $seen429, $retryAfterMaxSeen, $semRespTotal, $totalInAttempt, $successThisAttempt);
                            $buf = [];
                            // pacing entre chunks
                            if ($this->chunkDelayMs > 0 && $this->microSleepCoop($this->chunkDelayMs, $job)) {
                                return;
                            }
                        }
                    }
                    if (!empty($buf)) {
                        $this->processChunk($api, $job, $buf, $nf, $seen429, $retryAfterMaxSeen, $semRespTotal, $totalInAttempt, $successThisAttempt);
                        $buf = [];
                        if ($this->chunkDelayMs > 0 && $this->microSleepCoop($this->chunkDelayMs, $job)) {
                            return;
                        }
                    }
                } finally {
                    fclose($r2);
                    fflush($nf);
                    fclose($nf);
                }

                $semRespRatio = $totalInAttempt > 0 ? ($semRespTotal / $totalInAttempt) : 0.0;
                if ($semRespRatio >= 0.50 && $chunkSize > $minChunk) {
                    $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                    Log::warning("[CLT] Job {$this->jobId} – degradado por sem_resposta, chunk={$chunkSize}.");
                }
                if ($seen429 > 0 && $chunkSize > $minChunk) {
                    $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                    Log::warning("[CLT] Job {$this->jobId} – 429 vistos, chunk={$chunkSize}.");
                }

                if ($attempt < $maxAttempts && $disk->exists($nextPendRel) && ((int) $disk->size($nextPendRel)) > 0) {
                    $baseRetryAfter = $retryAfterMaxSeen > 0 ? min($retryAfterMaxSeen, $retryAfterCap) : 0;
                    $base = max(1, $retryDelay, $baseRetryAfter);
                    $sleepFactor = $semRespRatio >= 0.90 ? 2.0 : ($semRespRatio >= 0.50 ? 1.5 : 1.0);
                    $withFactor = (int) ceil($base * $sleepFactor);
                    $jitter = random_int(0, (int) max(1, ceil($withFactor * 0.15)));
                    $sleepSecs = $withFactor + $jitter;
                    if ($this->cooperativeSleep($sleepSecs, $job)) {
                        return;
                    }
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

            // 3) Fechamento de pendências
            if ($disk->exists($currPendRel) && ((int) $disk->size($currPendRel)) > 0) {
                $r = fopen($disk->path($currPendRel), 'r');
                if ($r !== false) {
                    $rows = [];
                    $batch = 500;
                    $left = 0;
                    while (($line = fgets($r)) !== false) {
                        $cpf = preg_replace('/\D+/', '', (string) $line);
                        if ($cpf === '' || strlen($cpf) !== 11)
                            continue;
                        $row = $this->baseRow($cpf);
                        $row['numeroVinculos'] = 0;
                        $row['mensagem'] = 'Não foi possível consultar após múltiplas tentativas';
                        $rows[] = $row;
                        $left++;
                        if (count($rows) >= $batch) {
                            $this->spoolAppendManyPersist($job, $rows);
                            $rows = [];
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

            $this->updateTotalsThrottled($job, $job->spool_path, [], true);

            dispatch(new FinalizeCltConsultReportJob($this->jobId, 'concluido'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
        } finally {
            if (is_resource($this->spoolFp)) {
                @fflush($this->spoolFp);
                @fclose($this->spoolFp);
            }
            $this->deletePendFiles();
        }
    }

    private function processChunk(
        FactaApiService $api,
        CltConsultJob $job,
        array $chunkCpfs,
        $nextPendHandle,
        int &$seen429InAttempt,
        int &$retryAfterMaxSeen,
        int &$semRespTotal,
        int &$totalInAttempt,
        int &$successThisAttempt
    ): void {
        $t0 = microtime(true);
        $chunkCount = count($chunkCpfs);

        // micro-batching dentro do chunk
        $micro = max(1, min($this->subchunkSize, $chunkCount));
        $slices = ($micro >= $chunkCount) ? [$chunkCpfs] : array_chunk($chunkCpfs, $micro);

        $rows = [];
        $successInChunk = 0;
        $notFoundInChunk = 0;
        $failTermInChunk = 0;
        $retriableInChunk = 0;
        $semRespInChunk = 0;
        $http429InChunk = 0;

        foreach ($slices as $idx => $slice) {
            if ($this->finishIfStopped($job))
                return;

            try {
                $batchResults = $api->autorizaConsultaLote($slice);
            } catch (\Throwable $e) {
                Log::error("[CLT] Job {$this->jobId} erro no autorizaConsultaLote: " . $e->getMessage(), ['exception' => $e]);
                foreach ($slice as $cpf) {
                    fwrite($nextPendHandle, $cpf . "\n");
                }
                $semRespTotal += count($slice);
                $totalInAttempt += count($slice);
                $semRespInChunk += count($slice);
                $retriableInChunk += count($slice);
                // micro-pacing entre subchunks mesmo em erro
                if ($this->subchunkDelayMs > 0 && $this->microSleepCoop($this->subchunkDelayMs, $job))
                    return;
                continue;
            }

            foreach ($slice as $cpf) {
                $res = $batchResults[$cpf] ?? [
                    'ok' => false,
                    'mensagem' => 'Sem resposta do serviço',
                    'vinculos' => null,
                    'retriable' => true,
                    'not_found' => false,
                    'http_status' => null,
                    'retry_after' => null,
                ];

                $http = $res['http_status'] ?? null;
                if ($http === 429) {
                    $http429InChunk++;
                    $seen429InAttempt++;
                }
                if ($http === null) {
                    $semRespInChunk++;
                    $semRespTotal++;
                }
                if (!empty($res['retry_after'])) {
                    $retryAfterMaxSeen = max($retryAfterMaxSeen, (int) $res['retry_after']);
                }

                if (!empty($res['ok'])) {
                    $vinculos = $res['vinculos'] ?? [];
                    $total = is_array($vinculos) ? count($vinculos) : 0;

                    if ($total > 0) {
                        foreach ($vinculos as $v) {
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

                            $row['possuiAlertas'] = $v['possuiAlertas'] ?? null;
                            $row['qtdEmprestimosAtivosSuspensos'] = $v['qtdEmprestimosAtivosSuspensos'] ?? null;
                            $row['emprestimosLegados'] = $v['emprestimosLegados'] ?? null;
                            $row['pessoaExpostaPoliticamente_descricao'] = $v['pessoaExpostaPoliticamente_descricao'] ?? null;

                            $row['nome'] = $v['nome'] ?? null;
                            $row['dataNascimento'] = $v['dataNascimento'] ?? null;
                            $row['idade'] = $this->computeIdadeAnos($v['dataNascimento'] ?? null);
                            $row['sexo_descricao'] = $v['sexo_descricao'] ?? null;

                            $row['status_code'] = $v['status_code'] ?? null;
                            $row['mensagem'] = $res['mensagem'] ?? 'OK';

                            $rows[] = $row;
                        }
                    } else {
                        $row = $this->baseRow($cpf);
                        $row['numeroVinculos'] = 0;
                        $row['mensagem'] = $res['mensagem'] ?? 'Sem vínculos';
                        $rows[] = $row;
                    }

                    $successInChunk++;
                    $successThisAttempt++;
                } else {
                    $msg = (string) ($res['mensagem'] ?? 'Falha na consulta');

                    if (!empty($res['not_found'])) {
                        $row = $this->baseRow($cpf);
                        $row['numeroVinculos'] = 0;
                        $row['mensagem'] = $msg;
                        $rows[] = $row;
                        $notFoundInChunk++;
                    } elseif (($res['retriable'] ?? true) === false) {
                        $row = $this->baseRow($cpf);
                        $row['numeroVinculos'] = 0;
                        $row['mensagem'] = $msg;
                        $rows[] = $row;
                        $failTermInChunk++;
                    } else {
                        fwrite($nextPendHandle, $cpf . "\n");
                        $retriableInChunk++;
                    }
                }
            }

            // pacing entre subchunks
            if ($this->subchunkDelayMs > 0 && $idx < count($slices) - 1) {
                if ($this->microSleepCoop($this->subchunkDelayMs, $job))
                    return;
            }
        }

        if (!empty($rows)) {
            $this->spoolAppendManyPersist($job, $rows);
        }

        if ($successInChunk > 0) {
            $this->accSuccess += $successInChunk;
        }
        if ($notFoundInChunk > 0) {
            $this->accNotFound += $notFoundInChunk;
        }
        if ($failTermInChunk > 0) {
            $this->accFail += $failTermInChunk;
        }

        $this->updateTotalsThrottled($job, $job->spool_path);
        $totalInAttempt += $chunkCount;

        // LOG do resumo do chunk
        $elapsed = microtime(true) - $t0;
        $rps = $elapsed > 0 ? number_format($chunkCount / $elapsed, 1, ',', '.') : 'inf';
        Log::debug(
            "[CLT] job={$this->jobId} chunk=OK size={$chunkCount}"
            . " sub={$micro}"
            . " time=" . number_format($elapsed, 3, ',', '.') . "s"
            . " rate={$rps} cps"
            . " ok={$successInChunk}"
            . " not_found={$notFoundInChunk}"
            . " fail_term={$failTermInChunk}"
            . " retriable={$retriableInChunk}"
            . " sem_resp={$semRespInChunk}"
            . " http429={$http429InChunk}"
            . " retry_after_max_attempt={$retryAfterMaxSeen}"
            . " success_attempt_total={$successThisAttempt}"
        );
    }

    private function baseRow(string $cpf): array
    {
        $row = array_fill_keys(CltConsultExport::COLS, null);
        $row['cpf'] = $cpf;
        return $row;
    }

    private function spoolAppendManyPersist(CltConsultJob $job, array $rows): void
    {
        if (!is_resource($this->spoolFp))
            throw new \RuntimeException("Writer do spool não inicializado.");
        if (flock($this->spoolFp, LOCK_EX)) {
            foreach ($rows as $row) {
                $ordered = [];
                foreach (CltConsultExport::COLS as $key) {
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

    private function updateTotalsThrottled(CltConsultJob $job, string $spoolRel, array $extra = [], bool $force = false): void
    {
        $now = microtime(true);

        $needBytesCheck = $force || $this->rowsSinceFlush >= $this->flushEveryRows || $now >= $this->nextFlushAt;

        $prevBytes = $this->lastFlushedBytes;
        $bytes = $prevBytes;
        if ($needBytesCheck) {
            try {
                clearstatcache(true, $this->spoolReal);
                $bytes = file_exists($this->spoolReal) ? (int) filesize($this->spoolReal) : 0;
            } catch (Throwable) {
                $bytes = $prevBytes;
            }
        }

        $bytesDelta = $bytes - $prevBytes;
        $triggerRows = $this->rowsSinceFlush >= $this->flushEveryRows;
        $triggerTime = $now >= $this->nextFlushAt;
        $triggerBytes = $bytesDelta >= $this->flushBytesStep;

        $shouldFlush = $force || $triggerRows || $triggerTime || $triggerBytes;
        if (!$shouldFlush)
            return;

        $updates = array_merge([
            'spool_bytes' => $bytes,
            'preview_dirty' => true,
            'updated_at' => Carbon::now(),
        ], $extra);

        if ($this->accSuccess > 0)
            $updates['success_count'] = DB::raw('success_count + ' . $this->accSuccess);
        if ($this->accNotFound > 0)
            $updates['not_found_count'] = DB::raw('not_found_count + ' . $this->accNotFound);
        if ($this->accFail > 0)
            $updates['fail_count'] = DB::raw('fail_count + ' . $this->accFail);

        try {
            Log::info('[CLT][FLUSH]', [
                'job' => $this->jobId,
                'force' => $force,
                'triggers' => ['rows' => $triggerRows, 'time' => $triggerTime, 'bytes' => $triggerBytes],
                'rows_since' => $this->rowsSinceFlush,
                'bytes_prev' => $prevBytes,
                'bytes_now' => $bytes,
                'bytes_delta' => $bytesDelta,
                'acc' => ['ok' => $this->accSuccess, 'nf' => $this->accNotFound, 'fail' => $this->accFail],
                'extra_keys' => array_keys($extra),
            ]);
        } catch (Throwable $e) {
        }

        DB::table('clt_consult_jobs')->where('id', $job->id)->update($updates);

        $job->spool_bytes = $bytes;
        $job->preview_dirty = true;
        $this->rowsSinceFlush = 0;
        $this->nextFlushAt = $now + $this->flushEverySecs;
        $this->lastFlushedBytes = $bytes;
        $this->accSuccess = $this->accNotFound = $this->accFail = 0;
    }

    private function computeValorMaxPrest($valor): ?string
    {
        $f = $this->toFloatPtBr($valor);
        if ($f === null)
            return null;
        $n = round($f, 2);
        return number_format($n, 2, ',', '');
    }

    private function computeTempoAdmissaoMeses(?string $admissao, ?string $deslig): ?int
    {
        try {
            if (!$admissao || !preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $admissao))
                return null;
            $a = Carbon::createFromFormat('d/m/Y', $admissao);
            $b = ($deslig && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $deslig))
                ? Carbon::createFromFormat('d/m/Y', $deslig)
                : Carbon::now('America/Sao_Paulo');
            return $a->diffInMonths($b);
        } catch (\Throwable) {
            return null;
        }
    }

    private function computeIdadeAnos(?string $nasc): ?int
    {
        try {
            if (!$nasc || !preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $nasc))
                return null;
            $d = Carbon::createFromFormat('d/m/Y', $nasc);
            return $d->age;
        } catch (\Throwable) {
            return null;
        }
    }

    private function toFloatPtBr($val): ?float
    {
        if ($val === null)
            return null;
        if (is_numeric($val))
            return (float) $val;
        $s = preg_replace('/[^\d,.-]/', '', (string) $val);
        $s = str_replace(['.', ' '], ['', ''], $s);
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s))
            return null;
        return (float) $s;
    }

    private function cooperativeSleep(int $seconds, CltConsultJob $job): bool
    {
        $total = max(0, $seconds);
        if ($total <= 0) {
            return $this->finishIfStopped($job);
        }

        Log::info("[CLT] job={$this->jobId} backoff:start sleep={$total}s");
        $remaining = $total;
        $start = microtime(true);

        while ($remaining > 0) {
            if ($this->finishIfStopped($job)) {
                $slept = (int) floor($total - $remaining);
                Log::info("[CLT] job={$this->jobId} backoff:aborted slept={$slept}s");
                return true;
            }
            sleep(1);
            $remaining -= 1;
        }

        $elapsed = (int) floor(microtime(true) - $start);
        Log::info("[CLT] job={$this->jobId} backoff:done slept={$elapsed}s");
        return $this->finishIfStopped($job);
    }

    /** micro-sleep cooperativo em milissegundos; retorna true se parar */
    private function microSleepCoop(int $ms, CltConsultJob $job): bool
    {
        $remain = max(0, $ms) * 1000; // us
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

    private function finishIfStopped(CltConsultJob $job): bool
    {
        if ($this->isCancelled($job)) {
            $job->update(['finished_at' => Carbon::now()]);
            $this->deletePreview($job);
            $this->cleanupSpool($job);
            Log::info("[CLT] Job {$this->jobId} cancelado.");
            return true;
        }
        if ($this->isPaused($job)) {
            Log::info("[CLT] Job {$this->jobId} pausado, saindo sem limpar.");
            return true;
        }
        if ($job->status !== 'em_progresso') {
            DB::table('clt_consult_jobs')
                ->where('id', $job->id)
                ->whereNotIn('status', ['cancelado', 'pausado', 'concluido', 'falhou'])
                ->update(['status' => 'em_progresso']);
            $job->status = 'em_progresso';
        }
        return false;
    }

    private function isCancelled(CltConsultJob $job): bool
    {
        $s = DB::table('clt_consult_jobs')->where('id', $job->id)->value('status');
        return $s === 'cancelado';
    }
    private function isPaused(CltConsultJob $job): bool
    {
        $s = DB::table('clt_consult_jobs')->where('id', $job->id)->value('status');
        return $s === 'pausado';
    }

    private function cleanupSpool(CltConsultJob $job): void
    {
        try {
            $disk = Storage::disk($this->disk);
            foreach (['spool_path', 'spool_cpfs_path'] as $f) {
                $p = $job->{$f} ?? null;
                if ($p && $disk->exists($p)) {
                    try {
                        $disk->delete($p);
                    } catch (Throwable) {
                    }
                }
            }
        } finally {
            $job->updateQuietly(['spool_path' => null, 'spool_cpfs_path' => null, 'spool_bytes' => 0]);
        }
    }

    private function deletePreview(CltConsultJob $job): void
    {
        try {
            if ($job->preview_disk && $job->preview_path) {
                $disk = Storage::disk($job->preview_disk);
                if ($disk->exists($job->preview_path))
                    $disk->delete($job->preview_path);
            }
        } catch (Throwable $e) {
            Log::warning("[CLT] Job {$this->jobId} falha ao apagar prévia: " . $e->getMessage());
        } finally {
            $job->updateQuietly([
                'preview_disk' => null,
                'preview_path' => null,
                'preview_name' => null,
                'preview_updated_at' => null,
                'preview_dirty' => false,
                'preview_status' => 'none',
                'preview_requested_at' => null,
                'preview_started_at' => null,
                'preview_finished_at' => null,
                'preview_size_bytes' => 0,
                'preview_rows' => 0,
                'preview_error' => null,
            ]);
        }
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

    private function buildUniqueCpfsFile(string $cpfsReal, string $uniqRel): int
    {
        $disk = Storage::disk($this->disk);
        $uniqReal = $disk->path($uniqRel);

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
                    if (fgets($fh) !== false)
                        $cnt++;
                }
                fclose($fh);
            }
            return $cnt;
        }

        $w = fopen($uniqReal, 'w');
        if ($w === false) {
            foreach ($chunks as $c)
                @unlink($c);
            return 0;
        }
        $handles = [];
        $heads = [];
        foreach ($chunks as $i => $p) {
            $h = fopen($p, 'r');
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
                fwrite($w, $minVal . "\n");
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

    private function failFinalize(CltConsultJob $job): void
    {
        dispatch(new FinalizeCltConsultReportJob($this->jobId, 'falhou'))->onQueue((string) config('facta_off.preview.queue', 'reports'));
        $this->deletePendFiles();
    }
}
