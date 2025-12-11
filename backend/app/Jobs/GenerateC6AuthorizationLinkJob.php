<?php

namespace App\Jobs;

use App\Models\BankAuthorization;
use App\Services\C6\C6AuthorizationService;
use App\Services\Inovachat\TextMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

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
     * Gera o link no C6, registra autorização e envia ao cliente via Inovachat.
     */
    public function handle(
        C6AuthorizationService $c6,
        TextMessageService $texts
    ): void {
        $name = $this->firstName ?: ($this->fullName ?: 'Cliente');

        [$ddd, $localNumber] = $this->splitPhone($this->phone);

        try {
            $link = $c6->generateLink($this->cpf, $name, $ddd, $localNumber);

            // 1) Cria registro genérico de autorização para o banco C6
            $authorization = BankAuthorization::create([
                'tracking_id' => $this->trackingId,
                'bank'        => 'c6',
                'step'        => 'authorization',
                'cpf'         => $this->cpf,
                'phone'       => $this->phone,
                'link'        => $link,
                'status'      => BankAuthorization::STATUS_PENDING,
            ]);

            // 2) Envia mensagem com o link via Inovachat
            $body = sprintf(
                "%s, para seguir com a análise do seu crédito, acesse o link de autorização do C6 Bank:\n%s",
                $name,
                $link
            );

            $sent = false;

            // dentro do handle, no GenerateC6AuthorizationLinkJob:
            if ($this->phone) {
                $sent = $texts->sendText(
                    $this->phone,
                    $body,
                    '0', // não abre ticket
                    '0'  // fila ignorada quando openTicket = "0"
                );
            }


            // 3) Agenda primeiro polling do status da autorização
            $firstDelaySeconds = (int) env('C6_AUTH_STATUS_FIRST_POLL_DELAY', 60);

            CheckC6AuthorizationStatusJob::dispatch($authorization->id)
                ->delay(Carbon::now()->addSeconds($firstDelaySeconds));

            Log::info('GenerateC6AuthorizationLinkJob finished', [
                'tracking_id' => $this->trackingId,
                'cpf'         => $this->cpf,
                'link'        => $link,
                'phone'       => $this->phone,
                'sent'        => $sent,
                'authorization_id' => $authorization->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateC6AuthorizationLinkJob failed', [
                'tracking_id' => $this->trackingId,
                'cpf'         => $this->cpf,
                'exception'   => $e->getMessage(),
            ]);

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
