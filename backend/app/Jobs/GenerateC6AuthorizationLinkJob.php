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
use Illuminate\Support\Carbon;
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

    // token da conexão (mesmo valor do token_origin)
    private string $connectionToken;

    public function __construct(
        string $trackingId,
        string $cpf,
        ?string $firstName,
        ?string $fullName,
        ?string $phone,
        string $openTicket = '0',
        string $queueId = '0',
        string $connectionToken = ''
    ) {
        $this->trackingId = $trackingId;
        $this->cpf        = $cpf;
        $this->firstName  = $firstName;
        $this->fullName   = $fullName;
        $this->phone      = $phone;
        $this->openTicket = $openTicket;
        $this->queueId    = $queueId;
        $this->connectionToken = $connectionToken;

        $queue          = config('c6bank.job.queue', 'c6-auth');
        $timeoutSeconds = (int) config('c6bank.job.timeout', 60);

        $this->onQueue($queue);
        $this->timeout = $timeoutSeconds;
    }

    public function handle(
        C6AuthorizationService $c6,
        TextMessageService $texts
    ): void {
        $name = $this->firstName ?: ($this->fullName ?: 'Cliente');

        [$ddd, $localNumber] = $this->splitPhone($this->phone);

        try {
            $link = $c6->generateLink($this->cpf, $name, $ddd, $localNumber);

            $authorization = BankAuthorization::create([
                'tracking_id' => $this->trackingId,
                'bank'        => 'c6',
                'step'        => 'authorization',
                'cpf'         => $this->cpf,
                'phone'       => $this->phone,
                'link'        => $link,
                'status'      => BankAuthorization::STATUS_PENDING,
            ]);

            $maxWaitMinutes = (int) config('c6bank.authorization.max_wait_minutes', 20);
            $maxWaitMinutes = max(1, $maxWaitMinutes);

            $body =
                "Oi, {$name}!\n\n"
                . "Para eu continuar sua análise de crédito, preciso de uma autorização rápida no C6 Bank ✅\n\n"
                . "🔗 Toque no link e confirme:\n{$link}\n\n"
                . "⏱️ Vou acompanhar por até {$maxWaitMinutes} min e te aviso assim que liberar.\n"
                . "Se já autorizou e me mandar mensagem aqui, eu confiro na hora. 🙌";

            $sent = false;

            if ($this->phone) {
                $sent = $texts->sendText(
                    number: $this->phone,
                    body: $body,
                    openTicket: '0',
                    queueId: '0',
                    connectionToken: $this->connectionToken
                );
            }

            $firstDelaySeconds = (int) config('c6bank.authorization.first_poll_delay_seconds', 60);
            $firstDelaySeconds = max(5, $firstDelaySeconds);

            CheckC6AuthorizationStatusJob::dispatch($authorization->id)
                ->delay(Carbon::now()->addSeconds($firstDelaySeconds));

            Log::info('GenerateC6AuthorizationLinkJob finished', [
                'tracking_id'       => $this->trackingId,
                'cpf'               => $this->cpf,
                'phone'             => $this->phone,
                'sent'              => $sent,
                'authorization_id'  => $authorization->id,
                'first_poll_delay'  => $firstDelaySeconds,
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

    private function splitPhone(?string $raw): array
    {
        if (! $raw) {
            return [null, null];
        }

        $digits = preg_replace('/\D+/', '', $raw) ?: '';

        if (str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) < 10) {
            return [null, null];
        }

        $ddd    = substr($digits, 0, 2);
        $number = substr($digits, 2);

        return [$ddd, $number];
    }
}
