<?php

namespace App\Modules\V8\Services;

use App\Modules\V8\Jobs\ProcessV8ConsultJob;
use App\Modules\V8\Models\V8ConsultJob;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ResumeActiveV8ConsultJobsService
{
    public function handle(): array
    {
        $result = [
            'scanned' => 0,
            'dispatched' => 0,
        ];

        $queue = (string) config('v8.job.queue', 'v8');

        V8ConsultJob::query()
            ->where('executor', 'local')
            ->whereIn('status', ['pendente', 'em_progresso'])
            ->orderBy('id')
            ->get()
            ->each(function (V8ConsultJob $job) use (&$result, $queue): void {
                $result['scanned']++;

                $processJob = new ProcessV8ConsultJob($job->id);
                (new UniqueLock(Cache::driver()))->release($processJob);

                ProcessV8ConsultJob::dispatch($job->id)->onQueue($queue);
                $result['dispatched']++;
            });

        if ($result['dispatched'] > 0) {
            Log::warning('[V8] Recuperacao de jobs ativos despachada', $result);
        }

        return $result;
    }
}
