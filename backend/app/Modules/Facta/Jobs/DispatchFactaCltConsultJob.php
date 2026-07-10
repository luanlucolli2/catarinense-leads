<?php

namespace App\Modules\Facta\Jobs;

use App\Modules\Facta\Models\FactaCltConsultJob;
use App\Modules\Facta\Support\FactaCltVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchFactaCltConsultJob implements ShouldQueue
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
        $job = FactaCltConsultJob::query()->whereKey($this->jobId)->first();
        if ($job === null || $job->status !== 'pendente') {
            return;
        }

        $queue = $this->stage === 'phase2'
            ? (string) config('facta.job.queue_phase2', 'facta-clt-valida-politica-cred')
            : FactaCltVariant::resolvePhaseOneQueue($job->variant);

        ProcessFactaCltConsultJob::dispatch($job->id, $this->stage)
            ->onQueue($queue);
    }
}
