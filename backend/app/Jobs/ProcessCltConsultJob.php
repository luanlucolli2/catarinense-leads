<?php

namespace App\Jobs;

use App\Models\CltConsultJob;
use App\Support\CltLog;
use App\Support\CltSchema;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessCltConsultJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 115260;
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

    // ====== FLUSH: mantemos apenas por tempo ======
    private int $flushEverySecs = 10;
    private float $lastFlushAt = 0.0;
    private int $statusCheckIntervalMs;
    private float $lastStatusCheckAt = 0.0;
    private ?string $cachedStatus = null;

    private int $accSuccess = 0;
    private int $accNotFound = 0;
    private int $accFail = 0;

    /** Pacing */
    private int $chunkDelayMs;
    private int $subchunkSize;
    private int $subchunkDelayMs;
    private int $rowsBufferFlush;
    private int $snapBufferFlush;
    private bool $backoffLog;
    private bool $chunkPerfDebug;
    private bool $flushProgressLog;

    /** Guarda a variante (online|offline) para a regra de snapshot */
    private string $variant = 'online';
    private array $baseRowTemplate = [];

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;

        // Nota: a fila é definida no dispatch (controller) por variante.
        $this->timeout = (int) config('cltfacta.job.timeout_seconds', 115200);
        $this->disk = (string) config('cltfacta.storage.reports_disk', 'local');
        $this->dirReports = (string) config('cltfacta.storage.dir_reports', 'clt-reports');
        $this->dirSpool = (string) (config('cltfacta.storage.dir_spool') ?? 'clt-spool');
        $this->finalPrefix = (string) config('cltfacta.storage.final_prefix', 'clt-consulta');

        // pacing (configurável por env)
        $this->chunkDelayMs = (int) config('cltfacta.job.chunk_delay_ms', 200);
        $this->subchunkSize = max(1, (int) config('cltfacta.job.subchunk', 5));
        $this->subchunkDelayMs = (int) config('cltfacta.job.subchunk_delay_ms', 120);
        $this->rowsBufferFlush = max(1, (int) config('cltfacta.job.rows_buffer_flush', 300));
        $this->snapBufferFlush = max(1, (int) config('cltfacta.job.snap_buffer_flush', 300));
        $this->statusCheckIntervalMs = max(100, (int) config('cltfacta.job.status_check_interval_ms', 1000));
        $this->backoffLog = (bool) config('cltfacta.logging.backoff_log', false);
        $this->chunkPerfDebug = (bool) config('cltfacta.logging.chunk_perf_debug', false);
        $this->flushProgressLog = (bool) config('cltfacta.logging.flush_progress_log', false);
        $this->baseRowTemplate = array_fill_keys(CltSchema::COLS, null);
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
        $this->variant = ($job->variant === 'offline') ? 'offline' : 'online';
        $this->cachedStatus = $job->status;
        $this->lastStatusCheckAt = microtime(true);

        $api = $job->variant === 'offline'
            ? app(\App\Services\CltOfflineApiService::class)
            : app(\App\Services\FactaApiService::class);

        if ($this->isCancelled($job)) {
            $this->cleanupSpool($job);
            return;
        }

        $disk = Storage::disk($this->disk);
        if (empty($job->spool_path) || empty($job->spool_cpfs_path) || !$disk->exists($job->spool_path) || !$disk->exists($job->spool_cpfs_path)) {
            CltLog::error("[CLT] Job {$this->jobId} sem spool pré-criado.");
            $this->dispatchFinalize('falhou');
            $this->deletePendFiles();
            return;
        }

        $job->update([
            'status' => 'em_progresso',
            'started_at' => $job->started_at ?? Carbon::now(),
            'spool_bytes' => $this->fileSizeSafe($this->disk, $job->spool_path),
        ]);

        $this->spoolReal = $disk->path($job->spool_path);
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
            $reader = fopen($disk->path($uniqRel), 'r');
            if ($reader === false) {
                fclose($pf);
                $this->failFinalize($job);
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

                    if (!\App\Support\Cpf::isValid($cpf)) {
                        $row = $this->baseRow($cpf);
                        $row['numeroVinculos'] = 0;
                        $row['mensagem'] = 'CPF inválido (dígitos verificadores)';
                        $row['consulted_at'] = $nowBr;
                        $batch[] = $row;
                        $invCount++;
                        if (count($batch) >= $snapSz) {
                            $this->spoolAppendManyPersist($job, $batch);
                            $batch = [];
                            $nowBr = date('d/m/Y H:i:s'); // Atualiza relógio a cada lote
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
                if ($this->finishIfStopped($job))
                    return;

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
                        if ($this->finishIfStopped($job))
                            return;

                        $cpf = preg_replace('/\D+/', '', (string) $line);
                        if ($cpf === '' || strlen($cpf) !== 11)
                            continue;

                        $buf[] = $cpf;
                        if (count($buf) >= max(1, $chunkSize)) {
                            $this->processChunk($api, $job, $buf, $nf, $seen429, $retryAfterMaxSeen, $semRespTotal, $totalInAttempt, $successThisAttempt);
                            $buf = [];
                            if ($this->chunkDelayMs > 0 && $this->microSleepCoop($this->chunkDelayMs, $job))
                                return;
                        }
                    }
                    if (!empty($buf)) {
                        $this->processChunk($api, $job, $buf, $nf, $seen429, $retryAfterMaxSeen, $semRespTotal, $totalInAttempt, $successThisAttempt);
                        $buf = [];
                        if ($this->chunkDelayMs > 0 && $this->microSleepCoop($this->chunkDelayMs, $job))
                            return;
                    }
                } finally {
                    fclose($r2);
                    fflush($nf);
                    fclose($nf);
                }

                $semRespRatio = $totalInAttempt > 0 ? ($semRespTotal / $totalInAttempt) : 0.0;
                if ($semRespRatio >= 0.50 && $chunkSize > $minChunk) {
                    $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                    CltLog::warning("[CLT] Job {$this->jobId} – degradado por sem_resposta, chunk={$chunkSize}.");
                }
                if ($seen429 > 0 && $chunkSize > $minChunk) {
                    $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                    CltLog::warning("[CLT] Job {$this->jobId} – 429 vistos, chunk={$chunkSize}.");
                }

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
                        $cpf = preg_replace('/\D+/', '', (string) $line);
                        if ($cpf === '' || strlen($cpf) !== 11)
                            continue;
                        $row = $this->baseRow($cpf);
                        $row['numeroVinculos'] = 0;
                        $row['mensagem'] = 'Não foi possível consultar após múltiplas tentativas';
                        $row['consulted_at'] = $nowBr;
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

            $this->updateTotalsThrottled($job, $job->spool_path, [], true);

            $this->dispatchFinalize('concluido');
        } finally {
            if (is_resource($this->spoolFp)) {
                @fflush($this->spoolFp);
                @fclose($this->spoolFp);
            }
            $this->deletePendFiles();
        }
    }

    private function processChunk(
        $api,
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

        $micro = max(1, min($this->subchunkSize, $chunkCount));
        $slices = ($micro >= $chunkCount) ? [$chunkCpfs] : array_chunk($chunkCpfs, $micro);
        $sliceTotal = count($slices);

        $rows = [];
        $snapRows = [];
        $successInChunk = 0;
        $notFoundInChunk = 0;
        $failTermInChunk = 0;
        $retriableInChunk = 0;
        $semRespInChunk = 0;
        $http429InChunk = 0;

        // CORREÇÃO: Força o timezone BR (America/Sao_Paulo) explicitamente.
        // OTIMIZAÇÃO: Chamamos o Carbon apenas UMA vez por lote (chunk), e não por CPF.
        // Isso é extremamente leve para o servidor (custo zero de CPU no loop).
        $nowStr = Carbon::now('America/Sao_Paulo')->format('d/m/Y H:i:s');
        $onlineFactaApi = ($this->variant === 'online' && $api instanceof \App\Services\FactaApiService)
            ? $api
            : null;

        foreach ($slices as $idx => $slice) {
            if ($this->finishIfStopped($job))
                return;

            try {
                $batchResults = $api->autorizaConsultaLote($slice);
            } catch (\Throwable $e) {
                CltLog::error("[CLT] Job {$this->jobId} erro no autorizaConsultaLote: " . $e->getMessage(), ['exception' => $e]);
                foreach ($slice as $cpf) {
                    fwrite($nextPendHandle, $cpf . "\n");
                }
                $semRespTotal += count($slice);
                $totalInAttempt += count($slice);
                $semRespInChunk += count($slice);
                $retriableInChunk += count($slice);
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
                        $bestIdx = $this->pickLatestVinculoIndex($vinculos);
                        $best = ($bestIdx !== null && isset($vinculos[$bestIdx]) && is_array($vinculos[$bestIdx]))
                            ? $vinculos[$bestIdx]
                            : null;

                        $politica = null;
                        if ($onlineFactaApi !== null && $best !== null) {
                            $bestElegivel = $this->simNaoToBool($best['elegivel'] ?? null) === true;
                            if ($bestElegivel) {
                                $politica = $onlineFactaApi->continuarCreditoTrabalhadorElegivel([
                                    'cpf' => $cpf,
                                    'matricula' => $best['matricula'] ?? null,
                                    'dataNascimento' => $best['dataNascimento'] ?? null,
                                    'dataAdmissao' => $best['dataAdmissao'] ?? null,
                                    'valorParcela' => $this->computeValorMaxPrestFloat($best['valorMargemDisponivel'] ?? null),
                                    'valorRenda' => $best['valorTotalVencimentos'] ?? null,
                                ]);

                                if (($politica['retriable'] ?? false) === true) {
                                    $pHttp = $politica['http_status'] ?? null;
                                    if ($pHttp === 429) {
                                        $http429InChunk++;
                                        $seen429InAttempt++;
                                    }
                                    if ($pHttp === null) {
                                        $semRespInChunk++;
                                        $semRespTotal++;
                                    }
                                    if (!empty($politica['retry_after'])) {
                                        $retryAfterMaxSeen = max($retryAfterMaxSeen, (int) $politica['retry_after']);
                                    }

                                    fwrite($nextPendHandle, $cpf . "\n");
                                    $retriableInChunk++;
                                    continue;
                                }
                            }
                        }

                        foreach ($vinculos as $i => $v) {
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

                            // NOVO: meses de empresa a partir do início da atividade do empregador
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
                            $row['mensagem'] = $res['mensagem'] ?? 'OK';

                            // CONVERSÃO DE DATA LEVE
                            $rawUpdated = $v['updated_at'] ?? ($v['created_at'] ?? null);
                            $row['updated_at'] = $this->toBrDateTime($rawUpdated);
                            $row['consulted_at'] = $nowStr;

                            if ($bestIdx !== null && $i === $bestIdx && is_array($politica) && !empty($politica['attempted'])) {
                                $row['politicaCreditoAprovado'] = !empty($politica['aprovado']) ? 'SIM' : 'NÃO';
                                $row['politicaCreditoMensagem'] = $politica['mensagem'] ?? null;
                                $row['politicaCreditoValorMaximoDisponivel'] = $politica['valor_maximo_disponivel'] ?? null;
                                $row['politicaCreditoPrazoMaximoDisponivel'] = $politica['prazo_maximo_disponivel'] ?? null;
                            }

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

                                // NOVO snapshot: meses da empresa
                                'meses_emp' => $this->computeMesesEmpresaEmpregador($best['dataInicioAtividadeEmpregador'] ?? null),

                                'qtd_ems' => isset($best['qtdEmprestimosAtivosSuspensos']) ? (int) $best['qtdEmprestimosAtivosSuspensos'] : null,
                                'legados' => array_key_exists('emprestimosLegados', $best)
                                    ? $this->simNaoToBool($best['emprestimosLegados'])
                                    : null,

                                // carimbo da ORIGEM (UTC)
                                'src_updated_at' => $best['updated_at'] ?? ($best['created_at'] ?? null),

                                'not_found' => false,
                            ];
                        }
                    } else {
                        $row = $this->baseRow($cpf);
                        $row['numeroVinculos'] = 0;
                        $row['mensagem'] = $res['mensagem'] ?? 'Sem vínculos';
                        $row['consulted_at'] = $nowStr; // Data da consulta
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
                        $row['consulted_at'] = $nowStr; // Data da consulta (Not Found)
                        $rows[] = $row;

                        $snapRows[] = [
                            'cpf' => $cpf,
                            'not_found' => true,
                            'src_updated_at' => null,
                        ];

                        $notFoundInChunk++;
                    } elseif (($res['retriable'] ?? true) === false) {
                        $row = $this->baseRow($cpf);
                        $row['numeroVinculos'] = 0;
                        $row['mensagem'] = $msg;
                        $row['consulted_at'] = $nowStr; // Data da consulta (Erro terminal)
                        $rows[] = $row;
                        $failTermInChunk++;
                    } else {
                        fwrite($nextPendHandle, $cpf . "\n");
                        $retriableInChunk++;
                    }
                }

                if (count($rows) >= $this->rowsBufferFlush) {
                    $this->spoolAppendManyPersist($job, $rows);
                    $rows = [];
                }
                if (count($snapRows) >= $this->snapBufferFlush) {
                    $this->persistSnapshots($snapRows);
                    $snapRows = [];
                }
            }

            if (!empty($rows)) {
                $this->spoolAppendManyPersist($job, $rows);
                $rows = [];
            }
            if (!empty($snapRows)) {
                $this->persistSnapshots($snapRows);
                $snapRows = [];
            }

            if ($this->subchunkDelayMs > 0 && $idx < $sliceTotal - 1) {
                if ($this->microSleepCoop($this->subchunkDelayMs, $job))
                    return;
            }
        }

        if ($successInChunk > 0)
            $this->accSuccess += $successInChunk;
        if ($notFoundInChunk > 0)
            $this->accNotFound += $notFoundInChunk;
        if ($failTermInChunk > 0)
            $this->accFail += $failTermInChunk;

        $this->updateTotalsThrottled($job, $job->spool_path);
        $totalInAttempt += $chunkCount;

        if ($this->chunkPerfDebug) {
            $elapsed = microtime(true) - $t0;
            $rps = $elapsed > 0 ? number_format($chunkCount / $elapsed, 1, ',', '.') : 'inf';
            CltLog::debug("[CLT] job={$this->jobId} chunk=OK size={$chunkCount} sub={$micro} time=" . number_format($elapsed, 3, ',', '.') . "s rate={$rps} cps");
        }
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
        if (flock($this->spoolFp, LOCK_EX)) {
            foreach ($rows as $row) {
                $ordered = [];
                foreach (CltSchema::COLS as $key) {
                    $ordered[] = $row[$key] ?? null;
                }
                fputcsv($this->spoolFp, $ordered, ';');
            }
            fflush($this->spoolFp);
            flock($this->spoolFp, LOCK_UN);
        }

        // apenas flush por tempo/force (nenhum contador de linhas)
        $this->updateTotalsThrottled($job, $job->spool_path);
    }

    private function updateTotalsThrottled(CltConsultJob $job, string $spoolRel, array $extra = [], bool $force = false): void
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

        if ($this->accSuccess > 0)
            $updates['success_count'] = DB::raw('COALESCE(success_count,0) + ' . $this->accSuccess);
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
        $this->accSuccess = $this->accNotFound = $this->accFail = 0;
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

    private function pickLatestVinculo(array $vinculos): ?array
    {
        $idx = $this->pickLatestVinculoIndex($vinculos);
        if ($idx === null || !isset($vinculos[$idx]) || !is_array($vinculos[$idx]))
            return null;
        return $vinculos[$idx];
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

        if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $t))
            return Carbon::createFromFormat('d/m/Y', $t);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $t))
            return Carbon::createFromFormat('Y-m-d', $t);
        return null;
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

            foreach ($snapRows as $r) {
                $cpf = (string) ($r['cpf'] ?? '');
                if ($cpf === '' || strlen($cpf) !== 11)
                    continue;

                $cpfs[] = $cpf;

                $srcUpdated = $this->parseDateTimeFlexible($r['src_updated_at'] ?? null);

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

            $existing = DB::table('clt_snapshots')
                ->whereIn('cpf', array_values(array_unique($cpfs)))
                ->select('cpf', 'updated_at', 'not_found')
                ->get()
                ->keyBy('cpf');

            $toUpsert = [];
            $nowUtc = Carbon::now('UTC')->format('Y-m-d H:i:s');
            $isOnline = ($this->variant === 'online');

            foreach ($payload as $row) {
                $cpf = $row['cpf'];
                $srcUpdated = $row['_src_updated_at'] ?? null;
                $rowExists = $existing->has($cpf);
                $existingRow = $rowExists ? $existing[$cpf] : null;

                $existingUpdated = null;
                if ($existingRow && isset($existingRow->updated_at) && $existingRow->updated_at !== null) {
                    $existingUpdated = $this->parseDateTimeFlexible((string) $existingRow->updated_at);
                }

                $incomingNotFound = (bool) ($row['not_found'] ?? false);

                unset($row['_src_updated_at']);

                if ($isOnline) {
                    $row['job_id'] = $this->jobId;
                    $row['updated_at'] = $srcUpdated ?? $nowUtc;
                    $row['consulted_at'] = $nowUtc;
                    $toUpsert[] = $row;
                    continue;
                }

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

    private function finishIfStopped(CltConsultJob $job): bool
    {
        $status = $this->currentStatusCached($job->id);
        if ($status === null) {
            $this->cleanupSpool($job);
            CltLog::info("[CLT] Job {$this->jobId} removido durante execução. Encerrando.");
            return true;
        }

        if ($status === 'cancelado') {
            DB::table('clt_consult_jobs')->where('id', $job->id)->update(['finished_at' => Carbon::now()]);
            $this->cleanupSpool($job);
            CltLog::info("[CLT] Job {$this->jobId} cancelado.");
            return true;
        }

        if ($status !== 'em_progresso') {
            DB::table('clt_consult_jobs')
                ->where('id', $job->id)
                ->whereNotIn('status', ['cancelado', 'concluido', 'falhou'])
                ->update(['status' => 'em_progresso']);
            $this->cachedStatus = 'em_progresso';
            $this->lastStatusCheckAt = microtime(true);
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
            if (DB::table('clt_consult_jobs')->where('id', $job->id)->exists()) {
                try {
                    $job->updateQuietly(['spool_path' => null, 'spool_cpfs_path' => null, 'spool_bytes' => 0]);
                } catch (Throwable) {
                }
            }
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
                if (count($block) >= $blockSize || $this->shouldSpill(count($block))) {
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
            @rename($chunks[0], $uniqReal);

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
                fwrite($w, $cpf . "\n");
            }
            fclose($w);
        }
        return $real; // sempre REAL
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
        $this->dispatchFinalize('falhou');
        $this->deletePendFiles();
    }

    private function dispatchFinalize(string $targetStatus): void
    {
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
