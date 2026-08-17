<?php

namespace App\Modules\Leads\Jobs;

use App\Models\ImportJob;
use App\Modules\Leads\Services\RollbackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RollbackLeadImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 0;
    public int $tries = 3;

    public function __construct(private readonly int $importJobId)
    {
        $this->onQueue((string) config('leads.import.queue', 'imports'));
    }

    public function handle(RollbackService $rollback): void
    {
        $job = ImportJob::findOrFail($this->importJobId);
        if ($job->status !== 'revertendo') return;
        $rollback->rollback($job, $job->rollback_final_status ?: 'revertido');
    }

    public function failed(Throwable $e): void
    {
        Log::critical('Falha no rollback de importação.', ['import_job_id' => $this->importJobId, 'exception' => $e]);
        DB::table('import_jobs')->where('id', $this->importJobId)->where('status', 'revertendo')->update([
            'status' => 'rollback_falhou',
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
