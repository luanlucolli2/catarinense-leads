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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ProcessCltConsultJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Exclusividade por jobId (evita múltiplos enfileiramentos/execuções simultâneas). */
    public int $uniqueFor = 18300; // 5h + margem

    public function uniqueId(): string
    {
        return (string) $this->jobId;
    }

    /** Timeout por job (segundos). */
    public int $timeout;

    private int $jobId;

    /** @var string[] CPFs válidos (11 dígitos) */
    private array $cpfs;

    /** @var string[] CPFs inválidos (11 dígitos mas DV inválido) */
    private array $invalidCpfs;

    /** Storage / Spool */
    private string $disk;
    private string $dirReports;
    private string $dirSpool;
    private string $finalPrefix;

    public function __construct(int $jobId, array $cpfs, array $invalidCpfs = [])
    {
        $this->jobId = $jobId;
        $this->cpfs = array_values(array_unique($cpfs));
        $this->invalidCpfs = array_values(array_unique($invalidCpfs));

        $this->onQueue('default');

        // Configs
        $this->timeout    = (int) config('cltfacta.job.timeout_seconds', 18000); // 5h
        $this->disk       = (string) config('cltfacta.storage.reports_disk', 'local');
        $this->dirReports = (string) config('cltfacta.storage.dir_reports', 'clt-reports');
        $this->dirSpool   = (string) (config('cltfacta.storage.dir_spool') ?? 'clt-spool');
        $this->finalPrefix= (string) config('cltfacta.storage.final_prefix', 'clt-consulta');
    }

    public function handle(FactaApiService $facta): void
    {
        /** @var CltConsultJob $job */
        $job = CltConsultJob::query()->whereKey($this->jobId)->firstOrFail();

        // Idempotência básica: estados terminais
        if (in_array($job->status, ['concluido', 'falhou', 'cancelado'], true)) {
            Log::info("[CLT] Job {$this->jobId} ignorado – status atual: {$job->status}.");
            return;
        }

        // Cancelado/pausado antes de começar
        if ($this->isCancelled()) {
            Log::info("[CLT] Job {$this->jobId} já cancelado antes do início.");
            $this->deletePreview($job);
            return;
        }
        if ($this->isPaused()) {
            Log::info("[CLT] Job {$this->jobId} está pausado antes de iniciar processamento.");
            return;
        }

        // Diretórios base
        $this->initStorageDirs();
        $disk = Storage::disk($this->disk);

        // Detecta retomada
        $spoolExists = !empty($job->spool_path) && !empty($job->spool_cpfs_path)
            && $disk->exists($job->spool_path) && $disk->exists($job->spool_cpfs_path);

        $spoolPath  = $job->spool_path;
        $cpfsPath   = $job->spool_cpfs_path;
        $freshStart = false;

        if (!$spoolExists) {
            // Guard-rail: evita reinit sem payload (retomada com arrays vazios)
            $inputTotal = count($this->cpfs) + count($this->invalidCpfs);
            if ($inputTotal === 0) {
                Log::warning("[CLT] Job {$this->jobId} sem payload de CPFs e sem spool — evitando reinit para não zerar total/spool.");
                return;
            }

            // Primeiro start: cria spool e lista de CPFs
            [$spoolPath, $cpfsPath] = $this->initSpoolFiles($job);
            $freshStart = true;
        }

        // Entrando/retomando em progresso (sem zerar total_cpfs)
        if ($freshStart) {
            $inputTotal = count($this->cpfs) + count($this->invalidCpfs);
            $safeTotal  = $inputTotal > 0 ? $inputTotal : (int) ($job->total_cpfs ?? 0);

            $job->update([
                'status'           => 'em_progresso',
                'started_at'       => $job->started_at ?? Carbon::now(),
                'total_cpfs'       => $safeTotal, // nunca escrever 0 se já havia > 0
                'spool_path'       => $spoolPath,
                'spool_cpfs_path'  => $cpfsPath,
                'spool_bytes'      => $this->fileSizeSafe($this->disk, $spoolPath),
                'preview_dirty'    => false,
            ]);
        } else {
            $job->update([
                'status'      => 'em_progresso',
                'spool_bytes' => $this->fileSizeSafe($this->disk, $spoolPath),
            ]);
        }

        Log::info("[CLT] Job {$this->jobId} ".($freshStart ? 'iniciado' : 'retomado')." – total: {$job->total_cpfs}");

        // Params de execução
        $maxAttempts   = (int) config('cltfacta.job.max_attempts', 5);
        $retryDelay    = (int) config('cltfacta.job.retry_delay_seconds', 60);
        $chunkSize     = (int) config('cltfacta.job.chunk', 20);
        $minChunk      = max(1, (int) config('cltfacta.job.min_chunk', 5));
        $retryAfterCap = (int) config('cltfacta.job.retry_after_max', 120);

        // Define pendentes
        if ($freshStart) {
            $pendentes    = $this->cpfs;
            $invalidCount = count($this->invalidCpfs);

            // CPFs inválidos direto no spool
            if ($invalidCount > 0) {
                foreach ($this->invalidCpfs as $cpfInv) {
                    $row = $this->baseRow($cpfInv);
                    $row['numeroVinculos'] = 0;
                    $row['mensagem'] = 'CPF inválido (dígitos verificadores)';
                    $this->spoolAppend($job, $row);
                }
                $job->increment('fail_count', $invalidCount);
            }
        } else {
            // Resume: a partir do SPOOL
            [$pendentes, $doneCount] = $this->computePendingCpfs($disk->path($spoolPath), $disk->path($cpfsPath));
            Log::info("[CLT] Job {$this->jobId} retomado – já processados: {$doneCount}, pendentes: ".count($pendentes));
        }

        $lastError = [];
        $notFoundTotal = 0;

        try {
            if ($this->finishIfStopped($job)) return;

            $prevPendCount = count($pendentes);

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($this->finishIfStopped($job)) return;
                if (empty($pendentes)) break;

                Log::debug("[CLT] Job {$this->jobId} tentativa {$attempt} – pendentes: " . count($pendentes) . " – chunkSize={$chunkSize}");

                $toTry  = $pendentes;
                $chunks = array_chunk($toTry, max(1, $chunkSize));

                $seen429InAttempt    = 0;
                $retryAfterMax       = 0;
                $successThisAttempt  = 0;
                $semRespTotalAttempt = 0;
                $totalInAttempt      = 0;

                foreach ($chunks as $idx => $chunkCpfs) {
                    if ($this->finishIfStopped($job)) return;

                    Log::debug("[CLT] Job {$this->jobId} tentativa {$attempt} – disparando chunk #" . ($idx + 1) . " (" . count($chunkCpfs) . " CPFs)");

                    $batchResults = $facta->autorizaConsultaLote($chunkCpfs);

                    $stats = ['2xx' => 0, '401' => 0, '429' => 0, '5xx' => 0, 'outros' => 0, 'sem_resposta' => 0];
                    $successInChunk  = 0;
                    $notFoundInChunk = 0;
                    $failInChunkTerm = 0;

                    foreach ($chunkCpfs as $cpf) {
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
                        if     ($http === 200) $stats['2xx']++;
                        elseif ($http === 401) $stats['401']++;
                        elseif ($http === 429) { $stats['429']++; $seen429InAttempt++; }
                        elseif (is_int($http) && $http >= 500) $stats['5xx']++;
                        elseif ($http === null) $stats['sem_resposta']++;
                        else $stats['outros']++;

                        if (!empty($res['retry_after'])) {
                            $retryAfterMax = max($retryAfterMax, (int) $res['retry_after']);
                        }

                        if ($res['ok'] === true) {
                            $vinculos = $res['vinculos'] ?? [];
                            $total    = is_array($vinculos) ? count($vinculos) : 0;

                            if ($total > 0) {
                                foreach ($vinculos as $v) {
                                    $row = $this->baseRow($cpf);
                                    $row['numeroVinculos'] = $total;

                                    // Núcleo
                                    $row['elegivel'] = $v['elegivel'] ?? null;
                                    $row['valorMargemDisponivel'] = $v['valorMargemDisponivel'] ?? null;
                                    $row['valorMaximoPrestacao'] = $this->computeValorMaximoPrestacao($v['valorMargemDisponivel'] ?? null);
                                    $row['valorBaseMargem'] = $v['valorBaseMargem'] ?? null;
                                    $row['valorTotalVencimentos'] = $v['valorTotalVencimentos'] ?? null;

                                    // Vínculo/empregador
                                    $row['nomeEmpregador'] = $v['nomeEmpregador'] ?? null;
                                    $row['numeroInscricaoEmpregador'] = $v['numeroInscricaoEmpregador'] ?? null;
                                    $row['inscricaoEmpregador_descricao'] = $v['inscricaoEmpregador_descricao'] ?? null;
                                    $row['matricula'] = $v['matricula'] ?? null;
                                    $row['dataAdmissao'] = $v['dataAdmissao'] ?? null;
                                    $row['tempoAdmissaoMeses'] = $this->computeTempoAdmissaoMeses($v['dataAdmissao'] ?? null, $v['dataDesligamento'] ?? null);
                                    $row['dataDesligamento'] = $v['dataDesligamento'] ?? null;
                                    $row['codigoMotivoDesligamento'] = $v['codigoMotivoDesligamento'] ?? null;

                                    // Contexto
                                    $row['codigoCategoriaTrabalhador'] = $v['codigoCategoriaTrabalhador'] ?? null;
                                    $row['cbo_descricao'] = $v['cbo_descricao'] ?? null;
                                    $row['cnae_descricao'] = $v['cnae_descricao'] ?? null;
                                    $row['dataInicioAtividadeEmpregador'] = $v['dataInicioAtividadeEmpregador'] ?? null;

                                    // Alertas
                                    $row['possuiAlertas'] = $v['possuiAlertas'] ?? null;
                                    $row['qtdEmprestimosAtivosSuspensos'] = $v['qtdEmprestimosAtivosSuspensos'] ?? null;
                                    $row['emprestimosLegados'] = $v['emprestimosLegados'] ?? null;
                                    $row['pessoaExpostaPoliticamente_descricao'] = $v['pessoaExpostaPoliticamente_descricao'] ?? null;

                                    // Identificação
                                    $row['nome'] = $v['nome'] ?? null;
                                    $row['dataNascimento'] = $v['dataNascimento'] ?? null;
                                    $row['idade'] = $this->computeIdadeAnos($v['dataNascimento'] ?? null);
                                    $row['sexo_descricao'] = $v['sexo_descricao'] ?? null;

                                    // Meta/status (FACTA)
                                    $row['status_code'] = $v['status_code'] ?? null;
                                    $row['mensagem'] = $res['mensagem'] ?? 'OK';

                                    $this->spoolAppend($job, $row);
                                }
                            } else {
                                $row = $this->baseRow($cpf);
                                $row['numeroVinculos'] = 0;
                                $row['mensagem'] = $res['mensagem'] ?? 'Sem vínculos';
                                $this->spoolAppend($job, $row);
                            }

                            $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                            $successInChunk++;
                            $successThisAttempt++;
                        } else {
                            $msg = (string) ($res['mensagem'] ?? 'Falha na consulta');

                            if (!empty($res['not_found'])) {
                                $row = $this->baseRow($cpf);
                                $row['numeroVinculos'] = 0;
                                $row['mensagem'] = $msg;
                                $this->spoolAppend($job, $row);

                                $notFoundInChunk++;
                                $notFoundTotal++;

                                $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                                continue;
                            }

                            if (isset($res['retriable']) && $res['retriable'] === false) {
                                $row = $this->baseRow($cpf);
                                $row['numeroVinculos'] = 0;
                                $row['mensagem'] = $msg;
                                $this->spoolAppend($job, $row);

                                $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                                $failInChunkTerm++;
                            } else {
                                $lastError[$cpf] = $msg;
                            }
                        }
                    }

                    if ($successInChunk > 0)   $job->increment('success_count', $successInChunk);
                    if ($notFoundInChunk > 0)  $job->increment('not_found_count', $notFoundInChunk);
                    if ($failInChunkTerm > 0)  $job->increment('fail_count', $failInChunkTerm);

                    Log::debug("[CLT] Job {$this->jobId} tentativa {$attempt} – stats chunk #" . ($idx + 1) . ": " . json_encode($stats));

                    $semRespTotalAttempt += $stats['sem_resposta'];
                    $totalInAttempt      += count($chunkCpfs);
                }

                if ($this->finishIfStopped($job)) return;

                // Ajustes de ritmo
                if ($seen429InAttempt > 0 && $chunkSize > $minChunk) {
                    $ratio429 = count($toTry) > 0 ? $seen429InAttempt / count($toTry) : 0.0;
                    if ($ratio429 >= 0.20) {
                        $old = $chunkSize;
                        $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                        Log::warning("[CLT] Job {$this->jobId} – muitos 429 (ratio=" . round($ratio429, 2) . "). Reduzindo chunk {$old} → {$chunkSize}.");
                    }
                }

                $semRespRatio = $totalInAttempt > 0 ? ($semRespTotalAttempt / $totalInAttempt) : 0.0;
                if ($semRespRatio >= 0.50 && $chunkSize > $minChunk) {
                    $old = $chunkSize;
                    $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                    Log::warning("[CLT] Job {$this->jobId} – muitos sem_resposta (ratio=" . round($semRespRatio, 2) . "). Reduzindo chunk {$old} → {$chunkSize}.");
                }

                if (!empty($pendentes) && $attempt < $maxAttempts) {
                    if ($this->finishIfStopped($job)) return;

                    $baseRetryAfter = $retryAfterMax > 0 ? min($retryAfterMax, $retryAfterCap) : 0;
                    $base = max(1, $retryDelay, $baseRetryAfter);

                    $sleepFactor = 1.0;
                    if      ($semRespRatio >= 0.90) $sleepFactor = 2.0;
                    elseif  ($semRespRatio >= 0.50) $sleepFactor = 1.5;

                    $withFactor = (int) ceil($base * $sleepFactor);
                    $jitter    = random_int(0, (int) max(1, ceil($withFactor * 0.15)));
                    $sleepSecs = $withFactor + $jitter;

                    Log::debug("[CLT] Job {$this->jobId} – dormindo {$sleepSecs}s (cooperativo).");
                    if ($this->cooperativeSleep($sleepSecs, $job)) return;
                }

                $currPendCount = count($pendentes);
                if ($currPendCount === $prevPendCount && $successThisAttempt === 0 && !empty($pendentes)) {
                    Log::warning("[CLT] Job {$this->jobId} – sem progresso na tentativa {$attempt}.");
                }
                $prevPendCount = $currPendCount;
            }

            // Fecha pendentes restantes (após tentativas)
            if (!empty($pendentes)) {
                foreach ($pendentes as $cpf) {
                    if ($this->finishIfStopped($job)) return;
                    $row = $this->baseRow($cpf);
                    $row['numeroVinculos'] = 0;
                    $row['mensagem'] = $lastError[$cpf] ?? 'Não foi possível consultar após múltiplas tentativas';
                    $this->spoolAppend($job, $row);
                }
                $job->increment('fail_count', count($pendentes));
            }

            // Finalização
            if ($this->isCancelled()) {
                $job->update(['finished_at' => Carbon::now()]);
                $this->deletePreview($job);
                $this->cleanupSpool($job);
                Log::info("[CLT] Job {$this->jobId} cancelado na finalização (spool removido).");
                return;
            }
            if ($this->isPaused()) {
                Log::info("[CLT] Job {$this->jobId} pausado na finalização de ciclo – saindo sem limpar spool/prévia.");
                return;
            }

            $finalOk = $this->generateFinalFromSpool($job);
            if ($finalOk) {
                $this->cleanupSpool($job);

                $job->update([
                    'status'      => 'concluido',
                    'finished_at' => Carbon::now(),
                ]);

                $this->deletePreview($job);

                $job->refresh();
                Log::info("[CLT] Job {$this->jobId} concluído – sucesso: {$job->success_count}, não encontrado: {$job->not_found_count}, falha: {$job->fail_count}");
                return;
            }

            $job->update([
                'status'      => 'falhou',
                'finished_at' => Carbon::now(),
            ]);
            $this->deletePreview($job);
            Log::error("[CLT] Job {$this->jobId} não conseguiu gerar FINAL (mantido spool para análise).");
        } catch (Throwable $e) {
            $finalOk = false;
            try {
                $finalOk = $this->generateFinalFromSpool($job);
            } catch (\Throwable $e2) {
                Log::warning("[CLT] Job {$this->jobId} falhou e não conseguiu gerar FINAL: " . $e2->getMessage());
            }
            if ($finalOk) {
                $this->cleanupSpool($job);
            }

            $job->update([
                'status'      => 'falhou',
                'finished_at' => Carbon::now(),
            ]);
            $this->deletePreview($job);
            Log::error("[CLT] Job {$this->jobId} falhou: " . $e->getMessage());
        }
    }

    /** ----------------------- Helpers ----------------------- */

    private function getStatus(): ?string
    {
        return DB::table('clt_consult_jobs')->where('id', $this->jobId)->value('status');
    }

    private function isCancelled(): bool
    {
        return $this->getStatus() === 'cancelado';
    }

    private function isPaused(): bool
    {
        return $this->getStatus() === 'pausado';
    }

    private function finishIfStopped(CltConsultJob $job): bool
    {
        $status = $this->getStatus();

        if ($status === 'cancelado') {
            $job->update(['finished_at' => Carbon::now()]);
            $this->deletePreview($job);
            $this->cleanupSpool($job);
            Log::info("[CLT] Job {$this->jobId} interrompido por cancelamento (spool removido).");
            return true;
        }

        if ($status === 'pausado') {
            Log::info("[CLT] Job {$this->jobId} detectou pausa – saindo sem limpar nada.");
            return true;
        }

        // Auto-sync para 'em_progresso' (sem sobrescrever estados finais/pausa)
        if ($status !== 'em_progresso') {
            $updated = DB::table('clt_consult_jobs')
                ->where('id', $this->jobId)
                ->whereNotIn('status', ['cancelado', 'pausado', 'concluido', 'falhou'])
                ->update(['status' => 'em_progresso']);

            if ($updated) {
                $job->status = 'em_progresso';
                Log::info("[CLT] Job {$this->jobId} sincronizado para 'em_progresso' (auto).");
            }
        }

        return false;
    }

    private function baseRow(string $cpf): array
    {
        $row = [];
        foreach (\App\Exports\CltConsultExport::COLS as $col) {
            $row[$col] = null;
        }
        $row['cpf'] = $cpf;
        return $row;
    }

    private function initStorageDirs(): void
    {
        $disk = Storage::disk($this->disk);
        foreach ([$this->dirReports, $this->dirSpool] as $dir) {
            if (!$disk->exists($dir)) {
                $disk->makeDirectory($dir);
            }
        }
    }

    private function initSpoolFiles(CltConsultJob $job): array
    {
        $disk = Storage::disk($this->disk);

        $spoolName = "{$this->finalPrefix}_{$this->jobId}.spool.csv";
        $cpfsName  = "{$this->finalPrefix}_{$this->jobId}.cpfs.txt";

        $spoolPath = "{$this->dirSpool}/{$spoolName}";
        $cpfsPath  = "{$this->dirSpool}/{$cpfsName}";

        // Spool CSV com cabeçalho
        $fp = fopen($disk->path($spoolPath), 'c+');
        if ($fp === false) {
            throw new \RuntimeException("Não foi possível criar spool em {$spoolPath}");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fputcsv($fp, \App\Exports\CltConsultExport::COLS, ';');
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        // Arquivo de CPFs originais (válidos + inválidos)
        $allCpfs = array_values(array_unique(array_merge($this->cpfs, $this->invalidCpfs)));
        $fp2 = fopen($disk->path($cpfsPath), 'c+');
        if ($fp2 === false) {
            throw new \RuntimeException("Não foi possível criar cpfs em {$cpfsPath}");
        }
        try {
            if (flock($fp2, LOCK_EX)) {
                ftruncate($fp2, 0);
                foreach ($allCpfs as $cpf) {
                    fwrite($fp2, $cpf . "\n");
                }
                fflush($fp2);
                flock($fp2, LOCK_UN);
            }
        } finally {
            fclose($fp2);
        }

        return [$spoolPath, $cpfsPath];
    }

    private function computePendingCpfs(string $spoolReal, string $cpfsReal): array
    {
        $done = [];

        // Marca CPFs já presentes no spool
        $fh = fopen($spoolReal, 'r');
        if ($fh !== false) {
            try {
                flock($fh, LOCK_SH);
                $header = fgetcsv($fh, 0, ';');
                while (($data = fgetcsv($fh, 0, ';')) !== false) {
                    $cpf = $data[0] ?? null; // primeira coluna é 'cpf' em COLS
                    if ($cpf !== null && $cpf !== '') {
                        $done[(string)$cpf] = true;
                    }
                }
            } finally {
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        }

        $pendentes = [];
        $doneCount = count($done);

        // Lê lista original e pega os que não estão no spool
        $fh2 = fopen($cpfsReal, 'r');
        if ($fh2 !== false) {
            try {
                flock($fh2, LOCK_SH);
                while (($line = fgets($fh2)) !== false) {
                    $cpf = trim($line);
                    if ($cpf === '' || isset($done[$cpf])) continue;
                    $pendentes[] = $cpf;
                }
            } finally {
                flock($fh2, LOCK_UN);
                fclose($fh2);
            }
        }

        return [$pendentes, $doneCount];
    }

    private function spoolAppend(CltConsultJob $job, array $row): void
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
                foreach (\App\Exports\CltConsultExport::COLS as $key) {
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

        DB::table('clt_consult_jobs')
            ->where('id', $job->id)
            ->update([
                'spool_bytes'   => $bytes,
                'preview_dirty' => true,
            ]);

        $job->spool_bytes = $bytes;
        $job->preview_dirty = true;
    }

    private function generateFinalFromSpool(CltConsultJob $job): bool
    {
        try {
            $disk = Storage::disk($this->disk);
            $spoolPath = $job->spool_path;

            if (!$spoolPath || !$disk->exists($spoolPath)) {
                Log::warning("[CLT] Job {$this->jobId} – spool ausente, não há FINAL para gerar.");
                return false;
            }

            $ts      = Carbon::now()->format('Ymd_His');
            $fileName= "{$this->finalPrefix}_{$this->jobId}_{$ts}.xlsx";
            $tmpName = "{$this->finalPrefix}_{$this->jobId}_{$ts}.tmp.xlsx";
            $path    = "{$this->dirReports}/{$fileName}";
            $tmpPath = "{$this->dirReports}/{$tmpName}";

            $export = \App\Exports\CltConsultExport::fromCsv($disk->path($spoolPath));
            Excel::store($export, $tmpPath, $this->disk);
            $disk->move($tmpPath, $path);

            if (!$disk->exists($path)) {
                Log::error("[CLT] Job {$this->jobId} – FINAL não encontrado após move: {$path}");
                return false;
            }

            $job->update([
                'file_disk' => $this->disk,
                'file_path' => $path,
                'file_name' => $fileName,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error("[CLT] Job {$this->jobId} – erro gerando FINAL: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Dorme de forma cooperativa verificando pausa/cancelamento.
     * @return bool true se abortou por pausa/cancelamento; false se dormiu até o fim.
     */
    private function cooperativeSleep(int $seconds, CltConsultJob $job): bool
    {
        $remaining = max(0, $seconds);
        $tick = 1;

        while ($remaining > 0) {
            if ($this->finishIfStopped($job)) {
                return true;
            }
            $slice = min($tick, $remaining);
            sleep($slice);
            $remaining -= $slice;
        }

        return $this->finishIfStopped($job);
    }

    private function cleanupSpool(CltConsultJob $job): void
    {
        try {
            $disk = Storage::disk($this->disk);

            foreach (['spool_path', 'spool_cpfs_path'] as $field) {
                $p = $job->{$field} ?? null;
                if ($p && $disk->exists($p)) {
                    try {
                        $disk->delete($p);
                    } catch (\Throwable $e) {
                        Log::warning("[CLT] Job {$this->jobId} – falha ao deletar {$field}: " . $e->getMessage());
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

    private function deletePreview(CltConsultJob $job): void
    {
        $jobId = $job->id;

        try {
            $lock = Cache::lock("clt_preview_{$jobId}", 30);

            try {
                $lock->block(5);
            } catch (Throwable $e) {
                // segue sem lock se não conseguir no prazo
            }

            $row = DB::table('clt_consult_jobs')
                ->select('preview_disk', 'preview_path')
                ->where('id', $jobId)
                ->first();

            $diskName = $row->preview_disk ?? null;
            $path     = $row->preview_path ?? null;

            if ($diskName && $path) {
                try {
                    $disk = Storage::disk($diskName);
                    if ($disk->exists($path)) {
                        $disk->delete($path);
                    }
                } catch (Throwable $e) {
                    Log::warning("[CLT] Job {$this->jobId} falha ao apagar arquivo de prévia: " . $e->getMessage());
                }
            }

            optional($lock)->release();

        } catch (Throwable $e) {
            Log::warning("[CLT] Job {$this->jobId} falha ao coordenar lock da prévia: " . $e->getMessage());
        } finally {
            DB::table('clt_consult_jobs')
                ->where('id', $jobId)
                ->update([
                    'preview_disk'       => null,
                    'preview_path'       => null,
                    'preview_name'       => null,
                    'preview_updated_at' => null,
                    'preview_dirty'      => false,
                ]);

            $job->preview_disk = null;
            $job->preview_path = null;
            $job->preview_name = null;
            $job->preview_updated_at = null;
            $job->preview_dirty = false;
        }
    }

    private function computeValorMaximoPrestacao($valorMargemDisponivel): ?string
    {
        $f = $this->toFloatPtBr($valorMargemDisponivel);
        if ($f === null) return null;
        $calc = $f * 0.70;
        return $this->formatPtBrMoney($calc);
    }

    private function computeIdadeAnos(?string $dataNascimento): ?int
    {
        $d = $this->parseDateBr($dataNascimento);
        return $d ? $d->diffInYears(Carbon::now()) : null;
    }

    private function computeTempoAdmissaoMeses(?string $dataAdmissao, ?string $dataDesligamento): ?int
    {
        $ini = $this->parseDateBr($dataAdmissao);
        if (!$ini) return null;
        $fim = $this->parseDateBr($dataDesligamento) ?? Carbon::now();
        if ($fim->lt($ini)) return 0;
        return $ini->diffInMonths($fim);
    }

    private function parseDateBr(?string $s): ?Carbon
    {
        if (!$s) return null;
        try {
            return Carbon::createFromFormat('d/m/Y', trim($s))->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function toFloatPtBr($v): ?float
    {
        if ($v === null) return null;
        if (is_numeric($v)) return (float) $v;
        $s = preg_replace('/[^\d,\-\.]/', '', (string) $v);
        if ($s === '' || $s === '-') return null;
        $s = str_replace(['.', ','], ['', '.'], $s);
        if (!is_numeric($s)) return null;
        return (float) $s;
    }

    private function formatPtBrMoney(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }

    private function fileSizeSafe(string $diskName, string $relativePath): int
    {
        try {
            $disk = Storage::disk($diskName);
            return $disk->exists($relativePath) ? (int) $disk->size($relativePath) : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
