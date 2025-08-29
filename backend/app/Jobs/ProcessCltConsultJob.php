<?php

namespace App\Jobs;

use App\Exports\CltConsultExport;
use App\Models\CltConsultJob;
use App\Services\FactaApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class ProcessCltConsultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Timeout por job (segundos) — agora vem de config('cltfacta.job.timeout_seconds'). */
    public int $timeout;

    /** Intervalo em segundos para gerar a prévia (config). */
    private int $previewIntervalSeconds;

    private int $jobId;
    private int $userId;
    private string $title;

    /** @var string[] CPFs válidos (11 dígitos) */
    private array $cpfs;

    /** @var string[] CPFs inválidos (11 dígitos mas DV inválido) */
    private array $invalidCpfs;

    public function __construct(int $jobId, int $userId, string $title, array $cpfs, array $invalidCpfs = [])
    {
        $this->jobId = $jobId;
        $this->userId = $userId;
        $this->title = $title;
        $this->cpfs = array_values(array_unique($cpfs));
        $this->invalidCpfs = array_values(array_unique($invalidCpfs));

        $this->onQueue('default');

        // Config
        $this->timeout = (int) config('cltfacta.job.timeout_seconds', 18000);
        $this->previewIntervalSeconds = (int) config('cltfacta.job.preview_interval_seconds', 60);
    }

    public function handle(FactaApiService $facta): void
    {
        /** @var CltConsultJob $job */
        $job = CltConsultJob::query()->whereKey($this->jobId)->firstOrFail();

        if ($this->isCancelled()) {
            Log::info("[CLT] Job {$this->jobId} já cancelado antes do início.");
            $this->deletePreview($job);
            return;
        }

        $job->update([
            'status' => 'em_progresso',
            'started_at' => Carbon::now(),
            'total_cpfs' => count($this->cpfs) + count($this->invalidCpfs),
        ]);

        // Params de execução — todos via config
        $maxAttempts = (int) config('cltfacta.job.max_attempts', 5);
        $retryDelay = (int) config('cltfacta.job.retry_delay_seconds', 60);
        $chunkSize = (int) config('cltfacta.job.chunk', 20);
        $minChunk = max(1, (int) config('cltfacta.job.min_chunk', 5));
        $retryAfterCap = (int) config('cltfacta.job.retry_after_max', 120);

        $rows = [];
        $successMap = [];
        $lastError = [];
        $terminalFailures = [];
        $pendentes = $this->cpfs;
        $invalidCount = count($this->invalidCpfs);
        $lastPreviewTime = Carbon::now();

        // 👇 novo acumulador para "não encontrado"
        $notFoundTotal = 0;

        try {
            // 1) Linhas para CPFs com DV inválido
            foreach ($this->invalidCpfs as $cpfInv) {
                $row = $this->baseRow($cpfInv);
                $row['numeroVinculos'] = 0;
                $row['mensagem'] = 'CPF inválido (dígitos verificadores)';
                $rows[] = $row;
            }

            // 2) Tentativas com teimosinha
            $prevPendCount = count($pendentes);

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($this->finishIfCancelled($job))
                    return;
                if (empty($pendentes))
                    break;

                Log::debug("[CLT] Job {$this->jobId} tentativa {$attempt} – pendentes: " . count($pendentes) . " – chunkSize={$chunkSize}");

                $toTry = $pendentes;
                $chunks = array_chunk($toTry, max(1, $chunkSize));

                $seen429InAttempt = 0;
                $retryAfterMax = 0;
                $successThisAttempt = 0;
                $semRespTotalAttempt = 0;
                $totalInAttempt = 0;

                foreach ($chunks as $idx => $chunkCpfs) {
                    if ($this->finishIfCancelled($job))
                        return;

                    Log::debug("[CLT] Job {$this->jobId} tentativa {$attempt} – disparando chunk #" . ($idx + 1) . " (" . count($chunkCpfs) . " CPFs)");

                    $batchResults = $facta->autorizaConsultaLote($chunkCpfs);

                    // Telemetria do chunk
                    $stats = [
                        '2xx' => 0,
                        '401' => 0,
                        '429' => 0,
                        '5xx' => 0,
                        'outros' => 0,
                        'sem_resposta' => 0
                    ];
                    $successInChunk = 0;
                    $notFoundInChunk = 0; // 👈 novo

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
                        if ($http === 200)
                            $stats['2xx']++;
                        elseif ($http === 401)
                            $stats['401']++;
                        elseif ($http === 429) {
                            $stats['429']++;
                            $seen429InAttempt++;
                        } elseif (is_int($http) && $http >= 500)
                            $stats['5xx']++;
                        elseif ($http === null)
                            $stats['sem_resposta']++;
                        else
                            $stats['outros']++;

                        if (!empty($res['retry_after'])) {
                            $retryAfterMax = max($retryAfterMax, (int) $res['retry_after']);
                        }

                        if ($res['ok'] === true) {
                            $vinculos = $res['vinculos'] ?? [];
                            $total = is_array($vinculos) ? count($vinculos) : 0;

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

                                    // Meta/status (da FACTA, não HTTP)
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

                            $successMap[$cpf] = true;
                            $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                            $successInChunk++;
                            $successThisAttempt++;

                        } else {
                            $msg = (string) ($res['mensagem'] ?? 'Falha na consulta');

                            if (!empty($res['not_found'])) {
                                // 👇 contabiliza "não encontrado"
                                $row = $this->baseRow($cpf);
                                $row['numeroVinculos'] = 0;
                                $row['mensagem'] = $msg;
                                $rows[] = $row;

                                $notFoundInChunk++;
                                $notFoundTotal++;

                                $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                                continue;
                            }

                            if (isset($res['retriable']) && $res['retriable'] === false) {
                                $terminalFailures[$cpf] = $msg;
                                $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                            } else {
                                $lastError[$cpf] = $msg;
                            }
                        }
                    }

                    if ($successInChunk > 0) {
                        $job->increment('success_count', $successInChunk);
                    }
                    if ($notFoundInChunk > 0) {
                        $job->increment('not_found_count', $notFoundInChunk); // 👈 feedback em tempo real
                    }

                    Log::debug("[CLT] Job {$this->jobId} tentativa {$attempt} – stats chunk #" . ($idx + 1) . ": " . json_encode($stats));

                    // Acumula tentativa
                    $semRespTotalAttempt += $stats['sem_resposta'];
                    $totalInAttempt += count($chunkCpfs);

                    // PRÉVIA por tempo
                    if ($lastPreviewTime->diffInSeconds(Carbon::now()) >= $this->previewIntervalSeconds) {
                        if ($this->finishIfCancelled($job))
                            return;
                        $this->generatePreview($job, $rows, $pendentes, $terminalFailures);
                        $lastPreviewTime = Carbon::now();
                    }
                }

                if ($this->finishIfCancelled($job))
                    return;

                // PRÉVIA no fim da tentativa
                $this->generatePreview($job, $rows, $pendentes, $terminalFailures);
                $lastPreviewTime = Carbon::now();

                // --- Ajustes de chunk/backoff (inalterados)
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
                    if ($this->finishIfCancelled($job))
                        return;

                    $baseRetryAfter = $retryAfterMax > 0 ? min($retryAfterMax, $retryAfterCap) : 0;
                    $base = max(1, $retryDelay, $baseRetryAfter);

                    $sleepFactor = 1.0;
                    if ($semRespRatio >= 0.90)
                        $sleepFactor = 2.0;
                    elseif ($semRespRatio >= 0.50)
                        $sleepFactor = 1.5;

                    $withFactor = (int) ceil($base * $sleepFactor);
                    $jitter = random_int(0, (int) max(1, ceil($withFactor * 0.15)));
                    $sleepSecs = $withFactor + $jitter;

                    Log::debug("[CLT] Job {$this->jobId} – dormindo {$sleepSecs}s (base={$base}, factor={$sleepFactor}, jitter={$jitter}, retryAfterMax={$retryAfterMax}, semRespRatio=" . round($semRespRatio, 2) . ").");
                    sleep($sleepSecs);
                }

                // Stall detector
                $currPendCount = count($pendentes);
                if ($currPendCount === $prevPendCount && $successThisAttempt === 0 && !empty($pendentes)) {
                    Log::warning("[CLT] Job {$this->jobId} – sem progresso na tentativa {$attempt}. Mantendo backoff e aguardando próximos retries.");
                }
                $prevPendCount = $currPendCount;
            }

            // 3) Falhas não-retriáveis
            foreach ($terminalFailures as $cpf => $msg) {
                if ($this->finishIfCancelled($job))
                    return;
                $row = $this->baseRow($cpf);
                $row['numeroVinculos'] = 0;
                $row['mensagem'] = $msg;
                $rows[] = $row;
            }

            // 4) Falhas após teimosinha
            foreach ($pendentes as $cpf) {
                if ($this->finishIfCancelled($job))
                    return;
                $row = $this->baseRow($cpf);
                $row['numeroVinculos'] = 0;
                $row['mensagem'] = $lastError[$cpf] ?? 'Não foi possível consultar após múltiplas tentativas';
                $rows[] = $row;
            }

            if ($this->isCancelled()) {
                $job->update(['finished_at' => Carbon::now()]);
                $this->deletePreview($job);
                Log::info("[CLT] Job {$this->jobId} cancelado na finalização.");
                return;
            }

            $successCount = count($successMap);
            $failCount = $invalidCount + count($terminalFailures) + count($pendentes);

            // Excel FINAL (escrita atômica)
            $disk = (string) config('cltfacta.storage.reports_disk', 'public');
            $dirReports = (string) config('cltfacta.storage.dir_reports', 'clt-reports');
            $finalPrefix = (string) config('cltfacta.storage.final_prefix', 'clt-consulta');

            $ts = Carbon::now()->format('Ymd_His');
            $fileName = "{$finalPrefix}_{$this->jobId}_{$ts}.xlsx";
            $tmpName = "{$finalPrefix}_{$this->jobId}_{$ts}.tmp.xlsx";
            $path = "{$dirReports}/{$fileName}";
            $tmpPath = "{$dirReports}/{$tmpName}";

            Excel::store(new CltConsultExport($rows), $tmpPath, $disk);
            Storage::disk($disk)->move($tmpPath, $path);

            $job->update([
                'success_count' => $successCount, // sobrescreve para ficar exato
                'fail_count' => $failCount,
                'not_found_count' => $notFoundTotal, // 👈 grava total de "não encontrado"
                'file_disk' => $disk,
                'file_path' => $path,
                'file_name' => $fileName,
                'status' => 'concluido',
                'finished_at' => Carbon::now(),
            ]);
            $this->deletePreview($job);
            Log::info("[CLT] Job {$this->jobId} concluído – sucesso: {$successCount}, não encontrado: {$notFoundTotal}, falha: {$failCount}");

        } catch (Throwable $e) {
            Log::error("[CLT] Job {$this->jobId} falhou: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $job->update([
                'status' => 'falhou',
                'finished_at' => Carbon::now(),
            ]);
            $this->deletePreview($job);
        }
    }

    /** ----------------------- Helpers ----------------------- */

    private function isCancelled(): bool
    {
        $status = DB::table('clt_consult_jobs')->where('id', $this->jobId)->value('status');
        return $status === 'cancelado';
    }

    private function finishIfCancelled(CltConsultJob $job): bool
    {
        if ($this->isCancelled()) {
            $job->update(['finished_at' => Carbon::now()]);
            $this->deletePreview($job);
            Log::info("[CLT] Job {$this->jobId} interrompido por cancelamento.");
            return true;
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

    private function generatePreview(CltConsultJob $job, array $rows, array $pendentes, array $terminalFailures): void
    {
        try {
            $rowsPreview = $rows;

            foreach ($terminalFailures as $cpf => $msg) {
                $rowsPreview[] = array_merge($this->baseRow($cpf), [
                    'numeroVinculos' => 0,
                    'mensagem' => $msg,
                ]);
            }

            foreach ($pendentes as $cpf) {
                $rowsPreview[] = array_merge($this->baseRow($cpf), [
                    'numeroVinculos' => 0,
                    'mensagem' => 'Em andamento',
                ]);
            }

            $disk = (string) config('cltfacta.storage.reports_disk', 'public');
            $dirPreviews = (string) config('cltfacta.storage.dir_previews', 'clt-previews');
            $finalPrefix = (string) config('cltfacta.storage.final_prefix', 'clt-consulta');
            $previewSuffix = (string) config('cltfacta.storage.preview_suffix', 'preview');

            $fileName = "{$finalPrefix}_{$this->jobId}_{$previewSuffix}.xlsx";
            $tmpName = "{$finalPrefix}_{$this->jobId}_{$previewSuffix}.tmp.xlsx";
            $path = "{$dirPreviews}/{$fileName}";
            $tmpPath = "{$dirPreviews}/{$tmpName}";

            Excel::store(new CltConsultExport($rowsPreview), $tmpPath, $disk);
            Storage::disk($disk)->move($tmpPath, $path);

            $job->update([
                'preview_disk' => $disk,
                'preview_path' => $path,
                'preview_name' => $fileName,
                'preview_updated_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning("[CLT] Job {$this->jobId} falha ao gerar prévia: " . $e->getMessage());
        }
    }

    private function deletePreview(CltConsultJob $job): void
    {
        try {
            if ($job->preview_disk && $job->preview_path) {
                $disk = Storage::disk($job->preview_disk);
                if ($disk->exists($job->preview_path)) {
                    $disk->delete($job->preview_path);
                }
            }
        } catch (Throwable $e) {
            Log::warning("[CLT] Job {$this->jobId} falha ao apagar prévia: " . $e->getMessage());
        } finally {
            $job->updateQuietly([
                'preview_disk' => null,
                'preview_path' => null,
                'preview_name' => null,
                'preview_updated_at' => null,
            ]);
        }
    }

    private function computeValorMaximoPrestacao($valorMargemDisponivel): ?string
    {
        $f = $this->toFloatPtBr($valorMargemDisponivel);
        if ($f === null)
            return null;
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
        if (!$ini)
            return null;
        $fim = $this->parseDateBr($dataDesligamento) ?? Carbon::now();
        if ($fim->lt($ini))
            return 0;
        return $ini->diffInMonths($fim);
    }

    private function parseDateBr(?string $s): ?Carbon
    {
        if (!$s)
            return null;
        try {
            return \Illuminate\Support\Carbon::createFromFormat('d/m/Y', trim($s))->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function toFloatPtBr($v): ?float
    {
        if ($v === null)
            return null;
        if (is_numeric($v))
            return (float) $v;
        $s = preg_replace('/[^\d,\-\.]/', '', (string) $v);
        if ($s === '' || $s === '-')
            return null;
        $s = str_replace(['.', ','], ['', '.'], $s);
        if (!is_numeric($s))
            return null;
        return (float) $s;
    }

    private function formatPtBrMoney(float $v): string
    {
        return number_format($v, 2, ',', '.');
    }
}
