<?php

namespace App\Jobs;

use App\Services\Inovachat\OfficialTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendUraOfficialTemplateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;
    public int $backoff;

    public function __construct(
        public readonly string $number,
        public readonly string $templateName,
        public readonly string $language,
        public readonly string $trackingId,
    ) {
        // Regras conservadoras: API pode oscilar e você já usa retry em outros pontos
        $this->tries = (int) config('ura.job_tries', 3);
        $this->backoff = (int) config('ura.job_backoff_seconds', 10);
    }

    public function handle(OfficialTemplateService $service): void
    {
        Log::info('URA official template send queued', [
            'tracking_id' => $this->trackingId,
            'number'      => $this->number,
            'template'    => $this->templateName,
            'language'    => $this->language,
            'attempt'     => $this->attempts(),
        ]);

        $service->sendOfficialTemplateWithoutVariables(
            number: $this->number,
            templateName: $this->templateName,
            language: $this->language,
            trackingId: $this->trackingId,
        );

        Log::info('URA official template send success', [
            'tracking_id' => $this->trackingId,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('URA official template send failed', [
            'tracking_id' => $this->trackingId,
            'number'      => $this->number,
            'template'    => $this->templateName,
            'language'    => $this->language,
            'error'       => $e->getMessage(),
        ]);
    }
}
