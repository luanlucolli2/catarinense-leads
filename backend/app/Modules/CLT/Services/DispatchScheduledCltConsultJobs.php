<?php

namespace App\Modules\CLT\Services;

use App\Modules\CLT\Jobs\DispatchCltConsultJob;
use App\Modules\CLT\Models\CltConsultJob;
use App\Modules\CLT\Support\CltLog;
use App\Modules\CLT\Support\CltSpool;
use App\Modules\CLT\Support\CltVariant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DispatchScheduledCltConsultJobs
{
    private const BATCH_SIZE = 25;

    /**
     * @return array{scanned:int,dispatched:int,failed:int}
     */
    public function handle(): array
    {
        $nowUtc = Carbon::now('UTC');
        $candidateIds = CltConsultJob::query()
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

        $disk = Storage::disk((string) config('cltfacta.storage.reports_disk', 'local'));

        foreach ($candidateIds as $jobId) {
            $job = CltConsultJob::query()->whereKey($jobId)->first();
            if ($job === null || $job->status !== 'agendado') {
                continue;
            }

            $hasSpool = !empty($job->spool_path) && $disk->exists($job->spool_path);
            $hasCpfFile = !empty($job->spool_cpfs_path) && $disk->exists($job->spool_cpfs_path);

            if (!$hasSpool || !$hasCpfFile) {
                CltSpool::deleteArtifacts($disk, $job->spool_path ?? null, $job->spool_cpfs_path ?? null);
                $job->update([
                    'status' => 'falhou',
                    'phase' => null,
                    'finished_at' => Carbon::now(),
                    'spool_path' => null,
                    'spool_cpfs_path' => null,
                    'spool_bytes' => 0,
                ]);
                CltLog::error("[CLT] Job agendado {$job->id} sem spool/arquivo de CPFs ao iniciar.");
                $failed++;
                continue;
            }

            $claimed = CltConsultJob::query()
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
                $stage = CltVariant::isCreditPolicyOnly($job->variant) ? 'phase2' : 'phase1';
                $queue = $stage === 'phase2'
                    ? (string) config('cltfacta.job.queue_phase2', 'clt-valida-politica-cred')
                    : CltVariant::resolvePhaseOneQueue($job->variant);

                DispatchCltConsultJob::dispatch($job->id, $stage)->onQueue($queue);
                $dispatched++;
            } catch (Throwable $e) {
                CltConsultJob::query()
                    ->whereKey($job->id)
                    ->where('status', 'pendente')
                    ->whereNull('started_at')
                    ->update([
                        'status' => 'agendado',
                        'updated_at' => Carbon::now(),
                    ]);

                CltLog::error("[CLT] Falha ao despachar job agendado {$job->id}: " . $e->getMessage(), [
                    'exception' => $e,
                ]);
                $failed++;
            }
        }

        return compact('scanned', 'dispatched', 'failed');
    }
}
