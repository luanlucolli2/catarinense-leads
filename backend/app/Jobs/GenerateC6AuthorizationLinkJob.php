<?php

namespace App\Jobs;

use App\Models\InovachatTriage;
use App\Services\C6\C6AuthorizationService;
use App\Services\Inovachat\InternalMessageService;
use App\Services\Inovachat\TextMessageService;
use App\Services\Inovachat\TicketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

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

            $authorization = \App\Models\BankAuthorization::create([
                'tracking_id' => $this->trackingId,
                'bank'        => 'c6',
                'step'        => 'authorization',
                'cpf'         => $this->cpf,
                'phone'       => $this->phone,
                'link'        => $link,
                'status'      => \App\Models\BankAuthorization::STATUS_PENDING,
            ]);

            $maxWaitMinutes = (int) config('c6bank.authorization.max_wait_minutes', 20);
            $maxWaitMinutes = max(1, $maxWaitMinutes);

            $body =
                "{$name}, preciso só da sua autorização no C6 pra eu simular seus valores ✅\n\n"
                . "🔗 Autorize aqui:\n{$link}\n\n"
                . "É seguro: não dá acesso à sua conta e não retira dinheiro.\n"
                . "Se pedir foto do rosto, é só confirmação do C6.\n\n"
                . "Quando concluir, me avise aqui que eu confiro na hora. ⏱️";

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
        } catch (Throwable $e) {
            Log::error('GenerateC6AuthorizationLinkJob failed (will retry if attempts remain)', [
                'tracking_id' => $this->trackingId,
                'cpf'         => $this->cpf,
                'exception'   => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Executado quando esgota todas as tentativas (tries).
     * Regras pedidas:
     * - NÃO enviar mensagem ao cliente
     * - enviar mensagem interna (whisper)
     * - passar para atendimento humano (handoff)
     * - NÃO usar tags
     */
    public function failed(Throwable $e): void
    {
        /** @var InovachatTriage|null $triage */
        $triage = InovachatTriage::where('tracking_id', $this->trackingId)->first();

        $ticketId = (string) ($triage?->ticket_id ?: '');
        $connectionToken = (string) ($triage?->connection_token ?: $this->connectionToken);

        $handoffQueueId = (string) config('inovachat.handoff.queue_id');
        $handoffStatus  = (string) config('inovachat.handoff.status', 'pending');

        // 1) Handoff (sem falar nada com o cliente)
        $handoffOk = false;

        if ($ticketId !== '' && $handoffQueueId !== '' && $connectionToken !== '') {
            try {
                /** @var TicketService $tickets */
                $tickets = app(TicketService::class);

                $handoffOk = (bool) $tickets->updateTicket(
                    ticketId: $ticketId,
                    status: $handoffStatus,
                    queueId: $handoffQueueId,
                    userId: null,
                    typebotSessionId: null,
                    customA: null,
                    customB: null,
                    connectionToken: $connectionToken
                );
            } catch (Throwable $ex) {
                Log::error('GenerateC6AuthorizationLinkJob: handoff failed after retries exhausted', [
                    'tracking_id' => $this->trackingId,
                    'cpf'         => $this->cpf,
                    'ticket_id'   => $ticketId,
                    'exception'   => $ex->getMessage(),
                ]);
            }
        } else {
            Log::warning('GenerateC6AuthorizationLinkJob: handoff skipped (missing ticketId/handoffQueueId/connectionToken)', [
                'tracking_id'      => $this->trackingId,
                'cpf'              => $this->cpf,
                'ticket_id'        => $ticketId,
                'handoff_queue_id' => $handoffQueueId,
                'has_token'        => $connectionToken !== '',
            ]);
        }

        // 2) Mensagem interna (whisper) para o time
        $internalOk = false;

        if ($ticketId !== '' && $connectionToken !== '') {
            try {
                /** @var InternalMessageService $internal */
                $internal = app(InternalMessageService::class);

                $msg =
                    "C6 | FALHA AO GERAR LINK DE AUTORIZAÇÃO\n"
                    . "Tracking: {$this->trackingId}\n"
                    . "CPF: {$this->cpf}\n"
                    . "Telefone: " . ($this->phone ?: '-') . "\n"
                    . "Erro: {$e->getMessage()}\n"
                    . "Handoff: " . ($handoffOk ? 'OK' : 'FALHOU/SKIPPED');

                $internalOk = $internal->sendInternal(
                    ticketId: $ticketId,
                    body: $msg,
                    connectionToken: $connectionToken
                );
            } catch (Throwable $ex) {
                Log::error('GenerateC6AuthorizationLinkJob: internal message failed after retries exhausted', [
                    'tracking_id' => $this->trackingId,
                    'cpf'         => $this->cpf,
                    'ticket_id'   => $ticketId,
                    'exception'   => $ex->getMessage(),
                ]);
            }
        } else {
            Log::warning('GenerateC6AuthorizationLinkJob: internal message skipped (missing ticketId/connectionToken)', [
                'tracking_id' => $this->trackingId,
                'cpf'         => $this->cpf,
                'ticket_id'   => $ticketId,
                'has_token'   => $connectionToken !== '',
            ]);
        }

        Log::error('GenerateC6AuthorizationLinkJob failed permanently (retries exhausted)', [
            'tracking_id' => $this->trackingId,
            'cpf'         => $this->cpf,
            'ticket_id'   => $ticketId,
            'handoff_ok'  => $handoffOk,
            'internal_ok' => $internalOk,
            'exception'   => $e->getMessage(),
        ]);
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
