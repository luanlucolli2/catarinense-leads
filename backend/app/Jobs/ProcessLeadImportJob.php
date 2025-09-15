<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Imports\CadastralImport;
use App\Imports\HigienizacaoImport;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelReaderType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use App\Services\BackupService;

class ProcessLeadImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ImportJob $importJob;

    public function __construct(ImportJob $importJob)
    {
        $this->importJob = $importJob;
    }

    public function handle(): void
    {
        $this->importJob->update([
            'status'     => 'em_progresso',
            'started_at' => now(),
        ]);

        try {
            // 1) Tenta no disco 'local' (onde salvamos no controller)
            $disk = 'local';
            $path = $this->importJob->file_path;

            $exists = Storage::disk($disk)->exists($path);
            $fullPath = $exists ? Storage::disk($disk)->path($path) : null;

            // 2) Fallback: caso algum ambiente tenha salvo em 'public'
            if (!$exists) {
                if (Storage::disk('public')->exists($path)) {
                    $disk = 'public';
                    $fullPath = Storage::disk('public')->path($path);
                    $exists = true;
                }
            }

            if (!$exists || !$fullPath || !is_file($fullPath) || !is_readable($fullPath)) {
                throw new \RuntimeException('Arquivo de importação não encontrado ou ilegível: ' . ($fullPath ?? $path));
            }

            /** @var BackupService $backup */
            $backup = app(BackupService::class);
            $backup->purgeOldBackups();

            $importer = $this->importJob->type === 'cadastral'
                ? new CadastralImport($this->importJob, $backup)
                : new HigienizacaoImport($this->importJob, $backup);

            // Define o readerType a partir da extensão original
            $ext = strtolower(pathinfo($this->importJob->file_name ?? $fullPath, PATHINFO_EXTENSION));
            $readerType = $ext === 'xls' ? ExcelReaderType::XLS : ExcelReaderType::XLSX;

            // Importa informando explicitamente o tipo
            Excel::import($importer, $fullPath, null, $readerType);

        } catch (Throwable $e) {
            $this->importJob->update([
                'status'      => 'falhou',
                'finished_at' => now(),
            ]);
            Log::error("Falha na importação do Job ID {$this->importJob->id}: " . $e->getMessage());
        }
    }
}
