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

    /**
     * ✅ ÚNICA fonte de verdade para tentativas/backoff (sem redundância no worker).
     * Total máximo: 3 tentativas.
     */
    public int $tries;
    public int $backoff;

    public function __construct(
        public readonly string $number,
        public readonly string $templateName,
        public readonly string $language,
        public readonly string $trackingId,
    ) {
        $this->tries = (int) config('ura.job_tries', 3);               // default 3
        $this->backoff = (int) config('ura.job_backoff_seconds', 10); // default 10s
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

        try {
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
                'token'    => $result['token'] ?? null, // sem censura (como você pediu)
            ]);
        } catch (Throwable $e) {
            // Log simples por tentativa (útil pra bater o olho).
            // O retry/falha final fica por conta do Worker/Laravel (sem lógica duplicada aqui).
            Log::warning('URA_SEND_OFFICIAL_ATTEMPT_FAIL', [
                'tracking' => $this->trackingId,
                'attempt'  => $this->attempts(),
                'error'    => $e->getMessage(),
            ]);

            throw $e; // rethrow => Laravel reagenda até $tries
        }
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
