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
    private int $authorizationWarmupBatchSize;
    private ?int $runtimeWorkerMemoryLimitBytes = null;

    private float $lastFlushAt = 0.0;
    private float $lastStatusCheckAt = 0.0;
    private ?string $cachedStatus = null;
    private bool $missingJobLogged = false;

    private int $accSuccess = 0;
    private int $accPolicyDeclined = 0;
    private int $accFail = 0;

    private int $baseSuccess = 0;
    private int $basePolicyDeclined = 0;
    private int $baseFail = 0;

    /** @var resource|null */
    private $spoolFp = null;
    private string $spoolReal = '';
    private int $spoolBytes = 0;

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;

        $this->timeout = (int) config('presenca.job.timeout_seconds', 115200);
        $this->uniqueFor = max(3600, $this->timeout + 3600);
        $this->disk = (string) config('presenca.storage.reports_disk', 'local');

        $this->rowsBufferFlush = max(1, (int) config('presenca.job.rows_buffer_flush', 100));
        $this->flushEverySecs = max(1, (int) config('presenca.job.progress_flush_interval_seconds', 10));
        $this->statusCheckIntervalMs = max(100, (int) config('presenca.job.status_check_interval_ms', 1000));
        $this->authorizationWarmupBatchSize = max(1, (int) config('presenca.authorization.warmup_batch_size', 500));
        $this->runtimeWorkerMemoryLimitBytes = $this->detectRuntimeWorkerMemoryLimitBytes();
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
        $this->spoolBytes = $this->fileSizeSafe($job->spool_path);

        $job->update([
            'status' => 'em_progresso',
            'phase' => 'processando',
            'started_at' => $job->started_at ?? Carbon::now(),
            'spool_bytes' => $this->spoolBytes,
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

        $uniqInputsRel = $this->uniqueInputsPathRel();
        $uniqueCount = $this->buildUniqueInputsFile($job, $disk->path($job->spool_inputs_path), $uniqInputsRel);
        if ($uniqueCount < 0) {
            $this->closeSpool();
            $this->cleanupTempRel($uniqInputsRel);
            return;
        }

        if ($uniqueCount === 0) {
            PresencaLog::error("[PRESENCA] Job {$this->jobId} sem entradas válidas após deduplicação.");
            $this->closeSpool();
            $this->cleanupTempRel($uniqInputsRel);
            $this->dispatchFinalize('falhou');
            return;
        }

        $sourceTotal = (int) ($job->total_cpfs ?? 0);

        DB::table('presenca_consult_jobs')
            ->where('id', $job->id)
            ->update([
                'total_cpfs' => $uniqueCount,
                'updated_at' => Carbon::now(),
            ]);

        $duplicatesSkipped = max(0, $sourceTotal - $uniqueCount);
        if ($duplicatesSkipped > 0) {
            PresencaLog::info("[PRESENCA] Deduplicação aplicada no worker (job {$this->jobId}).", [
                'input_rows' => $sourceTotal,
                'unique_cpfs' => $uniqueCount,
                'duplicates_skipped' => $duplicatesSkipped,
            ]);
        }

        $reader = @fopen($disk->path($uniqInputsRel), 'r');
        if (!is_resource($reader)) {
            PresencaLog::error("[PRESENCA] Job {$this->jobId} falha ao abrir arquivo de entradas.");
            $this->closeSpool();
            $this->cleanupTempRel($uniqInputsRel);
            $this->dispatchFinalize('falhou');
            return;
        }

        $rowsBuffer = [];
        $inputBatch = [];

        try {
            while (($parts = fgetcsv($reader, 0, ';')) !== false) {
                if ($this->finishIfCancelled($job)) {
                    $this->closeSpool();
                    fclose($reader);
                    $this->cleanupTempRel($uniqInputsRel);
                    return;
                }

                if (!is_array($parts) || $parts === [null]) {
                    continue;
                }

                [$cpf, $nome] = $this->parseInputColumns($parts);
                if (!$cpf || !$nome) {
                    continue;
                }

                $inputBatch[] = ['cpf' => $cpf, 'nome' => $nome];
                if (count($inputBatch) >= $this->authorizationWarmupBatchSize) {
                    if ($this->processInputBatch($job, $api, $inputBatch, $rowsBuffer)) {
                        $this->closeSpool();
                        fclose($reader);
                        $this->cleanupTempRel($uniqInputsRel);
                        return;
                    }
                    $inputBatch = [];
                }
            }

            if (!empty($inputBatch)) {
                if ($this->processInputBatch($job, $api, $inputBatch, $rowsBuffer)) {
                    $this->closeSpool();
                    fclose($reader);
                    $this->cleanupTempRel($uniqInputsRel);
                    return;
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
                $this->cleanupTempRel($uniqInputsRel);
                return;
            }

            $this->cleanupTempRel($uniqInputsRel);
            $this->dispatchFinalize('concluido');
        } catch (Throwable $e) {
            PresencaLog::error("[PRESENCA] Erro no processamento do job {$this->jobId}: {$e->getMessage()}", [
                'exception' => $e,
            ]);

            $this->closeSpool();
            if (is_resource($reader)) {
                fclose($reader);
            }

            $this->cleanupTempRel($uniqInputsRel);
            $this->dispatchFinalize('falhou');
        }
    }

    /**
     * @param array<int,mixed> $parts
     * @return array{0:?string,1:?string}
     */
    private function parseInputColumns(array $parts): array
    {
        $cpf = isset($parts[0]) ? trim((string) $parts[0]) : null;
        $nome = isset($parts[1]) ? trim((string) $parts[1]) : null;

        if ($cpf === '' || $nome === '') {
            return [null, null];
        }

        return [$cpf, $nome];
    }

    /**
     * @param array<int,array{cpf:string,nome:string}> $inputBatch
     * @param array<int,array<string,mixed>> $rowsBuffer
     */
    private function processInputBatch(
        PresencaConsultJob $job,
        PresencaApiService $api,
        array $inputBatch,
        array &$rowsBuffer
    ): bool {
        if (empty($inputBatch)) {
            return false;
        }

        $cpfs = [];
        foreach ($inputBatch as $entry) {
            $cpf = (string) ($entry['cpf'] ?? '');
            if ($cpf !== '') {
                $cpfs[] = $cpf;
            }
        }

        if (!empty($cpfs)) {
            $api->warmReusableAuthorizations($cpfs);
        }

        foreach ($inputBatch as $entry) {
            if ($this->finishIfCancelled($job)) {
                return true;
            }

            $cpf = (string) ($entry['cpf'] ?? '');
            $nome = (string) ($entry['nome'] ?? '');
            if ($cpf === '' || $nome === '') {
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

        return false;
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

        if (!flock($this->spoolFp, LOCK_EX)) {
            throw new \RuntimeException("Falha ao adquirir lock de escrita do spool (job {$this->jobId}).");
        }

        try {
            foreach ($rows as $row) {
                $line = [];
                foreach (PresencaSchema::COLS as $col) {
                    $line[] = $row[$col] ?? null;
                }
                fputcsv($this->spoolFp, $line, ';');
            }

            fflush($this->spoolFp);
            $pos = ftell($this->spoolFp);
            if (is_int($pos) && $pos >= 0) {
                $this->spoolBytes = max($this->spoolBytes, $pos);
            }
        } finally {
            flock($this->spoolFp, LOCK_UN);
        }

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
                'spool_bytes' => $this->spoolBytes,
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

        if (!is_string($status)) {
            $this->cachedStatus = 'cancelado';
            $this->lastStatusCheckAt = $now;

            if (!$this->missingJobLogged) {
                PresencaLog::warning("[PRESENCA] Job {$this->jobId} removido durante processamento; interrompendo execução.");
                $this->missingJobLogged = true;
            }

            return true;
        }

        $this->cachedStatus = $status;
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

    private function uniqueInputsPathRel(): string
    {
        $dirSpool = (string) (config('presenca.storage.dir_spool') ?? 'presenca-spool');
        $finalPrefix = (string) config('presenca.storage.final_prefix', 'presenca-consulta');

        return "{$dirSpool}/{$finalPrefix}_{$this->jobId}.inputs.uniq.txt";
    }

    private function cleanupTempRel(?string $relPath): void
    {
        if (!is_string($relPath) || $relPath === '') {
            return;
        }

        try {
            $disk = Storage::disk($this->disk);
            if ($disk->exists($relPath)) {
                $disk->delete($relPath);
            }
        } catch (Throwable) {
        }
    }

    /**
     * @return int Número de CPFs únicos gravados. Retorna -1 quando interrompido por cancelamento.
     */
    private function buildUniqueInputsFile(PresencaConsultJob $job, string $inputsReal, string $uniqRel): int
    {
        $disk = Storage::disk($this->disk);
        $uniqReal = $disk->path($uniqRel);

        $dirSpool = (string) (config('presenca.storage.dir_spool') ?? 'presenca-spool');
        if (!$disk->exists($dirSpool)) {
            $disk->makeDirectory($dirSpool);
        }

        $blockSize = 10000;
        $chunks = [];

        $reader = @fopen($inputsReal, 'r');
        if (!is_resource($reader)) {
            return 0;
        }

        try {
            $block = [];

            while (($parts = fgetcsv($reader, 0, ';')) !== false) {
                if ($this->finishIfCancelled($job)) {
                    foreach ($chunks as $chunk) {
                        $this->cleanupTempRel($chunk['rel'] ?? null);
                    }
                    return -1;
                }

                if (!is_array($parts) || $parts === [null]) {
                    continue;
                }

                [$cpf, $nome] = $this->parseInputColumns($parts);
                if (!$cpf || !$nome) {
                    continue;
                }

                if (!isset($block[$cpf])) {
                    $block[$cpf] = $nome; // mantém a primeira ocorrência no bloco
                }

                if (count($block) >= $blockSize || $this->shouldSpill(count($block))) {
                    $chunks[] = $this->writeSortedUniqueChunk($block);
                    $block = [];
                }
            }

            if (!empty($block)) {
                $chunks[] = $this->writeSortedUniqueChunk($block);
                $block = [];
            }
        } finally {
            fclose($reader);
        }

        if (empty($chunks)) {
            $w = @fopen($uniqReal, 'w');
            if (is_resource($w)) {
                fclose($w);
            }
            return 0;
        }

        if (count($chunks) === 1) {
            @rename($chunks[0]['real'], $uniqReal);
            return $this->countLines($uniqReal);
        }

        $writer = @fopen($uniqReal, 'w');
        if (!is_resource($writer)) {
            foreach ($chunks as $chunk) {
                $this->cleanupTempRel($chunk['rel'] ?? null);
            }
            return 0;
        }

        $handles = [];
        $heads = [];
        foreach ($chunks as $idx => $chunk) {
            $h = @fopen($chunk['real'], 'r');
            if (!is_resource($h)) {
                continue;
            }

            $first = fgetcsv($h, 0, ';');
            if (!is_array($first) || $first === [null]) {
                fclose($h);
                continue;
            }

            [$cpf, $nome] = $this->parseInputColumns($first);
            if (!$cpf || !$nome) {
                fclose($h);
                continue;
            }

            $handles[$idx] = $h;
            $heads[$idx] = ['cpf' => $cpf, 'nome' => $nome];
        }

        $written = 0;
        try {
            while (!empty($heads)) {
                $pickIdx = null;
                $pickCpf = null;

                foreach ($heads as $idx => $head) {
                    $cpf = (string) ($head['cpf'] ?? '');
                    if ($cpf === '') {
                        continue;
                    }

                    if (
                        $pickCpf === null
                        || strcmp($cpf, $pickCpf) < 0
                        || (strcmp($cpf, $pickCpf) === 0 && $idx < (int) $pickIdx)
                    ) {
                        $pickCpf = $cpf;
                        $pickIdx = $idx;
                    }
                }

                if ($pickIdx === null || $pickCpf === null) {
                    break;
                }

                $picked = $heads[$pickIdx];
                fputcsv($writer, [$picked['cpf'], $picked['nome']], ';');
                $written++;

                foreach (array_keys($heads) as $idx) {
                    while (true) {
                        $current = $heads[$idx] ?? null;
                        if (!is_array($current) || (string) ($current['cpf'] ?? '') !== $pickCpf) {
                            break;
                        }

                        $next = fgetcsv($handles[$idx], 0, ';');
                        if (!is_array($next) || $next === [null]) {
                            fclose($handles[$idx]);
                            unset($handles[$idx], $heads[$idx]);
                            break;
                        }

                        [$nextCpf, $nextNome] = $this->parseInputColumns($next);
                        if (!$nextCpf || !$nextNome) {
                            continue;
                        }

                        $heads[$idx] = ['cpf' => $nextCpf, 'nome' => $nextNome];
                    }
                }
            }
        } finally {
            foreach ($handles as $h) {
                if (is_resource($h)) {
                    fclose($h);
                }
            }
            fclose($writer);

            foreach ($chunks as $chunk) {
                $this->cleanupTempRel($chunk['rel'] ?? null);
            }
        }

        return $written;
    }

    /**
     * @param array<string,string> $block cpf => nome
     * @return array{rel:string,real:string}
     */
    private function writeSortedUniqueChunk(array $block): array
    {
        $disk = Storage::disk($this->disk);
        $dirSpool = (string) (config('presenca.storage.dir_spool') ?? 'presenca-spool');
        $finalPrefix = (string) config('presenca.storage.final_prefix', 'presenca-consulta');

        if (!$disk->exists($dirSpool)) {
            $disk->makeDirectory($dirSpool);
        }

        $rel = "{$dirSpool}/{$finalPrefix}_{$this->jobId}.inputs.chunk." . uniqid('', true) . ".txt";
        $real = $disk->path($rel);

        ksort($block, SORT_STRING);
        $writer = @fopen($real, 'w');
        if (is_resource($writer)) {
            foreach ($block as $cpf => $nome) {
                fputcsv($writer, [$cpf, $nome], ';');
            }
            fclose($writer);
        }

        return ['rel' => $rel, 'real' => $real];
    }

    private function shouldSpill(int $currentCount): bool
    {
        if ($currentCount <= 0) {
            return false;
        }

        $limit = $this->effectiveMemoryBudgetBytes();
        if ($limit <= 0 || $limit === PHP_INT_MAX) {
            return false;
        }

        $usage = memory_get_usage(true);
        return $usage > (int) floor($limit * 0.70);
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
            case 'g':
                $num *= 1024;
                // no break
            case 'm':
                $num *= 1024;
                // no break
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

            if ($arg === '--memory' && isset($argv[$idx + 1]) && is_numeric((string) $argv[$idx + 1])) {
                $memoryMb = (int) $argv[$idx + 1];
                break;
            }
        }

        return ($memoryMb !== null && $memoryMb > 0) ? ($memoryMb * 1024 * 1024) : null;
    }

    private function countLines(string $realPath): int
    {
        $count = 0;
        $h = @fopen($realPath, 'r');
        if (!is_resource($h)) {
            return 0;
        }

        try {
            while (!feof($h)) {
                if (fgets($h) !== false) {
                    $count++;
                }
            }
        } finally {
            fclose($h);
        }

        return $count;
    }

    private function dispatchFinalize(string $status): void
    {
        FinalizePresencaConsultReportJob::dispatch(
            $this->jobId,
            in_array($status, ['concluido', 'falhou'], true) ? $status : 'falhou'
        )->onQueue((string) config('presenca.preview.queue', 'reports'));
    }
}
