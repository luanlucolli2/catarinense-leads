<?php

namespace App\Services\MultiConsulta;

use App\Modules\Presenca\Jobs\StorePresencaExternalReportJob;
use App\Modules\Presenca\Models\PresencaConsultJob;
use App\Modules\Presenca\Services\PresencaExternalApiService;
use App\Modules\SomaClt\Jobs\StoreSomaCltExternalReportJob;
use App\Modules\SomaClt\Models\SomaCltConsultJob;
use App\Modules\SomaClt\Services\SomaCltExternalApiService;
use App\Modules\V8\Jobs\StoreV8ExternalReportJob;
use App\Modules\V8\Models\V8ConsultJob;
use App\Modules\V8\Services\V8ExternalApiService;
use App\Modules\V8Fgts\Jobs\StoreV8FgtsExternalReportJob;
use App\Modules\V8Fgts\Models\V8FgtsConsultJob;
use App\Modules\V8Fgts\Services\V8FgtsExternalApiService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncActiveExternalConsultJobsService
{
    private const ACTIVE_STATUSES = ['agendado', 'pendente', 'em_progresso', 'pausado'];

    public function handle(): array
    {
        return [
            'v8' => $this->syncV8(),
            'v8_fgts' => $this->syncV8Fgts(),
            'presenca' => $this->syncPresenca(),
            'soma_clt' => $this->syncSomaClt(),
        ];
    }

    private function syncV8(): int
    {
        $api = app(V8ExternalApiService::class);

        return $this->sync($this->active(V8ConsultJob::class), function (V8ConsultJob $job) use ($api): void {
            $remote = $api->getJob((string) $job->external_job_id);
            $metrics = (array) ($remote['metrics'] ?? []);
            $status = $this->status($remote);
            $hasReport = (bool) ($remote['has_report'] ?? false);

            $job->update([
                ...$this->common($job, $remote, $status, $hasReport),
                'phase' => match ($remote['phase'] ?? null) { 'phase_1' => 'fase_1', 'phase_2' => 'fase_2', default => null },
                'success_count' => max(0, (int) ($metrics['phase2.approved'] ?? 0)),
                'nao_elegivel_count' => max(0, (int) ($metrics['phase1.not_eligible'] ?? 0)) + max(0, (int) ($metrics['phase2.not_approved'] ?? 0)),
                'fail_count' => max(0, (int) ($metrics['phase1.errors'] ?? 0)) + max(0, (int) ($metrics['phase2.errors'] ?? 0)),
                'phase1_submitted_count' => max(0, (int) ($metrics['phase1.submitted'] ?? 0)),
                'phase1_not_eligible_count' => max(0, (int) ($metrics['phase1.not_eligible'] ?? 0)),
                'phase1_errors_count' => max(0, (int) ($metrics['phase1.errors'] ?? 0)),
                'phase2_approved_count' => max(0, (int) ($metrics['phase2.approved'] ?? 0)),
                'phase2_not_approved_count' => max(0, (int) ($metrics['phase2.not_approved'] ?? 0)),
                'phase2_errors_count' => max(0, (int) ($metrics['phase2.errors'] ?? 0)),
                'scheduled_for' => $remote['scheduled_for'] ?? $job->scheduled_for,
                'paused_at' => $remote['paused_at'] ?? ($status === 'pausado' ? ($job->paused_at ?? now()) : null),
            ]);

            if ($hasReport && !$this->hasStoredReport($job)) {
                StoreV8ExternalReportJob::dispatch($job->id);
            }
        }, 'V8');
    }

    private function syncV8Fgts(): int
    {
        $api = app(V8FgtsExternalApiService::class);

        return $this->sync($this->active(V8FgtsConsultJob::class), function (V8FgtsConsultJob $job) use ($api): void {
            $remote = $api->getJob((string) $job->external_job_id);
            $metrics = (array) ($remote['metrics'] ?? []);
            $pipeline = (array) ($metrics['pipeline'] ?? []);
            $status = $this->status($remote);
            $hasReport = (bool) ($remote['has_report'] ?? false);

            $job->update([
                ...$this->common($job, $remote, $status, $hasReport),
                'phase' => is_string($remote['phase'] ?? null) ? $remote['phase'] : null,
                'success_count' => max(0, (int) ($pipeline['success'] ?? $metrics['pipeline.success'] ?? 0)),
                'nao_elegivel_count' => max(0, (int) ($pipeline['not_eligible'] ?? $metrics['pipeline.not_eligible'] ?? 0)),
                'fail_count' => max(0, (int) ($pipeline['errors'] ?? $metrics['pipeline.errors'] ?? 0)),
            ]);

            if ($hasReport && !$this->hasStoredReport($job)) {
                StoreV8FgtsExternalReportJob::dispatch($job->id);
            }
        }, 'V8 FGTS');
    }

    private function syncPresenca(): int
    {
        $api = app(PresencaExternalApiService::class);

        return $this->sync($this->active(PresencaConsultJob::class), function (PresencaConsultJob $job) use ($api): void {
            $remote = $api->getJob((string) $job->external_job_id);
            $metrics = (array) ($remote['metrics'] ?? []);
            $status = $this->status($remote);
            $hasReport = (bool) ($remote['has_report'] ?? false);

            $job->update([
                ...$this->common($job, $remote, $status, $hasReport),
                'phase' => ($remote['phase'] ?? null) === 'phase_1' ? 'processando' : null,
                'success_count' => max(0, (int) ($metrics['phase1.success'] ?? 0)),
                'policy_declined_count' => max(0, (int) ($metrics['phase1.policy_declined'] ?? 0)),
                'fail_count' => max(0, (int) ($metrics['phase1.errors'] ?? 0)),
                'scheduled_for' => $remote['scheduled_for'] ?? $job->scheduled_for,
                'paused_at' => $remote['paused_at'] ?? ($status === 'pausado' ? ($job->paused_at ?? now()) : null),
            ]);

            if ($hasReport && !$this->hasStoredReport($job)) {
                StorePresencaExternalReportJob::dispatch($job->id);
            }
        }, 'Presença');
    }

    private function syncSomaClt(): int
    {
        $api = app(SomaCltExternalApiService::class);

        return $this->sync($this->active(SomaCltConsultJob::class), function (SomaCltConsultJob $job) use ($api): void {
            $remote = $api->getJob((string) $job->external_job_id);
            $metrics = (array) ($remote['metrics'] ?? []);
            $status = $this->status($remote);
            $hasReport = (bool) ($remote['has_report'] ?? false);

            $job->update([
                ...$this->common($job, $remote, $status, $hasReport),
                'phase' => ($remote['phase'] ?? null) === 'phase_1' ? 'processando' : null,
                'success_count' => max(0, (int) ($metrics['phase1.success'] ?? 0)),
                'policy_declined_count' => max(0, (int) ($metrics['phase1.declined'] ?? 0)),
                'fail_count' => max(0, (int) ($metrics['phase1.errors'] ?? 0)),
                'scheduled_for' => $remote['scheduled_for'] ?? $job->scheduled_for,
                'paused_at' => $remote['paused_at'] ?? ($status === 'pausado' ? ($job->paused_at ?? now()) : null),
            ]);

            if ($hasReport && !$this->hasStoredReport($job)) {
                StoreSomaCltExternalReportJob::dispatch($job->id);
            }
        }, 'Soma CLT');
    }

    private function active(string $model): \Illuminate\Database\Eloquent\Collection
    {
        return $model::query()
            ->where('executor', 'api')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->get();
    }

    private function sync(\Illuminate\Database\Eloquent\Collection $jobs, callable $sync, string $module): int
    {
        $synced = 0;

        foreach ($jobs as $job) {
            try {
                $sync($job);
                $synced++;
            } catch (\Throwable $e) {
                Log::warning("[{$module}] Falha ao sincronizar job externo {$job->id} após deploy: {$e->getMessage()}");
            }
        }

        return $synced;
    }

    private function status(array $remote): string
    {
        return match ((string) ($remote['status'] ?? 'queued')) {
            'scheduled' => 'agendado', 'completed' => 'concluido', 'failed', 'expired' => 'falhou',
            'cancelled' => 'cancelado', 'paused' => 'pausado', 'running', 'pausing' => 'em_progresso', default => 'pendente',
        };
    }

    private function common(Model $job, array $remote, string $status, bool $hasReport): array
    {
        $terminal = in_array($status, ['concluido', 'falhou', 'cancelado'], true);

        return [
            'status' => $status,
            'total_cpfs' => max(0, (int) ($remote['total_count'] ?? data_get($remote, 'progress.phase_1.total') ?? 0)),
            'external_has_report' => $hasReport,
            'started_at' => $remote['started_at'] ?? $job->started_at ?? ($status === 'em_progresso' ? now() : null),
            'finished_at' => $remote['finished_at'] ?? ($terminal && ($status !== 'cancelado' || $hasReport) ? ($job->finished_at ?? now()) : null),
            'canceled_at' => $remote['cancelled_at'] ?? $remote['canceled_at'] ?? ($status === 'cancelado' ? ($job->canceled_at ?? now()) : null),
        ];
    }

    private function hasStoredReport(Model $job): bool
    {
        return $job->file_disk && $job->file_path && Storage::disk($job->file_disk)->exists($job->file_path);
    }
}
