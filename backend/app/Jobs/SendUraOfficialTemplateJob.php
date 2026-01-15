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
        $this->tries = (int) config('ura.job_tries', 3);
        $this->backoff = (int) config('ura.job_backoff_seconds', 10);
    }

    public function handle(OfficialTemplateService $service): void
    {
        $result = $service->sendOfficialTemplateWithoutVariables(
            number: $this->number,
            templateName: $this->templateName,
            language: $this->language,
            trackingId: $this->trackingId,
        );

        Log::info('URA_SEND_OFFICIAL_OK', [
            'tracking' => $this->trackingId,
            'attempt'  => $this->attempts(),
            'token'    => $result['token'] ?? null,   // sem censura
            'status'   => $result['status'] ?? null,  // 200
            'number'   => $this->number,
            'template' => $this->templateName,
            'lang'     => $this->language,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('URA_SEND_OFFICIAL_FAILED', [
            'tracking' => $this->trackingId,
            'number'   => $this->number,
            'template' => $this->templateName,
            'lang'     => $this->language,
            'error'    => $e->getMessage(),
        ]);
    }
}
