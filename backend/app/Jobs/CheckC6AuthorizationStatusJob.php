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
        $auth = BankAuthorization::query()
            ->select([
                'id', 'tracking_id', 'connection_token', 'bank', 'cpf', 'phone', 'link', 'status',
                'last_checked_at', 'created_at',
            ])
            ->with(['triage:tracking_id,ticket_id,name,first_name,connection_token'])
            ->find($this->authorizationId);

        if (! $auth || $auth->bank !== 'c6') {
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

        // token do lead (preferir desnormalizado)
        $connectionToken = (string) ($auth->connection_token ?: ($auth->triage?->connection_token ?: ''));

        $logFailures = (bool) config('inovachat.logging.log_failures', true);

        /**
         * ✅ EXCEÇÃO (pedido):
         * Se o ticket saiu da fila de espera por ação humana, encerra o tracking e não faz mais nada.
         */
        $waitQueueId = (string) config('inovachat.queue_webhook.c6_wait_queue_id');
        if ($ticketId !== '' && $connectionToken !== '' && $waitQueueId !== '') {
            $ticket = $tickets->getTicket($ticketId, $connectionToken);
            $currentQueueId = is_array($ticket) ? (string) ($ticket['queueId'] ?? '') : '';

            if ($ticket !== null && $currentQueueId !== $waitQueueId) {
                $auth->status = 'aborted';
                $auth->last_checked_at = $now;
                $auth->last_status_payload = [
                    'status' => 'ABORTED_QUEUE_CHANGED',
                    'queueId' => $currentQueueId,
                    'at' => $now->toIso8601String(),
                ];
                $auth->save();

                if ($auth->triage) {
                    $auth->triage->update(['status' => 'aborted']);
                }

                return;
            }
        }

        // TIMEOUT
        if ($elapsedSeconds >= ($maxWaitMinutes * 60)) {
            $this->markTimedOut($auth, $now);

            if ($auth->phone && $connectionToken !== '') {
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

        // Lock curto para evitar dupla checagem (webhook + polling)
        $lockSeconds = (int) config('c6bank.authorization.status_lock_seconds', 30);
        $lockSeconds = max(5, $lockSeconds);

        $lockKey = 'c6auth:statuslock:' . $auth->id;
        if (! Cache::add($lockKey, 1, $lockSeconds)) {
            self::dispatch($auth->id)->delay($now->addSeconds($pollIntervalSeconds));
            return;
        }

        // POLLING (não pode derrubar o job)
        try {
            $result = $c6->checkAuthorizationStatus($auth->cpf);
        } catch (Throwable $e) {
            $this->persistStatusPayload(
                auth: $auth,
                now: $now,
                status: 'PENDING',
                raw: ['error' => true, 'message' => $e->getMessage(), 'at' => $now->toIso8601String()]
            );

            if ($logFailures) {
                Log::warning('C6 status check failed; rescheduling', [
                    'authorization_id' => $auth->id,
                    'exception'        => $e->getMessage(),
                ]);
            }

            self::dispatch($auth->id)->delay($now->addSeconds($pollIntervalSeconds));
            return;
        }

        $remoteStatus = strtoupper((string) ($result['status'] ?? 'PENDING'));
        $raw          = $result['raw'] ?? null;

        // AUTHORIZED
        if (in_array($remoteStatus, ['AUTHORIZED', 'AUTORIZADO', 'AUTORIZADO_COM_SUCESSO'], true)) {
            $auth->status        = BankAuthorization::STATUS_AUTHORIZED;
            $auth->authorized_at = $now;
            $auth->last_checked_at = $now;
            $auth->last_status_payload = $this->payloadToStore($remoteStatus, $raw, $now);
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_AUTHORIZED]);
            }

            if ($auth->phone && $connectionToken !== '') {
                $texts->sendText(
                    number: $auth->phone,
                    body: "Autorização confirmada ✅ Vou te encaminhar para o atendente agora. 👤",
                    openTicket: '0',
                    queueId: '0',
                    connectionToken: $connectionToken
                );
            }

            if ($ticketId !== '' && $handoffQueueId !== '' && $connectionToken !== '') {
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

            return;
        }

        // DENIED
        if (in_array($remoteStatus, ['DENIED', 'NAO_AUTORIZADO', 'NOT_AUTHORIZED'], true)) {
            $auth->status        = BankAuthorization::STATUS_DENIED;
            $auth->failed_at     = $now;
            $auth->last_checked_at = $now;
            $auth->last_status_payload = $this->payloadToStore($remoteStatus, $raw, $now);
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_DENIED]);
            }

            if ($auth->phone && $connectionToken !== '') {
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
        $this->persistPendingCheap($auth, $now, $remoteStatus, $raw);

        // lembrete pró-ativo (mantém comportamento)
        if ($auth->phone && is_string($auth->link) && $auth->link !== '' && $connectionToken !== '') {
            $slotSeconds = $reminderEveryMin * 60;
            $slot = (int) floor($elapsedSeconds / $slotSeconds);

            if ($slot >= 1) {
                $key = "c6auth:reminder:{$auth->id}:{$slot}";
                if (Cache::add($key, 1, $slotSeconds)) {
                    $name = $auth->triage?->first_name ?: ($auth->triage?->name ?: 'Tudo certo');

                    $texts->sendText(
                        number: $auth->phone,
                        body: $this->buildReminderMessage($name, $auth->link),
                        openTicket: '0',
                        queueId: '0',
                        connectionToken: $connectionToken
                    );
                }
            }
        }

        self::dispatch($auth->id)->delay($now->addSeconds($pollIntervalSeconds));
    }

    public function failed(Throwable $e): void
    {
        $logFailures = (bool) config('inovachat.logging.log_failures', true);

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

        if ($logFailures) {
            Log::error('CheckC6AuthorizationStatusJob failed', [
                'authorization_id' => $auth->id,
                'exception'        => $e->getMessage(),
            ]);
        }
    }

    private function markTimedOut(BankAuthorization $auth, Carbon $now): void
    {
        $auth->status    = BankAuthorization::STATUS_TIMED_OUT;
        $auth->failed_at = $now;
        $auth->last_checked_at = $now;
        $auth->last_status_payload = $this->payloadToStore('TIMED_OUT', $auth->last_status_payload, $now);
        $auth->save();

        if ($auth->triage) {
            $auth->triage->update(['status' => BankAuthorization::STATUS_TIMED_OUT]);
        }
    }

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
        if ($ticketId === '' || $handoffQueueId === '' || $connectionToken === '') {
            return;
        }

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

        $internalMessages->sendInternal(
            ticketId: $ticketId,
            body: $this->buildNotAuthorizedInternalMessage($auth, $reason, $elapsedSeconds),
            connectionToken: $connectionToken
        );
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

    private function payloadToStore(string $status, mixed $raw, Carbon $now): array|null
    {
        $storeRaw = (bool) config('c6bank.authorization.store_raw_payload', false);

        if ($storeRaw) {
            return is_array($raw) ? $raw : (is_null($raw) ? null : ['raw' => $raw, 'status' => $status, 'at' => $now->toIso8601String()]);
        }

        return [
            'status' => $status,
            'at'     => $now->toIso8601String(),
        ];
    }

    private function persistStatusPayload(BankAuthorization $auth, Carbon $now, string $status, array $raw): void
    {
        $auth->last_checked_at     = $now;
        $auth->last_status_payload = $this->payloadToStore($status, $raw, $now);
        $auth->save();
    }

    private function persistPendingCheap(BankAuthorization $auth, Carbon $now, string $remoteStatus, mixed $raw): void
    {
        $persistEvery = (int) config('c6bank.authorization.persist_pending_every_seconds', 300);
        $persistEvery = max(30, $persistEvery);

        $storeRaw = (bool) config('c6bank.authorization.store_raw_payload', false);

        $shouldPersist =
            $storeRaw
            || ! $auth->last_checked_at
            || $auth->last_checked_at->diffInSeconds($now) >= $persistEvery;

        if (! $shouldPersist) {
            return;
        }

        $auth->last_checked_at     = $now;
        $auth->last_status_payload = $this->payloadToStore($remoteStatus, $raw, $now);
        $auth->save();
    }
}
