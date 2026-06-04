<?php

namespace App\Modules\V8Fgts\Jobs;

use App\Modules\V8Fgts\Models\V8FgtsConsultJob;
use App\Modules\V8Fgts\Models\V8FgtsConsultJobItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessV8FgtsConsultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    private int $jobId;
    private string $disk;
    private int $prepareInsertChunk;
    private int $dedupeInMemoryLimit;

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;
        $this->onQueue((string) config('v8_fgts.job.queue', 'fgts'));

        $this->timeout = (int) config('v8_fgts.job.timeout_seconds', 21600);
        $this->disk = (string) config('v8_fgts.storage.reports_disk', 'local');
        $this->prepareInsertChunk = max(100, (int) config('v8_fgts.job.prepare_insert_chunk', 2000));
        $this->dedupeInMemoryLimit = max($this->prepareInsertChunk, (int) config('v8_fgts.job.dedupe_in_memory_limit', 100000));
    }

    public function handle(): void
    {
        $job = V8FgtsConsultJob::query()->find($this->jobId);
        if (!$job) {
            return;
        }

        if ($job->status === 'cancelado') {
            $this->dispatchFinalize('falhou');
            return;
        }

        $disk = Storage::disk($this->disk);
        if (
            empty($job->spool_path)
            || empty($job->spool_cpfs_path)
            || !$disk->exists($job->spool_path)
            || !$disk->exists($job->spool_cpfs_path)
        ) {
            Log::error("[V8-FGTS] Job {$this->jobId} sem arquivos iniciais.");
            $this->dispatchFinalize('falhou');
            return;
        }

        $job->update([
            'status' => 'em_progresso',
            'phase' => 'preparando_itens',
            'started_at' => $job->started_at ?? Carbon::now(),
        ]);

        try {
            $this->prepareItems($job, $disk->path($job->spool_cpfs_path));
        } catch (Throwable $e) {
            Log::error("[V8-FGTS] Job {$this->jobId} falhou ao preparar itens: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            $this->dispatchFinalize('falhou');
            return;
        }

        $total = V8FgtsConsultJobItem::query()
            ->where('job_id', $job->id)
            ->count();

        if ($total === 0) {
            $job->update([
                'phase' => null,
                'total_cpfs' => 0,
            ]);
            $this->dispatchFinalize('falhou');
            return;
        }

        $job->update([
            'phase' => 'iniciar_saldo',
            'total_cpfs' => $total,
        ]);

        ProcessV8FgtsConsultBatchJob::dispatch($job->id)
            ->onQueue((string) config('v8_fgts.job.queue', 'fgts'));
    }

    private function prepareItems(V8FgtsConsultJob $job, string $sourceReal): void
    {
        $buffer = [];

        foreach ($this->tokenizeInputFile($sourceReal) as $token) {
            $cpf = $this->normalizeCpf($token);
            if ($cpf === null) {
                continue;
            }

            $buffer[$cpf] = true;
            if (count($buffer) >= $this->prepareInsertChunk || count($buffer) >= $this->dedupeInMemoryLimit) {
                $this->flushPreparedChunk($job->id, $buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->flushPreparedChunk($job->id, $buffer);
        }
    }

    private function flushPreparedChunk(int $jobId, array $buffer): void
    {
        $now = Carbon::now();
        $rows = [];

        foreach (array_keys($buffer) as $cpf) {
            $rows[] = [
                'job_id' => $jobId,
                'cpf' => $cpf,
                'state' => V8FgtsConsultJobItem::STATE_QUEUED_START,
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('v8_fgts_consult_job_items')->insertOrIgnore($rows);
        }
    }

    private function tokenizeInputFile(string $realPath): \Generator
    {
        $reader = @fopen($realPath, 'rb');
        if (!is_resource($reader)) {
            return;
        }

        $buffer = '';

        try {
            while (!feof($reader)) {
                $chunk = fread($reader, 1024 * 256);
                if ($chunk === false || $chunk === '') {
                    continue;
                }

                $buffer .= $chunk;
                $parts = preg_split('/[\s,;]+/u', $buffer);
                if ($parts === false) {
                    continue;
                }

                $buffer = (string) array_pop($parts);
                foreach ($parts as $part) {
                    if ($part !== '') {
                        yield $part;
                    }
                }
            }
        } finally {
            fclose($reader);
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            yield $tail;
        }
    }

    private function normalizeCpf(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value);
        if ($digits === null || strlen($digits) !== 11) {
            return null;
        }

        return $digits;
    }

    private function dispatchFinalize(string $targetStatus): void
    {
        FinalizeV8FgtsConsultReportJob::dispatch($this->jobId, $targetStatus)
            ->onQueue((string) config('v8_fgts.preview.queue', 'reports'));
    }
}
