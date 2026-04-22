<?php

namespace App\Modules\CLT\Jobs;

use App\Modules\CLT\Models\CltConsultJob;
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
        if ($job === null) {
            return;
        }

        if (!in_array($job->status, ['pendente', 'em_progresso'], true)) {
            return;
        }

        if ($job->status === 'em_progresso') {
            return;
        }

        ProcessCltConsultJob::dispatch($job->id, $this->stage)
            ->onQueue($this->resolveQueue($job->variant));
    }

    private function resolveQueue(?string $variant): string
    {
        return match ($variant) {
            'offline' => (string) config('cltfacta.job.queue_offline', 'clt-off'),
            'hybrid' => (string) config('cltfacta.job.queue_hybrid', config('cltfacta.job.queue_online', 'clt-consulta-online')),
            default => (string) config('cltfacta.job.queue_online', 'clt-consulta-online'),
        };
    }
}
