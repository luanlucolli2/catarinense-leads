<?php

namespace App\Modules\V8\Jobs;

use App\Modules\V8\Models\V8ConsultJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchV8ConsultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(private int $jobId)
    {
    }

    public function handle(): void
    {
        $job = V8ConsultJob::query()->whereKey($this->jobId)->first();
        if ($job === null || $job->status !== 'pendente') {
            return;
        }

        ProcessV8ConsultJob::dispatch($job->id)
            ->onQueue((string) config('v8.job.queue', 'v8'));
    }
}
