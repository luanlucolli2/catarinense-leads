<?php

namespace App\Modules\V8\Jobs;

use App\Modules\V8\Models\V8ConsultJob;
use App\Modules\V8\Services\V8ExternalApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class StoreV8ExternalReportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $uniqueFor = 3600;

    public function __construct(public int $jobId)
    {
        $this->onQueue((string) config('v8.preview.queue', 'reports'));
    }

    public function uniqueId(): string
    {
        return (string) $this->jobId;
    }

    public function handle(V8ExternalApiService $api): void
    {
        $job = V8ConsultJob::query()->find($this->jobId);
        if (!$job || $job->executor !== 'api' || !$job->external_has_report || $this->hasStoredReport($job)) {
            return;
        }

        $response = $api->report((string) $job->external_job_id);
        if (!$response->successful()) {
            throw new \RuntimeException('Relatório externo V8 CLT indisponível.');
        }

        $diskName = (string) config('v8.storage.reports_disk', 'local');
        $disk = Storage::disk($diskName);
        $dir = (string) config('v8.storage.dir_reports', 'v8-reports');
        $fileName = "{$job->id}-" . $this->filename($response->header('Content-Disposition'), 'relatorio.csv');
        $path = "{$dir}/{$fileName}";

        if (!$disk->exists($dir)) {
            $disk->makeDirectory($dir);
        }
        $disk->put($path, $response->body());

        if (!$disk->exists($path)) {
            throw new \RuntimeException('Falha ao armazenar relatório externo V8 CLT.');
        }

        $job->update(['file_disk' => $diskName, 'file_path' => $path, 'file_name' => $fileName]);
    }

    private function hasStoredReport(V8ConsultJob $job): bool
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
