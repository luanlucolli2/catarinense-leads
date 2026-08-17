<?php

namespace App\Modules\HubCredito\Jobs;

use App\Modules\HubCredito\Models\HubCreditoConsultJob;
use App\Modules\HubCredito\Services\HubCreditoExternalApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class StoreHubCreditoExternalReportJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $uniqueFor = 3600;
    public function __construct(public int $jobId) { $this->onQueue('reports'); }
    public function uniqueId(): string { return (string) $this->jobId; }

    public function handle(HubCreditoExternalApiService $api): void
    {
        $job = HubCreditoConsultJob::find($this->jobId);
        if (!$job || $job->executor !== 'api' || !$job->external_has_report || $this->stored($job)) return;
        $response = $api->report((string) $job->external_job_id);
        if (!$response->successful()) throw new \RuntimeException('Relatório externo Hub Crédito indisponível.');
        $diskName = (string) config('hubcredito.storage.reports_disk', 'local');
        $disk = Storage::disk($diskName);
        $dir = 'hubcredito-reports';
        if (!$disk->exists($dir)) $disk->makeDirectory($dir);
        $name = "{$job->id}-hubcredito-relatorio.csv";
        $path = "{$dir}/{$name}";
        $disk->put($path, $response->body());
        if (!$disk->exists($path)) throw new \RuntimeException('Falha ao armazenar relatório externo Hub Crédito.');
        $job->update(['file_disk' => $diskName, 'file_path' => $path, 'file_name' => $name]);
    }

    private function stored(HubCreditoConsultJob $job): bool
    {
        return $job->file_disk && $job->file_path && Storage::disk($job->file_disk)->exists($job->file_path);
    }
}
