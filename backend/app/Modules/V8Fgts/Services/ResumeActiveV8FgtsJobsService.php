<?php

namespace App\Modules\V8Fgts\Services;

use App\Modules\V8Fgts\Jobs\ProcessV8FgtsConsultBatchJob;
use App\Modules\V8Fgts\Jobs\ProcessV8FgtsConsultJob;
use App\Modules\V8Fgts\Models\V8FgtsConsultJob;
use Illuminate\Support\Facades\Log;

class ResumeActiveV8FgtsJobsService
{
    public function handle(): array
    {
        $result = [
            'scanned' => 0,
            'prepare_dispatched' => 0,
            'batch_dispatched' => 0,
        ];

        $queue = (string) config('v8_fgts.job.queue', 'fgts');

        V8FgtsConsultJob::query()
            ->whereIn('status', ['pendente', 'em_progresso'])
            ->orderBy('id')
            ->get()
            ->each(function (V8FgtsConsultJob $job) use (&$result, $queue): void {
                $result['scanned']++;

                $shouldResumePrepare = $job->phase === 'preparando_itens'
                    || (int) ($job->total_cpfs ?? 0) <= 0
                    || empty($job->spool_cpfs_path);

                if ($shouldResumePrepare) {
                    ProcessV8FgtsConsultJob::dispatch($job->id)->onQueue($queue);
                    $result['prepare_dispatched']++;
                    return;
                }

                ProcessV8FgtsConsultBatchJob::dispatch($job->id)->onQueue($queue);
                $result['batch_dispatched']++;
            });

        if (($result['prepare_dispatched'] + $result['batch_dispatched']) > 0) {
            Log::warning('[V8-FGTS] Recuperacao de jobs ativos despachada', $result);
        }

        return $result;
    }
}
