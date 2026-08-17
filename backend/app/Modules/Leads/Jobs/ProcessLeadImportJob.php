<?php

namespace App\Modules\Leads\Jobs;

use App\Models\ImportJob;
use App\Modules\Leads\Imports\CadastralImport;
use App\Modules\Leads\Imports\Exceptions\ImportCancelledException;
use App\Modules\Leads\Imports\Exceptions\ImportHeaderException;
use App\Modules\Leads\Imports\HigienizacaoImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessLeadImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;
    public int $tries = 1;

    public function __construct(public ImportJob $importJob)
    {
        $this->onQueue((string) config('leads.import.queue', 'imports'));
    }

    public function handle(): void
    {
        $this->importJob->refresh();
        $uploadPath = $this->importJob->file_path;

        try {
            if ($this->importJob->status === 'cancelamento_solicitado') {
                $this->startRollback('cancelado');
                return;
            }
            if ($this->importJob->status !== 'pendente') {
                return;
            }

            DB::table('import_jobs')->where('id', $this->importJob->id)->where('status', 'pendente')->update([
                'status' => 'em_progresso',
                'started_at' => $this->importJob->started_at ?? now(),
                'updated_at' => now(),
            ]);
            $this->importJob->refresh();
            if ($this->importJob->status === 'cancelamento_solicitado') {
                $this->startRollback('cancelado');
                return;
            }

            $path = $this->uploadedFilePath();
            $processor = match ($this->importJob->type) {
                'cadastral' => new CadastralImport($this->importJob),
                'higienizacao' => new HigienizacaoImport($this->importJob),
                default => throw new \InvalidArgumentException('Tipo de importação não suportado: ' . $this->importJob->type),
            };
            $processor->process($path);
        } catch (ImportHeaderException $e) {
            $this->markHeaderFailure($e);
        } catch (ImportCancelledException) {
            $this->startRollback('cancelado');
        } catch (Throwable $e) {
            $this->markFatalFailure($e);
        } finally {
            $this->deleteUploadedFile($uploadPath);
        }
    }

    private function markHeaderFailure(ImportHeaderException $e): void
    {
        DB::transaction(function () use ($e): void {
            $now = now();
            $rows = array_map(fn (string $header) => [
                'import_job_id' => $this->importJob->id,
                'row_number' => 1,
                'column_name' => $header,
                'error_message' => 'Cabeçalho ausente.',
                'created_at' => $now,
                'updated_at' => $now,
            ], $e->missing);
            if ($rows !== []) DB::table('import_errors')->insert($rows);
            DB::table('import_jobs')->where('id', $this->importJob->id)->update([
                'status' => 'falhou',
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function markFatalFailure(Throwable $e): void
    {
        Log::error('Falha na importação de leads.', ['import_job_id' => $this->importJob->id, 'exception' => $e]);
        DB::table('import_errors')->insert([
            'import_job_id' => $this->importJob->id,
            'row_number' => 0,
            'column_name' => 'Geral',
            'error_message' => 'Falha técnica durante a importação. As alterações serão revertidas.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->startRollback('falhou');
    }

    private function startRollback(string $finalStatus): void
    {
        if (DB::table('import_jobs')->where('id', $this->importJob->id)->value('status') === 'cancelamento_solicitado') {
            $finalStatus = 'cancelado';
        }

        $updated = DB::table('import_jobs')
            ->where('id', $this->importJob->id)
            ->whereIn('status', ['pendente', 'em_progresso', 'cancelamento_solicitado'])
            ->update([
                'status' => 'revertendo',
                'rollback_final_status' => $finalStatus,
                'updated_at' => now(),
            ]);

        if ($updated > 0) {
            RollbackLeadImportJob::dispatch($this->importJob->id);
        }
    }

    private function uploadedFilePath(): string
    {
        $disk = (string) config('leads.import.storage.disk', 'local');
        $path = $this->importJob->file_path;
        if (!Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException('Arquivo de importação não encontrado.');
        }
        $fullPath = Storage::disk($disk)->path($path);
        if (!is_file($fullPath) || !is_readable($fullPath)) {
            throw new \RuntimeException('Arquivo de importação ilegível.');
        }
        return $fullPath;
    }

    private function deleteUploadedFile(?string $path): void
    {
        if (!$path) return;
        $disk = (string) config('leads.import.storage.disk', 'local');
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $e) {
            Log::warning('Não foi possível apagar arquivo de importação.', ['import_job_id' => $this->importJob->id, 'exception' => $e]);
        }
    }
}
