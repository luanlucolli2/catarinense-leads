<?php

namespace App\Modules\Presenca\Services;

use App\Modules\Presenca\Jobs\ProcessPresencaConsultJob;
use App\Modules\Presenca\Models\PresencaConsultJob;
use App\Modules\Presenca\Support\PresencaLog;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;

class ResumeActivePresencaConsultJobsService
{
    public function handle(): array
    {
        $result = [
            'scanned' => 0,
            'dispatched' => 0,
        ];

        $queue = (string) config('presenca.job.queue', 'presenca');

        PresencaConsultJob::query()
            ->where('executor', 'local')
            ->whereIn('status', ['pendente', 'em_progresso'])
            ->orderBy('id')
            ->get()
            ->each(function (PresencaConsultJob $job) use (&$result, $queue): void {
                $result['scanned']++;

                $processJob = new ProcessPresencaConsultJob($job->id);
                (new UniqueLock(Cache::driver()))->release($processJob);

                ProcessPresencaConsultJob::dispatch($job->id)->onQueue($queue);
                $result['dispatched']++;
            });

        if ($result['dispatched'] > 0) {
            PresencaLog::warning('[PRESENCA] Recuperacao de jobs ativos despachada', $result);
        }

        return $result;
    }
}
