<?php

namespace App\Modules\CLT\Services;

use App\Modules\CLT\Jobs\ProcessCltConsultJob;
use App\Modules\CLT\Models\CltConsultJob;
use App\Modules\CLT\Support\CltVariant;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ResumeActiveCltConsultJobsService
{
    public function handle(): array
    {
        $result = [
            'scanned' => 0,
            'dispatched' => 0,
        ];

        CltConsultJob::query()
            ->whereIn('status', ['pendente', 'em_progresso'])
            ->orderBy('id')
            ->get()
            ->each(function (CltConsultJob $job) use (&$result): void {
                $result['scanned']++;

                $stage = $job->phase === 'fase_2' && CltVariant::supportsCreditPhaseTwo($job->variant)
                    ? 'phase2'
                    : 'phase1';

                $queue = $stage === 'phase2'
                    ? (string) config('cltfacta.job.queue_phase2', 'clt-valida-politica-cred')
                    : CltVariant::resolvePhaseOneQueue($job->variant);

                $processJob = new ProcessCltConsultJob($job->id, $stage);
                (new UniqueLock(Cache::driver()))->release($processJob);

                ProcessCltConsultJob::dispatch($job->id, $stage)->onQueue($queue);
                $result['dispatched']++;
            });

        if ($result['dispatched'] > 0) {
            Log::warning('[CLT] Recuperacao de jobs ativos despachada', $result);
        }

        return $result;
    }
}
