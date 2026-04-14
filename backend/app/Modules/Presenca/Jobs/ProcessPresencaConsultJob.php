<?php

namespace App\Modules\Presenca\Jobs;

use App\Modules\Presenca\Models\PresencaConsultJob;
use App\Modules\Presenca\Services\PresencaApiService;
use App\Modules\Presenca\Support\PresencaLog;
use App\Modules\Presenca\Support\PresencaSchema;
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

class ProcessPresencaConsultJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor;
    public int $timeout;

    private int $jobId;
    private string $disk;

    private int $rowsBufferFlush;
    private int $flushEverySecs;
    private int $statusCheckIntervalMs;

    private float $lastFlushAt = 0.0;
    private float $lastStatusCheckAt = 0.0;
    private ?string $cachedStatus = null;

    private int $accSuccess = 0;
    private int $accPolicyDeclined = 0;
    private int $accFail = 0;

    private int $baseSuccess = 0;
    private int $basePolicyDeclined = 0;
    private int $baseFail = 0;

    /** @var resource|null */
    private $spoolFp = null;
    private string $spoolReal = '';

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;

        $this->timeout = (int) config('presenca.job.timeout_seconds', 115200);
        $this->uniqueFor = max(3600, $this->timeout + 3600);
        $this->disk = (string) config('presenca.storage.reports_disk', 'local');

        $this->rowsBufferFlush = max(1, (int) config('presenca.job.rows_buffer_flush', 100));
        $this->flushEverySecs = max(1, (int) config('presenca.job.progress_flush_interval_seconds', 10));
        $this->statusCheckIntervalMs = max(100, (int) config('presenca.job.status_check_interval_ms', 1000));
    }

    public function uniqueId(): string
    {
        return (string) $this->jobId;
    }

    public function handle(PresencaApiService $api): void
    {
        /** @var PresencaConsultJob|null $job */
        $job = PresencaConsultJob::query()->whereKey($this->jobId)->first();
        if (!$job) {
            return;
        }

        if ($this->isCancelled($job)) {
            $this->cleanupSpool($job);
            return;
        }

        $api->setJobId($this->jobId);

        $disk = Storage::disk($this->disk);
        if (
            empty($job->spool_path)
            || empty($job->spool_inputs_path)
            || !$disk->exists($job->spool_path)
            || !$disk->exists($job->spool_inputs_path)
        ) {
            PresencaLog::error("[PRESENCA] Job {$this->jobId} sem spool pré-criado.");
            $this->dispatchFinalize('falhou');
            return;
        }

        $this->baseSuccess = (int) ($job->success_count ?? 0);
        $this->basePolicyDeclined = (int) ($job->policy_declined_count ?? 0);
        $this->baseFail = (int) ($job->fail_count ?? 0);

        $job->update([
            'status' => 'em_progresso',
            'phase' => 'processando',
            'started_at' => $job->started_at ?? Carbon::now(),
            'spool_bytes' => $this->fileSizeSafe($job->spool_path),
        ]);

        $this->cachedStatus = $job->status;
        $this->lastStatusCheckAt = microtime(true);
        $this->lastFlushAt = microtime(true);

        $this->spoolReal = $disk->path($job->spool_path);
        $this->spoolFp = @fopen($this->spoolReal, 'a');
        if (!is_resource($this->spoolFp)) {
            PresencaLog::error("[PRESENCA] Job {$this->jobId} falha ao abrir spool para append.");
            $this->dispatchFinalize('falhou');
            return;
        }

        $reader = @fopen($disk->path($job->spool_inputs_path), 'r');
        if (!is_resource($reader)) {
            PresencaLog::error("[PRESENCA] Job {$this->jobId} falha ao abrir arquivo de entradas.");
            $this->closeSpool();
            $this->dispatchFinalize('falhou');
            return;
        }

        $rowsBuffer = [];

        try {
            while (($line = fgets($reader)) !== false) {
                if ($this->finishIfCancelled($job)) {
                    $this->closeSpool();
                    fclose($reader);
                    return;
                }

                [$cpf, $nome] = $this->parseInputLine($line);
                if (!$cpf || !$nome) {
                    continue;
                }

                $result = $api->consultarCpf($cpf, $nome);
                $row = is_array($result['row'] ?? null) ? $result['row'] : [];
                $outcome = (string) ($result['outcome'] ?? 'failed');

                $rowsBuffer[] = $this->normalizeRowForCsv($row, $cpf, $nome);

                if ($outcome === 'success') {
                    $this->accSuccess++;
                } elseif ($outcome === 'policy_declined') {
                    $this->accPolicyDeclined++;
                } else {
                    $this->accFail++;
                }

                if (count($rowsBuffer) >= $this->rowsBufferFlush || $this->shouldFlushByTime()) {
                    $this->flushRowsBuffer($rowsBuffer);
                    $rowsBuffer = [];
                    $this->flushProgress($job, false);
                }
            }

            if (!empty($rowsBuffer)) {
                $this->flushRowsBuffer($rowsBuffer);
            }

            $this->flushProgress($job, true);
            $this->closeSpool();
            fclose($reader);

            if ($this->isCancelled($job)) {
                $this->cleanupSpool($job);
                return;
            }

            $this->dispatchFinalize('concluido');
        } catch (Throwable $e) {
            PresencaLog::error("[PRESENCA] Erro no processamento do job {$this->jobId}: {$e->getMessage()}", [
                'exception' => $e,
            ]);

            $this->closeSpool();
            if (is_resource($reader)) {
                fclose($reader);
            }

            $this->dispatchFinalize('falhou');
        }
    }

    private function parseInputLine(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [null, null];
        }

        $parts = str_getcsv($line, ';');
        $cpf = isset($parts[0]) ? trim((string) $parts[0]) : null;
        $nome = isset($parts[1]) ? trim((string) $parts[1]) : null;

        if ($cpf === '' || $nome === '') {
            return [null, null];
        }

        return [$cpf, $nome];
    }

    /** @param array<string,mixed> $row */
    private function normalizeRowForCsv(array $row, string $cpf, string $nome): array
    {
        $normalized = array_fill_keys(PresencaSchema::COLS, null);
        foreach ($normalized as $key => $_) {
            if (array_key_exists($key, $row)) {
                $normalized[$key] = $row[$key];
            }
        }

        if (!isset($normalized['cpf']) || $normalized['cpf'] === null || $normalized['cpf'] === '') {
            $normalized['cpf'] = $cpf;
        }

        if (!isset($normalized['nome']) || $normalized['nome'] === null || $normalized['nome'] === '') {
            $normalized['nome'] = $nome;
        }

        if (!isset($normalized['consulted_at']) || $normalized['consulted_at'] === null || $normalized['consulted_at'] === '') {
            $normalized['consulted_at'] = now('America/Sao_Paulo')->format('d/m/Y H:i:s');
        }

        return $normalized;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function flushRowsBuffer(array $rows): void
    {
        if (!is_resource($this->spoolFp)) {
            return;
        }

        foreach ($rows as $row) {
            $line = [];
            foreach (PresencaSchema::COLS as $col) {
                $line[] = $row[$col] ?? null;
            }
            fputcsv($this->spoolFp, $line, ';');
        }

        fflush($this->spoolFp);
        $this->lastFlushAt = microtime(true);
    }

    private function shouldFlushByTime(): bool
    {
        return (microtime(true) - $this->lastFlushAt) >= $this->flushEverySecs;
    }

    private function flushProgress(PresencaConsultJob $job, bool $force): void
    {
        DB::table('presenca_consult_jobs')
            ->where('id', $job->id)
            ->update([
                'success_count' => $this->baseSuccess + $this->accSuccess,
                'policy_declined_count' => $this->basePolicyDeclined + $this->accPolicyDeclined,
                'fail_count' => $this->baseFail + $this->accFail,
                'spool_bytes' => $this->fileSizeSafe($job->spool_path),
                'updated_at' => Carbon::now(),
            ]);

        $this->lastFlushAt = microtime(true);
    }

    private function finishIfCancelled(PresencaConsultJob $job): bool
    {
        if (!$this->isCancelled($job)) {
            return false;
        }

        PresencaLog::info("[PRESENCA] Job {$this->jobId} cancelado durante processamento.");
        $this->cleanupSpool($job);
        return true;
    }

    private function isCancelled(PresencaConsultJob $job): bool
    {
        $now = microtime(true);
        $elapsedMs = (int) (($now - $this->lastStatusCheckAt) * 1000);
        if ($this->cachedStatus !== null && $elapsedMs < $this->statusCheckIntervalMs) {
            return $this->cachedStatus === 'cancelado';
        }

        $status = DB::table('presenca_consult_jobs')
            ->where('id', $job->id)
            ->value('status');

        $this->cachedStatus = is_string($status) ? $status : null;
        $this->lastStatusCheckAt = $now;

        return $this->cachedStatus === 'cancelado';
    }

    private function closeSpool(): void
    {
        if (is_resource($this->spoolFp)) {
            fclose($this->spoolFp);
        }

        $this->spoolFp = null;
    }

    private function fileSizeSafe(?string $relPath): int
    {
        if (!$relPath) {
            return 0;
        }

        try {
            return (int) Storage::disk($this->disk)->size($relPath);
        } catch (Throwable) {
            return 0;
        }
    }

    private function cleanupSpool(PresencaConsultJob $job): void
    {
        try {
            $disk = Storage::disk($this->disk);
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
                'phase' => null,
            ]);
        }
    }

    private function dispatchFinalize(string $status): void
    {
        FinalizePresencaConsultReportJob::dispatch(
            $this->jobId,
            in_array($status, ['concluido', 'falhou'], true) ? $status : 'falhou'
        )->onQueue((string) config('presenca.preview.queue', 'reports'));
    }
}
