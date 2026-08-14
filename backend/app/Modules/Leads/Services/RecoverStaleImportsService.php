<?php

namespace App\Modules\Leads\Services;

use App\Modules\Leads\Jobs\RollbackLeadImportJob;
use Illuminate\Support\Facades\DB;

class RecoverStaleImportsService
{
    public function handle(): int
    {
        $jobs = DB::table('import_jobs')
            ->whereIn('status', ['pendente', 'em_progresso', 'cancelamento_solicitado', 'revertendo'])
            ->orderBy('id')
            ->get(['id', 'status']);
        $recovered = 0;

        foreach ($jobs as $job) {
            $seconds = $job->status === 'pendente'
                ? max(3600, (int) config('leads.import.pending_stale_seconds', 86400))
                : max(60, (int) config('leads.import.stale_seconds', 900));
            $cutoff = now()->subSeconds($seconds);
            $isStale = DB::table('import_jobs')->where('id', $job->id)->where('updated_at', '<', $cutoff)->exists();
            if (!$isStale) {
                continue;
            }
            if ($job->status === 'revertendo') {
                DB::table('import_jobs')->where('id', $job->id)->update(['updated_at' => now()]);
                RollbackLeadImportJob::dispatch((int) $job->id);
                $recovered++;
                continue;
            }

            $updated = DB::table('import_jobs')
                ->where('id', $job->id)
                ->where('updated_at', '<', $cutoff)
                ->whereIn('status', ['pendente', 'em_progresso', 'cancelamento_solicitado'])
                ->update([
                    'status' => 'revertendo',
                    'rollback_final_status' => $job->status === 'cancelamento_solicitado' ? 'cancelado' : 'falhou',
                    'updated_at' => now(),
                ]);
            if ($updated > 0) {
                DB::table('import_errors')->insert([
                    'import_job_id' => $job->id,
                    'row_number' => 0,
                    'column_name' => 'Geral',
                    'error_message' => 'Importação interrompida por falta de atividade. As alterações serão revertidas.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                RollbackLeadImportJob::dispatch((int) $job->id);
                $recovered++;
            }
        }
        return $recovered;
    }
}
