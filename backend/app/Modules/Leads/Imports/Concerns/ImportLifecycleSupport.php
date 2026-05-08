<?php

namespace App\Modules\Leads\Imports\Concerns;

use Illuminate\Support\Facades\DB;

trait ImportLifecycleSupport
{
    protected bool $cancelled = false;
    protected int $cancelCheckCounter = 0;

    protected function dbBatchSize(): int
    {
        return max(50, (int) config('leads.import.db_batch_size', 500));
    }

    protected function maxErrorsPerJob(): int
    {
        return max(1, (int) config('leads.import.max_errors_per_job', 5000));
    }

    protected function queueImportError(int $rowNumber, string $columnName, string $message): void
    {
        if ($this->maxErrorsPerJob > 0 && $this->errorCount >= $this->maxErrorsPerJob) {
            return;
        }

        $now = now();
        $this->pendingErrors[] = [
            'import_job_id' => $this->importJob->id,
            'row_number' => $rowNumber,
            'column_name' => $columnName,
            'error_message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->errorCount++;

        if (count($this->pendingErrors) >= $this->dbBatchSize()) {
            $this->flushQueuedErrors();
        }
    }

    protected function flushQueuedErrors(): void
    {
        if (empty($this->pendingErrors)) {
            return;
        }

        foreach (array_chunk($this->pendingErrors, $this->dbBatchSize()) as $chunk) {
            DB::table('import_errors')->insert($chunk);
        }

        $this->pendingErrors = [];
    }

    protected function bootImportLifecycleState(): void
    {
        $this->backup->purgeOldBackups();
        $this->rowsInCurrentChunk = 0;
        $this->cancelled = false;
        $this->cancelCheckCounter = 0;
        $this->maxErrorsPerJob = $this->maxErrorsPerJob();
        $this->errorCount = (int) DB::table('import_errors')
            ->where('import_job_id', $this->importJob->id)
            ->count();
    }

    protected function shouldStopImport(): bool
    {
        if ($this->cancelled) {
            return true;
        }

        $this->cancelCheckCounter++;
        if (($this->cancelCheckCounter % 50) !== 0) {
            return false;
        }

        return $this->refreshImportCancelledFlag(true);
    }

    protected function refreshImportCancelledFlag(bool $force = false): bool
    {
        if ($this->cancelled && !$force) {
            return true;
        }

        $status = DB::table('import_jobs')
            ->where('id', $this->importJob->id)
            ->value('status');

        $this->cancelled = ($status === 'cancelado');
        return $this->cancelled;
    }

    protected function updateProcessedRowsAfterChunk(): void
    {
        if ($this->rowsInCurrentChunk <= 0) {
            return;
        }

        DB::table('import_jobs')
            ->where('id', $this->importJob->id)
            ->update([
                'processed_rows' => DB::raw(
                    'CASE
                        WHEN total_rows > 0
                            THEN LEAST(processed_rows + ' . (int) $this->rowsInCurrentChunk . ', total_rows)
                        ELSE processed_rows + ' . (int) $this->rowsInCurrentChunk . '
                     END'
                ),
            ]);

        $this->rowsInCurrentChunk = 0;
    }

    protected function finalizeImportJobAsCompleted(): void
    {
        $this->rowsInCurrentChunk = 0;
        $snapshot = DB::table('import_jobs')
            ->where('id', $this->importJob->id)
            ->first(['processed_rows', 'total_rows']);

        $processed = (int) ($snapshot->processed_rows ?? 0);
        $total = max((int) ($snapshot->total_rows ?? 0), $processed);

        $this->importJob->update([
            'total_rows' => $total,
            'processed_rows' => $total,
            'status' => 'concluido',
            'finished_at' => now(),
        ]);
    }

    protected function finalizeImportJobAsCancelled(): void
    {
        $this->rowsInCurrentChunk = 0;

        DB::table('import_jobs')
            ->where('id', $this->importJob->id)
            ->where('status', 'cancelado')
            ->update([
                'finished_at' => DB::raw('COALESCE(finished_at, NOW())'),
            ]);
    }
}
