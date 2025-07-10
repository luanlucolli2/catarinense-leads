<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Imports\CadastralImport;
use App\Imports\HigienizacaoImport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        // marca início
        $this->importJob->update([
            'status'     => 'em_progresso',
            'started_at' => now(),
        ]);

        try {
            $importer = $this->importJob->type === 'cadastral'
                ? new CadastralImport($this->importJob)
                : new HigienizacaoImport($this->importJob);

            // despacha a importação (cada chunk vira um job ReadChunk)
            // NÃO atualiza processed_rows aqui
            Excel::import($importer, $this->importJob->file_path);

        } catch (Throwable $e) {
            // se algo falhar antes de despachar, marca como falho
            $this->importJob->update([
                'status'      => 'falhou',
                'finished_at' => now(),
            ]);
            Log::error(
                "Falha na importação do Job ID {$this->importJob->id}: "
                . $e->getMessage()
            );
        }
    }
}
