<?php

namespace App\Jobs;

use App\Services\C6\C6AuthorizationService;
use App\Services\Inovachat\TextMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateC6AuthorizationLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;
    public int $tries   = 3;
    public int $backoff = 10;

    private string $trackingId;
    private string $cpf;
    private ?string $firstName;
    private ?string $fullName;
    private ?string $phone;
    private string $openTicket;
    private string $queueId;

    public function __construct(
        string $trackingId,
        string $cpf,
        ?string $firstName,
        ?string $fullName,
        ?string $phone,
        string $openTicket = '0',
        string $queueId = '0'
    ) {
        $this->trackingId = $trackingId;
        $this->cpf        = $cpf;
        $this->firstName  = $firstName;
        $this->fullName   = $fullName;
        $this->phone      = $phone;
        $this->openTicket = $openTicket;
        $this->queueId    = $queueId;

        $queue          = config('c6bank.job.queue', 'c6-auth');
        $timeoutSeconds = (int) config('c6bank.job.timeout', 60);

        $this->onQueue($queue);
        $this->timeout = $timeoutSeconds;
    }

    /**
     * Gera o link no C6 e envia ao cliente via Inovachat.
     */
    public function handle(
        C6AuthorizationService $c6,
        TextMessageService $texts
    ): void {
        $name = $this->firstName ?: ($this->fullName ?: 'Cliente');

        [$ddd, $localNumber] = $this->splitPhone($this->phone);

        try {
            $link = $c6->generateLink($this->cpf, $name, $ddd, $localNumber);

            $body = sprintf(
                "%s, para seguir com a análise do seu crédito, acesse o link de autorização do C6 Bank:\n%s",
                $name,
                $link
            );

            $sent = false;

            if ($this->phone) {
                $sent = $texts->sendText(
                    $this->phone,
                    $body,
                    $this->openTicket,
                    $this->queueId
                );
            }

            Log::info('GenerateC6AuthorizationLinkJob finished', [
                'tracking_id' => $this->trackingId,
                'cpf'         => $this->cpf,
                'link'        => $link,
                'phone'       => $this->phone,
                'sent'        => $sent,
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateC6AuthorizationLinkJob failed', [
                'tracking_id' => $this->trackingId,
                'cpf'         => $this->cpf,
                'exception'   => $e->getMessage(),
            ]);

            // Deixa a exceção subir para respeitar $tries / $backoff do Laravel
            throw $e;
        }
    }

    /**
     * Divide o telefone em [DDD, número] para o payload opcional do C6.
     *
     * Aceita formatos:
     * - 55DDNNNNNNNNN
     * - 0DDNNNNNNNNN
     * - DDNNNNNNNNN
     */
    private function splitPhone(?string $raw): array
    {
        if (! $raw) {
            return [null, null];
        }

        $digits = preg_replace('/\D+/', '', $raw) ?: '';

        // remove código do Brasil (55), se presente
        if (str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) < 10) {
            // não conseguimos confiar na separação
            return [null, null];
        }

        $ddd    = substr($digits, 0, 2);
        $number = substr($digits, 2);

        return [$ddd, $number];
    }
}
