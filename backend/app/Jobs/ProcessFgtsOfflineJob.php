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

    /** Timeout por job (segundos). */
    public int $timeout;

    private int $jobId;
    private int $userId;
    private string $title;

    /** @var string[] CPFs válidos (11 dígitos) */
    private array $cpfs;

    /** @var string[] CPFs inválidos (11 dígitos mas DV inválido) */
    private array $invalidCpfs;

    /** Storage / Spool */
    private string $disk;
    private string $dirReports;
    private string $dirPreviews;
    private string $dirSpool;
    private string $finalPrefix;
    private string $previewSuffix;

    public function __construct(int $jobId, int $userId, string $title, array $cpfs, array $invalidCpfs = [])
    {
        $this->jobId = $jobId;
        $this->userId = $userId;
        $this->title = $title;
        $this->cpfs = array_values(array_unique($cpfs));
        $this->invalidCpfs = array_values(array_unique($invalidCpfs));

        // DE: $this->onQueue('default');
        $this->onQueue((string) config('facta_off.job.queue', 'fgts')); // 👈 AGORA VAI PRA FILA 'fgts'

        // Configs
        $this->timeout = (int) config('facta_off.job.timeout_seconds', 115200); // 5h
        $this->disk = (string) config('facta_off.storage.reports_disk', 'public');
        $this->dirReports = (string) config('facta_off.storage.dir_reports', 'fgts-off-reports');
        $this->dirPreviews = (string) config('facta_off.storage.dir_previews', 'fgts-off-previews');
        $this->dirSpool = (string) (config('facta_off.storage.dir_spool') ?? 'fgts-off-spool'); // novo
        $this->finalPrefix = (string) config('facta_off.storage.final_prefix', 'fgts-offline');
        $this->previewSuffix = (string) config('facta_off.storage.preview_suffix', 'preview');
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

        // Inicializa status e SPOOL
        $this->initStorageDirs();
        [$spoolPath, $cpfsPath] = $this->initSpoolFiles($job);

        $job->update([
            'status' => 'em_progresso',
            'started_at' => Carbon::now(),
            'total_cpfs' => count($this->cpfs) + count($this->invalidCpfs),
            'spool_path' => $spoolPath,
            'spool_cpfs_path' => $cpfsPath,
            'spool_bytes' => $this->fileSizeSafe($this->disk, $spoolPath),
            'preview_dirty' => false,
        ]);

        Log::info("[FGTS-OFF] Job {$this->jobId} iniciado – válidos: " . count($this->cpfs) . ", inválidos: " . count($this->invalidCpfs) . ", total: " . $job->total_cpfs);

        // Knobs de execução
        $maxAttempts = (int) config('facta_off.job.max_attempts', 5);
        $retryDelay = (int) config('facta_off.job.retry_delay_seconds', 30);
        $chunkSize = (int) config('facta_off.job.chunk', 6);
        $minChunk = max(1, (int) config('facta_off.job.min_chunk', 2));
        $retryAfterCap = (int) config('facta_off.job.retry_after_max', 120);

        $pendentes = $this->cpfs;
        $invalidCount = count($this->invalidCpfs);

        try {
            // 1) Inválidos já entram no SPOOL (e contam como falha)
            foreach ($this->invalidCpfs as $cpfInv) {
                $row = $this->baseRow($cpfInv);
                $row['autorizado'] = null;
                $row['autorizadoAte'] = null;
                $row['mensagem'] = 'CPF inválido (dígitos verificadores)';
                $row['status'] = null;
                $row['consultadoEm'] = $this->nowBrString();
                $this->spoolAppend($job, $row);
            }
            if ($invalidCount > 0) {
                $job->increment('fail_count', $invalidCount);
            }

            if ($this->isExpired($deadlineUtc)) {
                $this->finalizeExpired($job);
                return;
            }

            // 2) Tentativas com teimosinha
            $prevPendCount = count($pendentes);

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($this->finishIfCancelled($job))
                    return;
                if ($this->isExpired($deadlineUtc)) {
                    $this->finalizeExpired($job);
                    return;
                }
                if (empty($pendentes))
                    break;

                $toTry = $pendentes;
                $chunks = array_chunk($toTry, max(1, $chunkSize));
                $chunkIndex = 0;

                $seen429InAttempt = 0;
                $retryAfterMax = 0;
                $successThisAttempt = 0;
                $semRespTotalAttempt = 0;
                $totalInAttempt = 0;

                Log::debug("[FGTS-OFF] Job {$this->jobId} tentativa {$attempt} – pendentes: " . count($pendentes) . " – chunkSize={$chunkSize}");

                foreach ($chunks as $chunkCpfs) {
                    $chunkIndex++;
                    $chunkCount = count($chunkCpfs);
                    $t0 = microtime(true);

                    if ($this->finishIfCancelled($job))
                        return;
                    if ($this->isExpired($deadlineUtc)) {
                        $this->finalizeExpired($job);
                        return;
                    }

                    Log::debug("[FGTS-OFF] Job {$this->jobId} tentativa {$attempt} – disparando chunk #{$chunkIndex} ({$chunkCount} CPFs)");

                    $batchResults = $api->consultaCpfLote($chunkCpfs);

                    $stats = ['2xx' => 0, '401' => 0, '429' => 0, '5xx' => 0, 'outros' => 0, 'sem_resposta' => 0];
                    $authorizedInChunk = 0;
                    $notAuthorizedInChunk = 0;
                    $terminalFailsInChunk = 0;

                    foreach ($chunkCpfs as $cpf) {
                        if ($this->isExpired($deadlineUtc)) {
                            $this->finalizeExpired($job);
                            return;
                        }

                        $res = $batchResults[$cpf] ?? [
                            'ok' => false,
                            'mensagem' => 'Sem resposta do serviço',
                            'authorized' => null,
                            'authorized_until' => null,
                            'retriable' => true,
                            'http_status' => null,
                            'retry_after' => null,
                            'consultado_at' => $this->nowBrString(),
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

                        if (!empty($res['ok'])) {
                            $row = $this->baseRow($cpf);
                            $row['autorizado'] = $res['authorized'] ?? null;
                            $row['autorizadoAte'] = $res['authorized_until'] ?? null;
                            $row['mensagem'] = $res['mensagem'] ?? null;
                            $row['status'] = $res['http_status'] ?? 200;
                            $row['consultadoEm'] = $res['consultado_at'] ?? $this->nowBrString();
                            $this->spoolAppend($job, $row);

                            if ($res['authorized'] === true)
                                $authorizedInChunk++;
                            else
                                $notAuthorizedInChunk++;

                            $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                            $successThisAttempt++;
                        } else {
                            $msg = (string) ($res['mensagem'] ?? 'Falha na consulta');
                            $retriable = $res['retriable'] ?? true;

                            if ($retriable === false) {
                                $row = $this->baseRow($cpf);
                                $row['autorizado'] = null;
                                $row['autorizadoAte'] = null;
                                $row['mensagem'] = $msg;
                                $row['status'] = $res['http_status'] ?? null;
                                $row['consultadoEm'] = $res['consultado_at'] ?? $this->nowBrString();
                                $this->spoolAppend($job, $row);

                                $pendentes = array_values(array_filter($pendentes, fn($x) => $x !== $cpf));
                                $terminalFailsInChunk++;
                            } else {
                                // mantém para próxima tentativa
                            }
                        }
                    }

                    if ($authorizedInChunk > 0)
                        $job->increment('success_count', $authorizedInChunk);
                    if ($notAuthorizedInChunk > 0)
                        $job->increment('not_authorized_count', $notAuthorizedInChunk);
                    if ($terminalFailsInChunk > 0)
                        $job->increment('fail_count', $terminalFailsInChunk);

                    $semRespTotalAttempt += $stats['sem_resposta'];
                    $totalInAttempt += $chunkCount;

                    $elapsed = max(0.001, microtime(true) - $t0);
                    $rps = $chunkCount / $elapsed;
                    Log::debug(
                        "[FGTS-OFF] Job {$this->jobId} tentativa {$attempt} – chunk #{$chunkIndex} " .
                        "size={$chunkCount} stats=" . json_encode($stats) .
                        " auth={$authorizedInChunk} nao_auth={$notAuthorizedInChunk} fail_term={$terminalFailsInChunk} " .
                        "pend_rest=" . count($pendentes) . " " .
                        "elapsed=" . number_format($elapsed, 3) . "s rps=" . number_format($rps, 2)
                    );
                }

                if ($this->finishIfCancelled($job))
                    return;

                $semRespRatio = $totalInAttempt > 0 ? ($semRespTotalAttempt / $totalInAttempt) : 0.0;
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

                Log::debug(
                    "[FGTS-OFF] Job {$this->jobId} tentativa {$attempt} – resumo: " .
                    "pendentes=" . count($pendentes) .
                    " sem_resp_ratio=" . number_format($semRespRatio, 2) .
                    " seen429={$seen429InAttempt} retry_after_max={$retryAfterMax} chunkSizeAtual={$chunkSize}"
                );

                if (!empty($pendentes) && $attempt < $maxAttempts) {
                    if ($this->finishIfCancelled($job))
                        return;
                    if ($this->isExpired($deadlineUtc)) {
                        $this->finalizeExpired($job);
                        return;
                    }

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

                    Log::debug("[FGTS-OFF] Job {$this->jobId} – dormindo {$sleepSecs}s.");
                    sleep($sleepSecs);
                }

                $currPendCount = count($pendentes);
                if ($currPendCount === $prevPendCount && $successThisAttempt === 0 && !empty($pendentes)) {
                    Log::warning("[FGTS-OFF] Job {$this->jobId} – sem progresso na tentativa {$attempt}.");
                }
                $prevPendCount = $currPendCount;
            }

            // 3) Conclusão normal → gera FINAL, só então limpa o spool
            $finalOk = $this->generateFinalFromSpool($job);
            if ($finalOk) {
                $this->cleanupSpool($job);
            }

            $job->update([
                'status' => 'concluido',
                'finished_at' => Carbon::now(),
            ]);
            $this->deletePreview($job);

            $job->refresh();
            Log::info("[FGTS-OFF] Job {$this->jobId} concluído – autorizado: {$job->success_count}, não autorizado: {$job->not_authorized_count}, falha: {$job->fail_count}");
        } catch (Throwable $e) {
            // Tenta gerar FINAL com o que temos; limpa spool somente se o FINAL existir
            $finalOk = false;
            try {
                $finalOk = $this->generateFinalFromSpool($job);
            } catch (\Throwable $e2) {
                Log::warning("[FGTS-OFF] Job {$this->jobId} falhou e não conseguiu gerar FINAL: " . $e2->getMessage());
            }
            if ($finalOk) {
                $this->cleanupSpool($job);
            }

            $job->update([
                'status' => 'falhou',
                'finished_at' => Carbon::now(),
            ]);
            $job->refresh();
            Log::error("[FGTS-OFF] Job {$this->jobId} falhou – autorizado: {$job->success_count}, não autorizado: {$job->not_authorized_count}, falha: {$job->fail_count}. Erro: " . $e->getMessage());
        }
    }

    /** ----------------------- Helpers ----------------------- */

    private function isCancelled(): bool
    {
        $status = DB::table('fgts_off_consult_jobs')->where('id', $this->jobId)->value('status');
        return $status === 'cancelado';
    }

    private function isExpired(?Carbon $deadlineUtc): bool
    {
        return $deadlineUtc !== null && Carbon::now('UTC')->greaterThan($deadlineUtc);
    }

    private function finalizeExpired(FgtsOfflineJob $job): void
    {
        // Gera Excel com o que temos; limpa spool somente se o FINAL existir
        $finalOk = $this->generateFinalFromSpool($job);
        if ($finalOk) {
            $this->cleanupSpool($job);
        }

        $job->update([
            'status' => 'expirado',
            'finished_at' => Carbon::now(),
        ]);

        $this->deletePreview($job);

        $job->refresh();
        Log::info("[FGTS-OFF] Job {$this->jobId} expirado – autorizado: {$job->success_count}, não autorizado: {$job->not_authorized_count}, falha: {$job->fail_count}");
    }

    private function finishIfCancelled(FgtsOfflineJob $job): bool
    {
        if ($this->isCancelled()) {
            // cancelamento: não gera FINAL, apenas encerra e limpa prévia + spool
            $job->update(['finished_at' => Carbon::now()]);
            $this->deletePreview($job);
            $this->cleanupSpool($job);
            Log::info("[FGTS-OFF] Job {$this->jobId} interrompido por cancelamento (spool removido).");
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

    /** Inicializa diretórios do storage se necessário. */
    private function initStorageDirs(): void
    {
        $disk = Storage::disk($this->disk);
        foreach ([$this->dirReports, $this->dirPreviews, $this->dirSpool] as $dir) {
            if (!$disk->exists($dir)) {
                $disk->makeDirectory($dir);
            }
        }
    }

    /**
     * Cria os arquivos iniciais do spool (CSV com cabeçalho) e o arquivo de CPFs (um por linha).
     * Retorna [spoolPath, cpfsPath].
     */
    private function initSpoolFiles(FgtsOfflineJob $job): array
    {
        $disk = Storage::disk($this->disk);

        $spoolName = "{$this->finalPrefix}_{$this->jobId}.spool.csv";
        $cpfsName = "{$this->finalPrefix}_{$this->jobId}.cpfs.txt";

        $spoolPath = "{$this->dirSpool}/{$spoolName}";
        $cpfsPath = "{$this->dirSpool}/{$cpfsName}";

        // Spool CSV com cabeçalho
        $fp = fopen($disk->path($spoolPath), 'c+');
        if ($fp === false) {
            throw new \RuntimeException("Não foi possível criar spool em {$spoolPath}");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fputcsv($fp, \App\Exports\FgtsOfflineExport::COLS, ';');
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        // Arquivo CPFs (todos: válidos + inválidos) — um por linha
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

    /** Apende uma linha no SPOOL (CSV), com lock e atualização de bytes / preview_dirty. */
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
                    $v = $row[$key] ?? null;

                    // 🔧 Normaliza 'autorizado' para '1'/'0' (evita string vazia no CSV)
                    if ($key === 'autorizado') {
                        if ($v === true || $v === 1 || $v === '1') {
                            $v = '1';
                        } elseif ($v === false || $v === 0 || $v === '0') {
                            $v = '0';
                        } elseif (is_string($v)) {
                            $n = mb_strtolower(trim($v), 'UTF-8');
                            if (in_array($n, ['sim', 's', 'true'], true)) {
                                $v = '1';
                            } elseif (in_array($n, ['nao', 'não', 'n', 'false'], true)) {
                                $v = '0';
                            }
                        }
                        // se continuar null, deixa null (inválido/pendente)
                    }

                    $ordered[] = $v;
                }

                fputcsv($fp, $ordered, ';');
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        $job->updateQuietly([
            'spool_bytes' => $this->fileSizeSafe($this->disk, $path),
            'preview_dirty' => true,
        ]);
    }

    /**
     * Gera XLSX FINAL a partir do SPOOL, grava de forma atômica e atualiza o job.
     * @return bool true se gerou e moveu com sucesso; false caso contrário.
     */
    private function generateFinalFromSpool(FgtsOfflineJob $job): bool
    {
        try {
            $disk = Storage::disk($this->disk);
            $spoolPath = $job->spool_path;

            if (!$spoolPath || !$disk->exists($spoolPath)) {
                Log::warning("[FGTS-OFF] Job {$this->jobId} – spool ausente, não há FINAL para gerar.");
                return false;
            }

            $ts = Carbon::now()->format('Ymd_His');
            $fileName = "{$this->finalPrefix}_{$this->jobId}_{$ts}.xlsx";
            $tmpName = "{$this->finalPrefix}_{$this->jobId}_{$ts}.tmp.xlsx";
            $path = "{$this->dirReports}/{$fileName}";
            $tmpPath = "{$this->dirReports}/{$tmpName}";

            $export = FgtsOfflineExport::fromCsv($disk->path($spoolPath));
            Excel::store($export, $tmpPath, $this->disk);

            // move atômico dentro do mesmo disco
            $disk->move($tmpPath, $path);

            if (!$disk->exists($path)) {
                Log::error("[FGTS-OFF] Job {$this->jobId} – FINAL não encontrado após move: {$path}");
                return false;
            }

            $job->update([
                'file_disk' => $this->disk,
                'file_path' => $path,
                'file_name' => $fileName,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error("[FGTS-OFF] Job {$this->jobId} – erro gerando FINAL: " . $e->getMessage());
            return false;
        }
    }

    /** Remove spool CSV/TXT com segurança e limpa campos no banco. */
    private function cleanupSpool(FgtsOfflineJob $job): void
    {
        try {
            $disk = Storage::disk($this->disk);

            foreach (['spool_path', 'spool_cpfs_path'] as $field) {
                $p = $job->{$field} ?? null;
                if ($p && $disk->exists($p)) {
                    try {
                        $disk->delete($p);
                    } catch (\Throwable $e) {
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

    /** Horário de Brasília formatado (para a planilha). */
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
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
