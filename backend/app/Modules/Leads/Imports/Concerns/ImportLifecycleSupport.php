<?php

namespace App\Modules\Leads\Imports\Concerns;

use App\Modules\Leads\Imports\Exceptions\ImportCancelledException;
use Illuminate\Support\Facades\DB;

trait ImportLifecycleSupport
{
    protected int $errorCount = 0;

    protected function batchSize(): int
    {
        return max(100, (int) config('leads.import.batch_size', 1000));
    }

    protected function bootImportLifecycleState(): void
    {
        $this->errorCount = (int) DB::table('import_errors')
            ->where('import_job_id', $this->importJob->id)
            ->count();
    }

    /** @param array<int, array{row_number:int,column_name:string,error_message:string}> $errors */
    protected function insertErrors(array $errors): void
    {
        $limit = max(1, (int) config('leads.import.max_errors_per_job', 5000));
        $remaining = max(0, $limit - $this->errorCount);
        if ($remaining === 0 || $errors === []) {
            return;
        }

        $now = now();
        $rows = array_map(fn (array $error) => [
            'import_job_id' => $this->importJob->id,
            'row_number' => $error['row_number'],
            'column_name' => $error['column_name'],
            'error_message' => $error['error_message'],
            'created_at' => $now,
            'updated_at' => $now,
        ], array_slice($errors, 0, $remaining));

        DB::table('import_errors')->insert($rows);
        $this->errorCount += count($rows);
    }

    protected function assertNotCancellationRequested(): void
    {
        $status = DB::table('import_jobs')->where('id', $this->importJob->id)->value('status');
        if ($status === 'cancelamento_solicitado') {
            throw new ImportCancelledException('Cancelamento solicitado.');
        }
    }

    protected function advanceProgress(int $rows): void
    {
        if ($rows < 1) {
            return;
        }

        DB::table('import_jobs')
            ->where('id', $this->importJob->id)
            ->where('status', 'em_progresso')
            ->update([
                'processed_rows' => DB::raw('processed_rows + ' . $rows),
                'updated_at' => now(),
            ]);
    }

    protected function completeImport(): void
    {
        $job = DB::table('import_jobs')->where('id', $this->importJob->id)->first(['processed_rows', 'status']);
        if (($job->status ?? null) === 'cancelamento_solicitado') {
            throw new ImportCancelledException('Cancelamento solicitado.');
        }

        DB::table('import_jobs')
            ->where('id', $this->importJob->id)
            ->where('status', 'em_progresso')
            ->update([
                'total_rows' => (int) ($job->processed_rows ?? 0),
                'status' => 'concluido',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    protected static function normalizeCsvValue(string $value): string
    {
        if ($value !== '' && !mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
        return str_replace("\u{00A0}", ' ', $value);
    }

    protected static function normalizeHeaderLabel(string $value): string
    {
        $value = self::normalizeCsvValue($value);
        $normalized = \Illuminate\Support\Str::of($value)->ascii()->lower()->value();
        $normalized = preg_replace('/[^a-z0-9]+/u', '_', $normalized) ?? '';
        return trim((string) preg_replace('/_+/u', '_', $normalized), '_');
    }

    protected function csvDelimiter(): string
    {
        $configured = (string) config('leads.import.csv.delimiter', ';');
        return $configured !== '' ? $configured[0] : ';';
    }

    protected function csvEnclosure(): string
    {
        $configured = (string) config('leads.import.csv.enclosure', '"');
        return $configured !== '' ? $configured[0] : '"';
    }

    /** @param array<int, string> $row */
    protected function isCsvRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
