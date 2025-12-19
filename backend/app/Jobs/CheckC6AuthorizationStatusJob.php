<?php

namespace App\Jobs;

use App\Models\BankAuthorization;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckC6AuthorizationStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    private int $authorizationId;

    public function __construct(int $authorizationId)
    {
        $this->authorizationId = $authorizationId;

        $queue = config('c6bank.job.queue', 'c6-auth');
        $this->onQueue($queue);
    }

    public function handle(
        C6AuthorizationService $c6,
        TextMessageService $texts,
        TicketService $tickets,
        InternalMessageService $internalMessages
    ): void {
        /** @var BankAuthorization|null $auth */
        $auth = BankAuthorization::with('triage')->find($this->authorizationId);

        if (! $auth) {
            Log::warning('CheckC6AuthorizationStatusJob: authorization not found', [
                'authorization_id' => $this->authorizationId,
            ]);
            return;
        }

        if ($auth->status !== BankAuthorization::STATUS_PENDING) {
            return;
        }

        $now = Carbon::now();

        $maxWaitMinutes      = (int) config('c6bank.authorization.max_wait_minutes', 20);
        $pollIntervalSeconds = (int) config('c6bank.authorization.poll_interval_seconds', 60);
        $reminderEveryMin    = (int) config('c6bank.authorization.reminder_every_minutes', 5);

        $maxWaitMinutes      = max(1, $maxWaitMinutes);
        $pollIntervalSeconds = max(10, $pollIntervalSeconds);
        $reminderEveryMin    = max(1, $reminderEveryMin);

        $createdAt      = $auth->created_at ?: $now;
        $elapsedSeconds = $createdAt->diffInSeconds($now);

        $ticketId       = (string) ($auth->triage?->ticket_id ?: '');
        $handoffQueueId = (string) config('inovachat.handoff.queue_id');
        $handoffStatus  = (string) config('inovachat.handoff.status', 'pending');

        // token da conexão do lead (mesmo valor do token_origin)
        $connectionToken = (string) ($auth->triage?->connection_token ?: '');

        // TIMEOUT (fim do polling sem autorizar)
        if ($elapsedSeconds >= ($maxWaitMinutes * 60)) {
            $this->markTimedOut($auth, $now);

            if ($auth->phone) {
                $texts->sendText(
                    number: $auth->phone,
                    body: "Não consegui confirmar a autorização do C6. Vou te passar para um atendente. 👤",
                    openTicket: '0',
                    queueId: '0',
                    connectionToken: $connectionToken
                );
            }

            $this->handoffAndInternalMessage(
                auth: $auth,
                tickets: $tickets,
                internalMessages: $internalMessages,
                ticketId: $ticketId,
                handoffQueueId: $handoffQueueId,
                handoffStatus: $handoffStatus,
                reason: 'timed_out',
                elapsedSeconds: $elapsedSeconds,
                connectionToken: $connectionToken
            );

            return;
        }

        // POLLING (não pode derrubar o job em caso de timeout/erro de rede)
        try {
            $result = $c6->checkAuthorizationStatus($auth->cpf);
        } catch (Throwable $e) {
            // mantém como PENDING e só reagenda
            $auth->last_checked_at = $now;
            $auth->last_status_payload = [
                'error' => true,
                'message' => $e->getMessage(),
                'at' => $now->toIso8601String(),
            ];
            $auth->save();

            Log::warning('C6 authorization status check failed; rescheduling polling', [
                'authorization_id' => $auth->id,
                'cpf'              => $auth->cpf,
                'poll_interval_s'  => $pollIntervalSeconds,
                'exception'        => $e->getMessage(),
            ]);

            self::dispatch($auth->id)->delay($now->addSeconds($pollIntervalSeconds));
            return;
        }

        $auth->last_status_payload = $result['raw'] ?? null;
        $auth->last_checked_at     = $now;

        $remoteStatus = strtoupper($result['status'] ?? 'PENDING');

        // AUTHORIZED
        if (in_array($remoteStatus, ['AUTHORIZED', 'AUTORIZADO', 'AUTORIZADO_COM_SUCESSO'], true)) {
            $auth->status        = BankAuthorization::STATUS_AUTHORIZED;
            $auth->authorized_at = $now;
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_AUTHORIZED]);
            }

            if ($auth->phone) {
                $texts->sendText(
                    number: $auth->phone,
                    body: "Autorização confirmada ✅ Vou te encaminhar para o atendente agora. 👤",
                    openTicket: '0',
                    queueId: '0',
                    connectionToken: $connectionToken
                );
            }

            if ($ticketId !== '' && $handoffQueueId !== '') {
                $tickets->updateTicket(
                    ticketId: $ticketId,
                    status: $handoffStatus,
                    queueId: $handoffQueueId,
                    userId: null,
                    typebotSessionId: null,
                    customA: null,
                    customB: null,
                    connectionToken: $connectionToken
                );
            }

            Log::info('C6 authorization authorized and handed off', [
                'authorization_id' => $auth->id,
                'cpf'              => $auth->cpf,
                'ticket_id'        => $ticketId,
            ]);

            return;
        }

        // DENIED (fim do polling sem autorizar)
        if (in_array($remoteStatus, ['DENIED', 'NAO_AUTORIZADO', 'NOT_AUTHORIZED'], true)) {
            $auth->status    = BankAuthorization::STATUS_DENIED;
            $auth->failed_at = $now;
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_DENIED]);
            }

            if ($auth->phone) {
                $texts->sendText(
                    number: $auth->phone,
                    body: "Não consegui confirmar a autorização do C6. Vou te passar para um atendente. 👤",
                    openTicket: '0',
                    queueId: '0',
                    connectionToken: $connectionToken
                );
            }

            $this->handoffAndInternalMessage(
                auth: $auth,
                tickets: $tickets,
                internalMessages: $internalMessages,
                ticketId: $ticketId,
                handoffQueueId: $handoffQueueId,
                handoffStatus: $handoffStatus,
                reason: 'denied',
                elapsedSeconds: $elapsedSeconds,
                connectionToken: $connectionToken
            );

            return;
        }

        // PENDING
        $auth->status = BankAuthorization::STATUS_PENDING;
        $auth->save();

        if ($auth->phone && is_string($auth->link) && $auth->link !== '') {
            $slotSeconds = $reminderEveryMin * 60;
            $slot = (int) floor($elapsedSeconds / $slotSeconds);

            if ($slot >= 1) {
                $key = "c6auth:reminder:{$auth->id}:{$slot}";
                if (Cache::add($key, 1, $slotSeconds)) {
                    $name = $auth->triage?->first_name ?: ($auth->triage?->name ?: 'Tudo certo');

                    $texts->sendText(
                        $auth->phone,
                        $this->buildReminderMessage($name, $auth->link),
                        '0',
                        '0',
                        $connectionToken
                    );

                    Log::info('C6 pending: proactive reminder sent', [
                        'authorization_id' => $auth->id,
                        'cpf'              => $auth->cpf,
                        'slot'             => $slot,
                    ]);
                }
            }
        }

        self::dispatch($auth->id)->delay($now->addSeconds($pollIntervalSeconds));

        Log::info('C6 authorization pending, rescheduling polling', [
            'authorization_id' => $auth->id,
            'cpf'              => $auth->cpf,
            'remote_status'    => $remoteStatus,
            'poll_interval_s'  => $pollIntervalSeconds,
        ]);
    }

    public function failed(Throwable $e): void
    {
        /** @var BankAuthorization|null $auth */
        $auth = BankAuthorization::find($this->authorizationId);

        if (! $auth) {
            return;
        }

        $auth->status        = BankAuthorization::STATUS_ERROR;
        $auth->failed_at     = Carbon::now();
        $auth->error_message = $e->getMessage();
        $auth->save();

        if ($auth->triage) {
            $auth->triage->update(['status' => BankAuthorization::STATUS_ERROR]);
        }

        Log::error('CheckC6AuthorizationStatusJob failed', [
            'authorization_id' => $auth->id,
            'cpf'              => $auth->cpf,
            'exception'        => $e->getMessage(),
        ]);
    }

    private function markTimedOut(BankAuthorization $auth, Carbon $now): void
    {
        $auth->status    = BankAuthorization::STATUS_TIMED_OUT;
        $auth->failed_at = $now;
        $auth->save();

        if ($auth->triage) {
            $auth->triage->update(['status' => BankAuthorization::STATUS_TIMED_OUT]);
        }
    }

    /**
     * Handoff + mensagem interna (whisper) para o time quando não autoriza.
     */
    private function handoffAndInternalMessage(
        BankAuthorization $auth,
        TicketService $tickets,
        InternalMessageService $internalMessages,
        string $ticketId,
        string $handoffQueueId,
        string $handoffStatus,
        string $reason,
        int $elapsedSeconds,
        string $connectionToken
    ): void {
        if ($ticketId === '' || $handoffQueueId === '') {
            Log::warning('C6 handoff skipped (missing ticketId or handoffQueueId)', [
                'authorization_id' => $auth->id,
                'ticket_id'        => $ticketId,
                'handoff_queue_id' => $handoffQueueId,
                'reason'           => $reason,
            ]);
            return;
        }

        $ok = $tickets->updateTicket(
            ticketId: $ticketId,
            status: $handoffStatus,
            queueId: $handoffQueueId,
            userId: null,
            typebotSessionId: null,
            customA: null,
            customB: null,
            connectionToken: $connectionToken
        );

        $internalOk = $internalMessages->sendInternal(
            ticketId: $ticketId,
            body: $this->buildNotAuthorizedInternalMessage($auth, $reason, $elapsedSeconds),
            connectionToken: $connectionToken
        );

        Log::info('C6 handoff executed + internal message (no tag)', [
            'authorization_id' => $auth->id,
            'ticket_id'        => $ticketId,
            'handoff_queue_id' => $handoffQueueId,
            'ok'               => $ok,
            'internal_ok'      => $internalOk,
            'reason'           => $reason,
        ]);
    }

    private function buildNotAuthorizedInternalMessage(BankAuthorization $auth, string $reason, int $elapsedSeconds): string
    {
        $mins = (int) max(1, ceil($elapsedSeconds / 60));

        return implode("\n", array_filter([
            "C6 | sem autorização ({$reason})",
            "Auth: {$auth->id} | {$mins} min",
            "CPF: {$auth->cpf}",
            "Fone: " . ($auth->phone ?: '-'),
            (is_string($auth->link) && $auth->link !== '') ? "Link: {$auth->link}" : null,
        ]));
    }

    private function buildReminderMessage(string $name, string $link): string
    {
        return "{$name}, falta só autorizar no C6:\n{$link}\n\n"
            . "Se pedir foto, é confirmação do banco.\n"
            . "Assim que fizer, eu confiro aqui. ✅";
    }
}
