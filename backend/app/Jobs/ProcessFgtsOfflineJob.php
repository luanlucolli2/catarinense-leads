<?php

namespace App\Jobs;

use App\Exports\FgtsOfflineExport;
use App\Models\FgtsOfflineJob;
use App\Services\FactaOfflineApiService;
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

class ProcessFgtsOfflineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Timeout por job (segundos) — via config('fgts_off.job.timeout_seconds'). */
    public int $timeout;

    /** Intervalo em segundos para gerar a prévia — via config('fgts_off.job.preview_interval_seconds'). */
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
        $this->jobId       = $jobId;
        $this->userId      = $userId;
        $this->title       = $title;
        $this->cpfs        = array_values(array_unique($cpfs));
        $this->invalidCpfs = array_values(array_unique($invalidCpfs));

        $this->onQueue('default');

        // Configs padrão (podem ser refinadas depois em config/fgts_off.php)
        $this->timeout                = (int) config('fgts_off.job.timeout_seconds', 18000); // 5h
        $this->previewIntervalSeconds = (int) config('fgts_off.job.preview_interval_seconds', 60);
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

        $job->update([
            'status'     => 'em_progresso',
            'started_at' => Carbon::now(),
            'total_cpfs' => count($this->cpfs) + count($this->invalidCpfs),
        ]);

        // Knobs de execução
        $maxAttempts   = (int) config('fgts_off.job.max_attempts', 5);
        $retryDelay    = (int) config('fgts_off.job.retry_delay_seconds', 30);
        $chunkSize     = (int) config('fgts_off.job.chunk', 6); // valor confortável
        $minChunk      = max(1, (int) config('fgts_off.job.min_chunk', 2));
        $retryAfterCap = (int) config('fgts_off.job.retry_after_max', 120);

        $rows               = [];
        $authorizedMap      = []; // cpf => true (autorizado)
        $notAuthorizedMap   = []; // cpf => true (não autorizado)
        $lastError          = [];
        $terminalFailures   = [];
        $pendentes          = $this->cpfs;
        $invalidCount       = count($this->invalidCpfs);
        $lastPreviewTime    = Carbon::now();

        try {
            // 1) CPFs com DV inválido → linha + falha "ao vivo"
            foreach ($this->invalidCpfs as $cpfInv) {
                $row = $this->baseRow($cpfInv);
                $row['autorizado']    = null;
                $row['autorizadoAte'] = null;
                $row['mensagem']      = 'CPF inválido (dígitos verificadores)';
                $row['status']        = null;
                $row['consultadoEm']  = $this->nowBrString();
                $rows[] = $row;
            }
            if ($invalidCount > 0) {
                $job->increment('fail_count', $invalidCount);
            }

            // 2) Tentativas com "teimosinha"
            $prevPendCount = count($pendentes);

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($this->finishIfCancelled($job)) return;
                if (empty($pendentes)) break;

                Log::debug("[FGTS-OFF] Job {$this->jobId} tentativa {$attempt} – pendentes: ".count($pendentes)." – chunkSize={$chunkSize}");

                $toTry  = $pendentes;
                $chunks = array_chunk($toTry, max(1, $chunkSize));

                // Telemetria e controle de backoff
                $seen429InAttempt    = 0;
                $retryAfterMax       = 0;
                $authorizedThisAtt   = 0;
                $notAuthorizedThisAtt= 0;
                $failsThisAtt        = 0;
                $successThisAttempt  = 0;
                $semRespTotalAttempt = 0;
                $totalInAttempt      = 0;

                foreach ($chunks as $idx => $chunkCpfs) {
                    if ($this->finishIfCancelled($job)) return;

                    Log::debug("[FGTS-OFF] Job {$this->jobId} tentativa {$attempt} – processando chunk #".($idx+1)." (".count($chunkCpfs)." CPFs)");

                    // Lote (o service pode ser seq. ou paralelizar internamente)
                    $batchResults = $api->consultaCpfLote($chunkCpfs);

                    // Stats por chunk (HTTP)
                    $stats = ['2xx'=>0,'401'=>0,'429'=>0,'5xx'=>0,'outros'=>0,'sem_resposta'=>0];
                    $authorizedInChunk    = 0;
                    $notAuthorizedInChunk = 0;
                    $terminalFailsInChunk = 0;

                    foreach ($chunkCpfs as $cpf) {
                        $res = $batchResults[$cpf] ?? [
                            'ok'               => false,
                            'mensagem'         => 'Sem resposta do serviço',
                            'authorized'       => null,
                            'authorized_until' => null,
                            'retriable'        => true,
                            'http_status'      => null,
                            'retry_after'      => null,
                            'consultado_at'    => $this->nowBrString(),
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

                        if (!empty($res['ok'])) {
                            // Sucesso lógico → separar autorizado vs não autorizado
                            $row = $this->baseRow($cpf);
                            $row['autorizado']    = $res['authorized'] ?? null;
                            $row['autorizadoAte'] = $res['authorized_until'] ?? null;
                            $row['mensagem']      = $res['mensagem'] ?? null;
                            $row['status']        = $res['http_status'] ?? 200;
                            $row['consultadoEm']  = $res['consultado_at'] ?? $this->nowBrString();
                            $rows[] = $row;

                            if ($res['authorized'] === true) {
                                $authorizedMap[$cpf] = true;
                                $authorizedInChunk++;
                                $authorizedThisAtt++;
                            } else { // false
                                $notAuthorizedMap[$cpf] = true;
                                $notAuthorizedInChunk++;
                                $notAuthorizedThisAtt++;
                            }

                            // remove dos pendentes
                            $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                            $successThisAttempt++;
                        } else {
                            // Falha
                            $msg       = (string) ($res['mensagem'] ?? 'Falha na consulta');
                            $retriable = $res['retriable'] ?? true;

                            if ($retriable === false) {
                                // terminal
                                $terminalFailures[$cpf] = $msg;
                                $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                                $terminalFailsInChunk++;
                                $failsThisAtt++;
                            } else {
                                // manter para próxima tentativa
                                $lastError[$cpf] = $msg;
                            }
                        }
                    }

                    // 🎯 Atualiza contadores "ao vivo"
                    if ($authorizedInChunk > 0) {
                        $job->increment('success_count', $authorizedInChunk);
                    }
                    if ($notAuthorizedInChunk > 0) {
                        $job->increment('not_authorized_count', $notAuthorizedInChunk);
                    }
                    if ($terminalFailsInChunk > 0) {
                        $job->increment('fail_count', $terminalFailsInChunk);
                    }

                    Log::debug("[FGTS-OFF] Job {$this->jobId} tentativa {$attempt} – stats chunk #".($idx+1).": ".json_encode($stats));

                    // acumula para decisão de backoff
                    $semRespTotalAttempt += $stats['sem_resposta'];
                    $totalInAttempt      += count($chunkCpfs);

                    // PRÉVIA periódica (tempo)
                    if ($lastPreviewTime->diffInSeconds(Carbon::now()) >= $this->previewIntervalSeconds) {
                        if ($this->finishIfCancelled($job)) return;
                        $this->generatePreview($job, $rows, $pendentes, $terminalFailures);
                        $lastPreviewTime = Carbon::now();
                    }
                }

                if ($this->finishIfCancelled($job)) return;

                // PRÉVIA no fim da tentativa
                $this->generatePreview($job, $rows, $pendentes, $terminalFailures);
                $lastPreviewTime = Carbon::now();

                // Ajustes de chunk/backoff (telemetria básica)
                $semRespRatio = $totalInAttempt > 0 ? ($semRespTotalAttempt / $totalInAttempt) : 0.0;
                if ($semRespRatio >= 0.50 && $chunkSize > $minChunk) {
                    $old = $chunkSize;
                    $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                    Log::warning("[FGTS-OFF] Job {$this->jobId} – muitos sem_resposta (ratio=".round($semRespRatio,2)."). Reduzindo chunk {$old} → {$chunkSize}.");
                }

                if ($seen429InAttempt > 0 && $chunkSize > $minChunk) {
                    $old = $chunkSize;
                    $chunkSize = max($minChunk, (int) floor($chunkSize / 2));
                    Log::warning("[FGTS-OFF] Job {$this->jobId} – 429 vistos. Reduzindo chunk {$old} → {$chunkSize}.");
                }

                // Backoff entre tentativas — considera Retry-After parseado pelo service
                if (!empty($pendentes) && $attempt < $maxAttempts) {
                    if ($this->finishIfCancelled($job)) return;

                    $baseRetryAfter = $retryAfterMax > 0 ? min($retryAfterMax, $retryAfterCap) : 0;
                    $base           = max(1, $retryDelay, $baseRetryAfter);

                    $sleepFactor = 1.0;
                    if     ($semRespRatio >= 0.90) $sleepFactor = 2.0;
                    elseif ($semRespRatio >= 0.50) $sleepFactor = 1.5;

                    $withFactor = (int) ceil($base * $sleepFactor);
                    $jitter     = random_int(0, (int) max(1, ceil($withFactor * 0.15))); // +15% máx
                    $sleepSecs  = $withFactor + $jitter;

                    Log::debug("[FGTS-OFF] Job {$this->jobId} – dormindo {$sleepSecs}s (base={$base}, factor={$sleepFactor}, jitter={$jitter}, retryAfterMax={$retryAfterMax}, semRespRatio=".round($semRespRatio,2).").");
                    sleep($sleepSecs);
                }

                // Stall detector
                $currPendCount = count($pendentes);
                if ($currPendCount === $prevPendCount && $successThisAttempt === 0 && !empty($pendentes)) {
                    Log::warning("[FGTS-OFF] Job {$this->jobId} – sem progresso na tentativa {$attempt}. Mantendo backoff e aguardando próximos retries.");
                }
                $prevPendCount = $currPendCount;
            }

            // 3) Falhas não-retriáveis viram linhas (já contamos no fail_count ao vivo)
            foreach ($terminalFailures as $cpf => $msg) {
                if ($this->finishIfCancelled($job)) return;
                $row = $this->baseRow($cpf);
                $row['autorizado']    = null;
                $row['autorizadoAte'] = null;
                $row['mensagem']      = $msg;
                $row['status']        = null;
                $row['consultadoEm']  = $this->nowBrString();
                $rows[] = $row;
            }

            // 4) Falhas após teimosinha (ainda pendentes) — contam como falha
            if (!empty($pendentes)) {
                $job->increment('fail_count', count($pendentes));
            }
            foreach ($pendentes as $cpf) {
                if ($this->finishIfCancelled($job)) return;
                $row = $this->baseRow($cpf);
                $row['autorizado']    = null;
                $row['autorizadoAte'] = null;
                $row['mensagem']      = $lastError[$cpf] ?? 'Não foi possível consultar após múltiplas tentativas';
                $row['status']        = null;
                $row['consultadoEm']  = $this->nowBrString();
                $rows[] = $row;
            }

            if ($this->isCancelled()) {
                $job->update(['finished_at' => Carbon::now()]);
                $this->deletePreview($job);
                Log::info("[FGTS-OFF] Job {$this->jobId} cancelado na finalização.");
                return;
            }

            // Totais exatos (sobrescreve para garantir consistência)
            $authorizedCount     = count($authorizedMap);
            $notAuthorizedCount  = count($notAuthorizedMap);
            $failCount           = $invalidCount + count($terminalFailures) + count($pendentes);

            // Excel FINAL (escrita atômica)
            $disk         = (string) config('fgts_off.storage.reports_disk', 'public');
            $dirReports   = (string) config('fgts_off.storage.dir_reports', 'fgts-off-reports');
            $finalPrefix  = (string) config('fgts_off.storage.final_prefix', 'fgts-offline');

            $ts       = Carbon::now()->format('Ymd_His');
            $fileName = "{$finalPrefix}_{$this->jobId}_{$ts}.xlsx";
            $tmpName  = "{$finalPrefix}_{$this->jobId}_{$ts}.tmp.xlsx";
            $path     = "{$dirReports}/{$fileName}";
            $tmpPath  = "{$dirReports}/{$tmpName}";

            Excel::store(new FgtsOfflineExport($rows), $tmpPath, $disk);
            Storage::disk($disk)->move($tmpPath, $path);

            $job->update([
                'success_count'        => $authorizedCount,         // ✅ autorizado
                'not_authorized_count' => $notAuthorizedCount,      // ✅ não autorizado
                'fail_count'           => $failCount,               // ✅ erro
                'file_disk'            => $disk,
                'file_path'            => $path,
                'file_name'            => $fileName,
                'status'               => 'concluido',
                'finished_at'          => Carbon::now(),
            ]);
            $this->deletePreview($job);
            Log::info("[FGTS-OFF] Job {$this->jobId} concluído – autorizado: {$authorizedCount}, não autorizado: {$notAuthorizedCount}, falha: {$failCount}");

        } catch (Throwable $e) {
            Log::error("[FGTS-OFF] Job {$this->jobId} falhou: ".$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $job->update([
                'status'      => 'falhou',
                'finished_at' => Carbon::now(),
            ]);
            $this->deletePreview($job);
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
            Log::info("[FGTS-OFF] Job {$this->jobId} interrompido por cancelamento.");
            return true;
        }
        return false;
    }

    private function baseRow(string $cpf): array
    {
        $row = [];
        foreach (\App\Exports\FgtsOfflineExport::COLS as $col) {
            $row[$col] = null;
        }
        $row['cpf'] = $cpf;
        return $row;
    }

    private function generatePreview(FgtsOfflineJob $job, array $rows, array $pendentes, array $terminalFailures): void
    {
        try {
            $rowsPreview = $rows;

            foreach ($terminalFailures as $cpf => $msg) {
                $rowsPreview[] = array_merge($this->baseRow($cpf), [
                    'autorizado'    => null,
                    'autorizadoAte' => null,
                    'mensagem'      => $msg,
                    'status'        => null,
                    'consultadoEm'  => $this->nowBrString(),
                ]);
            }

            foreach ($pendentes as $cpf) {
                $rowsPreview[] = array_merge($this->baseRow($cpf), [
                    'autorizado'    => null,
                    'autorizadoAte' => null,
                    'mensagem'      => 'Em andamento',
                    'status'        => null,
                    'consultadoEm'  => $this->nowBrString(),
                ]);
            }

            $disk          = (string) config('fgts_off.storage.reports_disk', 'public');
            $dirPreviews   = (string) config('fgts_off.storage.dir_previews', 'fgts-off-previews');
            $finalPrefix   = (string) config('fgts_off.storage.final_prefix', 'fgts-offline');
            $previewSuffix = (string) config('fgts_off.storage.preview_suffix', 'preview');

            $fileName = "{$finalPrefix}_{$this->jobId}_{$previewSuffix}.xlsx";
            $tmpName  = "{$finalPrefix}_{$this->jobId}_{$previewSuffix}.tmp.xlsx";
            $path     = "{$dirPreviews}/{$fileName}";
            $tmpPath  = "{$dirPreviews}/{$tmpName}";

            Excel::store(new FgtsOfflineExport($rowsPreview), $tmpPath, $disk);
            Storage::disk($disk)->move($tmpPath, $path);

            $job->update([
                'preview_disk'       => $disk,
                'preview_path'       => $path,
                'preview_name'       => $fileName,
                'preview_updated_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning("[FGTS-OFF] Job {$this->jobId} falha ao gerar prévia: ".$e->getMessage());
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
            ]);
        }
    }

    /** Horário de Brasília formatado (para a planilha). */
    private function nowBrString(): string
    {
        return Carbon::now('America/Sao_Paulo')->format('d/m/Y H:i:s');
    }
}
