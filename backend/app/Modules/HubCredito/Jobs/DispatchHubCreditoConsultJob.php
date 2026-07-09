<?php

namespace App\Modules\HubCredito\Jobs;

use App\Modules\HubCredito\Models\HubCreditoConsultJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchHubCreditoConsultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(private int $jobId)
    {
    }

    public function handle(): void
    {
        $job = HubCreditoConsultJob::query()->whereKey($this->jobId)->first();
        if ($job === null || $job->status !== 'pendente') {
            return;
        }

        if ((bool) config('hubcredito.logging.enabled', false)) {
            Log::warning("[HUBCREDITO] Dispatch encaminhando processamento (job {$job->id})", [
                'queue' => (string) config('hubcredito.job.queue', 'hubcredito-clt'),
            ]);
        }

        ProcessHubCreditoConsultJob::dispatch($job->id)
            ->onQueue((string) config('hubcredito.job.queue', 'hubcredito-clt'));
    }
}
