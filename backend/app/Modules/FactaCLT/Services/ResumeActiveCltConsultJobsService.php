<?php

namespace App\Modules\FactaCLT\Services;

use App\Modules\FactaCLT\Jobs\DispatchFactaCltConsultJob;
use App\Modules\FactaCLT\Jobs\ProcessFactaCltConsultJob;
use App\Modules\FactaCLT\Models\FactaCltConsultJob;
use App\Modules\FactaCLT\Support\FactaCltVariant;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResumeActiveCltConsultJobsService
{
    public function handle(): array
    {
        $result = ['scanned' => 0, 'dispatched' => 0];

        FactaCltConsultJob::query()
            ->whereIn('status', ['pendente', 'em_progresso'])
            ->orderBy('id')
            ->get()
            ->each(function (FactaCltConsultJob $job) use (&$result): void {
                $result['scanned']++;

                $stage = $job->phase === 'fase_2' && FactaCltVariant::supportsCreditPhaseTwo($job->variant)
                    ? 'phase2'
                    : 'phase1';

                $queue = $stage === 'phase2'
                    ? (string) config('facta.job.queue_phase2', 'facta-clt-valida-politica-cred')
                    : FactaCltVariant::resolvePhaseOneQueue($job->variant);

                DB::table('facta_clt_consult_jobs')
                    ->where('id', $job->id)
                    ->whereIn('status', ['pendente', 'em_progresso'])
                    ->update([
                        'status' => 'pendente',
                        'run_token' => DB::raw('COALESCE(run_token, 0) + 1'),
                        'updated_at' => now(),
                    ]);

                $processJob = new ProcessFactaCltConsultJob($job->id, $stage);
                (new UniqueLock(Cache::driver()))->release($processJob);

                DispatchFactaCltConsultJob::dispatch($job->id, $stage)->onQueue($queue);
                $result['dispatched']++;
            });

        if ($result['dispatched'] > 0) {
            Log::warning('[FACTA CLT] Recuperacao de jobs ativos despachada', $result);
        }

        return $result;
    }
}
