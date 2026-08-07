<?php

namespace App\Modules\SomaClt\Jobs;

use App\Modules\SomaClt\Models\SomaCltConsultJob;
use App\Modules\SomaClt\Services\SomaCltExternalApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class StoreSomaCltExternalReportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $uniqueFor = 3600;

    public function __construct(public int $jobId)
    {
        $this->onQueue((string) config('soma_clt.queue', 'reports'));
    }

    public function uniqueId(): string
    {
        return (string) $this->jobId;
    }

    public function handle(SomaCltExternalApiService $api): void
    {
        $job = SomaCltConsultJob::query()->find($this->jobId);
        if (! $job || ! $job->external_has_report || $this->hasStoredReport($job)) {
            return;
        }

        $response = $api->report($job->external_job_id);
        if (! $response->successful()) {
            throw new \RuntimeException('Relatório externo Soma CLT indisponível.');
        }

        $diskName = (string) config('soma_clt.storage.reports_disk', 'local');
        $disk = Storage::disk($diskName);
        $directory = (string) config('soma_clt.storage.dir_reports', 'soma-clt-reports');
        $fileName = "{$job->id}-" . $this->filename($response->header('Content-Disposition'), 'relatorio.csv');
        $path = "{$directory}/{$fileName}";

        if (! $disk->exists($directory)) {
            $disk->makeDirectory($directory);
        }
        $disk->put($path, $response->body());

        if (! $disk->exists($path)) {
            throw new \RuntimeException('Falha ao armazenar relatório externo Soma CLT.');
        }

        $job->update(['file_disk' => $diskName, 'file_path' => $path, 'file_name' => $fileName]);
    }

    private function hasStoredReport(SomaCltConsultJob $job): bool
    {
        return $job->file_disk && $job->file_path && Storage::disk($job->file_disk)->exists($job->file_path);
    }

    private function filename(?string $contentDisposition, string $fallback): string
    {
        if (is_string($contentDisposition) && preg_match('/filename\\*?=(?:UTF-8\\x27\\x27|\")?([^\";]+)/i', $contentDisposition, $matches) === 1) {
            return basename(trim(rawurldecode($matches[1]), " \""));
        }

        return $fallback;
    }
}
