<?php

namespace App\Modules\CLT\Jobs;

use App\Modules\CLT\Models\CltConsultJob;
use App\Modules\CLT\Support\CltVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchCltConsultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(
        private int $jobId,
        private string $stage = 'phase1'
    ) {
    }

    public function handle(): void
    {
        $job = CltConsultJob::query()->whereKey($this->jobId)->first();
        if ($job === null || $job->status !== 'pendente') {
            return;
        }

        ProcessCltConsultJob::dispatch($job->id, $this->stage)
            ->onQueue(CltVariant::resolvePhaseOneQueue($job->variant));
    }
}
