<?php

namespace App\Modules\Presenca\Jobs;

use App\Modules\Presenca\Models\PresencaConsultJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchPresencaConsultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(private int $jobId)
    {
    }

    public function handle(): void
    {
        $job = PresencaConsultJob::query()->whereKey($this->jobId)->first();
        if ($job === null || $job->status !== 'pendente') {
            return;
        }

        ProcessPresencaConsultJob::dispatch($job->id)
            ->onQueue((string) config('presenca.job.queue', 'presenca'));
    }
}
