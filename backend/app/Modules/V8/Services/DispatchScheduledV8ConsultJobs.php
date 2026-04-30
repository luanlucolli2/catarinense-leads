<?php

namespace App\Modules\V8\Services;

use App\Modules\V8\Jobs\DispatchV8ConsultJob;
use App\Modules\V8\Models\V8ConsultJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DispatchScheduledV8ConsultJobs
{
    private const BATCH_SIZE = 25;

    /**
     * @return array{scanned:int,dispatched:int,failed:int}
     */
    public function handle(): array
    {
        $nowUtc = Carbon::now('UTC');

        $candidateIds = V8ConsultJob::query()
            ->where('status', 'agendado')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', $nowUtc)
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->pluck('id');

        $scanned = $candidateIds->count();
        $dispatched = 0;
        $failed = 0;

        if ($scanned === 0) {
            return compact('scanned', 'dispatched', 'failed');
        }

        $disk = Storage::disk((string) config('v8.storage.reports_disk', 'local'));

        foreach ($candidateIds as $jobId) {
            $job = V8ConsultJob::query()->whereKey($jobId)->first();
            if ($job === null || $job->status !== 'agendado') {
                continue;
            }

            $hasSpool = !empty($job->spool_path) && $disk->exists($job->spool_path);
            $hasInputs = !empty($job->spool_inputs_path) && $disk->exists($job->spool_inputs_path);

            if (!$hasSpool || !$hasInputs) {
                try {
                    foreach ([$job->spool_path, $job->spool_inputs_path] as $path) {
                        if ($path && $disk->exists($path)) {
                            $disk->delete($path);
                        }
                    }
                } catch (Throwable) {
                }

                $job->update([
                    'status' => 'falhou',
                    'phase' => null,
                    'finished_at' => Carbon::now(),
                    'spool_path' => null,
                    'spool_inputs_path' => null,
                    'spool_bytes' => 0,
                ]);

                Log::error("[V8] Job agendado {$job->id} sem spool/arquivo de entradas ao iniciar.");
                $failed++;
                continue;
            }

            $claimed = V8ConsultJob::query()
                ->whereKey($job->id)
                ->where('status', 'agendado')
                ->whereNotNull('scheduled_for')
                ->where('scheduled_for', '<=', $nowUtc)
                ->update([
                    'status' => 'pendente',
                    'paused_at' => null,
                    'canceled_at' => null,
                    'cancel_reason' => null,
                    'finished_at' => null,
                    'updated_at' => Carbon::now(),
                ]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                DispatchV8ConsultJob::dispatch($job->id)
                    ->onQueue((string) config('v8.job.queue', 'v8'));
                $dispatched++;
            } catch (Throwable $e) {
                V8ConsultJob::query()
                    ->whereKey($job->id)
                    ->where('status', 'pendente')
                    ->whereNull('started_at')
                    ->update([
                        'status' => 'agendado',
                        'updated_at' => Carbon::now(),
                    ]);

                Log::error("[V8] Falha ao despachar job agendado {$job->id}: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
                $failed++;
            }
        }

        return compact('scanned', 'dispatched', 'failed');
    }
}
