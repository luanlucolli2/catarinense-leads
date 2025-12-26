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
        Log::info('URA_SEND_OFFICIAL_START', [
            'tracking' => $this->trackingId,
            'attempt'  => $this->attempts(),
            'number'   => $this->number,
            'template' => $this->templateName,
            'lang'     => $this->language,
        ]);

        $result = $service->sendOfficialTemplateWithoutVariables(
            number: $this->number,
            templateName: $this->templateName,
            language: $this->language,
            trackingId: $this->trackingId,
        );

        Log::info('URA_SEND_OFFICIAL_DONE', [
            'tracking' => $this->trackingId,
            'attempt'  => $this->attempts(),
            'status'   => $result['status'] ?? null,
            'ok_200'   => $result['ok_200'] ?? null,
            'ok'       => $result['ok'] ?? null,
            'token'    => $result['token'] ?? null, // ✅ SEM CENSURA (como você pediu)
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
