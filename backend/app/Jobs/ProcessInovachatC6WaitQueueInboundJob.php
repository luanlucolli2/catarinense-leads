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

class ProcessInovachatC6WaitQueueInboundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;
    public int $tries;
    public int $backoff;

    public function __construct(
        public readonly int $authorizationId,
        public readonly string $ticketId,
        public readonly string $phone,
        public readonly string $connectionToken,
    ) {
        $this->timeout = (int) config('c6bank.job.timeout', 60);
        $this->tries   = 3;
        $this->backoff = 10;

        // Mantém coerência: este job deve cair no mesmo worker/queue do C6 (se você estiver unificando)
        $this->onQueue((string) config('c6bank.job.queue', 'c6-auth'));
    }

    public function handle(
        TextMessageService $texts,
        C6AuthorizationService $c6,
        TicketService $tickets,
        InternalMessageService $internal,
    ): void {
        /** @var BankAuthorization|null $auth */
        $auth = BankAuthorization::query()
            ->with(['triage:tracking_id,ticket_id,name,first_name,connection_token,status'])
            ->find($this->authorizationId);

        if (! $auth || $auth->bank !== 'c6') {
            return;
        }

        // Só faz sentido enquanto estiver pendente
        if ($auth->status !== BankAuthorization::STATUS_PENDING) {
            return;
        }

        // Segurança extra
        if (! is_string($auth->link) || $auth->link === '') {
            return;
        }

        $now = Carbon::now();

        $maxWaitMinutes = (int) config('c6bank.authorization.max_wait_minutes', 20);
        $maxWaitMinutes = max(1, $maxWaitMinutes);

        $createdAt      = $auth->created_at ?: $now;
        $elapsedSeconds = $createdAt->diffInSeconds($now);

        $handoffQueueId = (string) config('inovachat.handoff.queue_id');
        $handoffStatus  = (string) config('inovachat.handoff.status', 'pending');

        $ticketId = (string) $this->ticketId;
        $phone    = (string) ($this->phone ?: ($auth->phone ?: ''));
        $token    = (string) $this->connectionToken;

        $sendInternal = function (string $body) use ($internal, $ticketId, $token): bool {
            if ($ticketId === '' || $token === '') return false;
            return $internal->sendInternal($ticketId, $body, $token);
        };

        $safeUpdateTicket = function () use ($tickets, $ticketId, $handoffStatus, $handoffQueueId, $token): bool {
            if ($ticketId === '' || $handoffQueueId === '' || $token === '') return false;

            try {
                return (bool) $tickets->updateTicket(
                    ticketId: $ticketId,
                    status: $handoffStatus,
                    queueId: $handoffQueueId,
                    userId: null,
                    typebotSessionId: null,
                    customA: null,
                    customB: null,
                    connectionToken: $token
                );
            } catch (Throwable) {
                return false;
            }
        };

        // TIMEOUT (fim do fluxo)
        if ($elapsedSeconds >= ($maxWaitMinutes * 60)) {
            $auth->status              = BankAuthorization::STATUS_TIMED_OUT;
            $auth->failed_at           = $now;
            $auth->last_checked_at     = $now;
            $auth->last_status_payload = [
                'status' => 'TIMED_OUT',
                'at'     => $now->toIso8601String(),
            ];
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_TIMED_OUT]);
            }

            if ($phone !== '' && $token !== '') {
                $texts->sendText(
                    number: $phone,
                    body: "⏱️ Não consegui confirmar a autorização.\nVou te passar para um atendente agora. 👤",
                    openTicket: '0',
                    queueId: '0',
                    connectionToken: $token
                );
            }

            $ok = $safeUpdateTicket();

            $sendInternal(
                "C6 | NÃO AUTORIZOU (TIMEOUT)\n"
                . "AuthorizationID: {$auth->id}\n"
                . "CPF: {$auth->cpf}\n"
                . "Telefone: {$phone}\n"
                . "Handoff: " . ($ok ? 'OK' : 'FALHOU')
            );

            return;
        }

        /**
         * Regra:
         * - Se não pegar lock, não consulta C6 agora (evita custo), mas pode mandar lembrete (cooldown).
         */
        $remoteStatus = 'PENDING';

        $lockSeconds = (int) config('c6bank.authorization.status_lock_seconds', 30);
        $lockSeconds = max(5, $lockSeconds);

        $lockKey = 'c6auth:statuslock:' . $auth->id;
        $canCheckStatus = Cache::add($lockKey, 1, $lockSeconds);

        if ($canCheckStatus) {
            try {
                $result = $c6->checkAuthorizationStatus($auth->cpf);
                $remoteStatus = strtoupper((string) ($result['status'] ?? 'PENDING'));

                $storeRaw = (bool) config('c6bank.authorization.store_raw_payload', false);

                $auth->last_status_payload = $storeRaw
                    ? ($result['raw'] ?? null)
                    : ['status' => $remoteStatus, 'at' => $now->toIso8601String()];

                $auth->last_checked_at = $now;
            } catch (Throwable $e) {
                // Falhou checar C6: trata como PENDING e segue
                $auth->last_status_payload = [
                    'error'   => true,
                    'message' => $e->getMessage(),
                    'at'      => $now->toIso8601String(),
                ];
                $auth->last_checked_at = $now;
                $remoteStatus = 'PENDING';
            }
        }

        // AUTHORIZED
        if (in_array($remoteStatus, ['AUTHORIZED', 'AUTORIZADO', 'AUTORIZADO_COM_SUCESSO'], true)) {
            $auth->status        = BankAuthorization::STATUS_AUTHORIZED;
            $auth->authorized_at = $now;
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_AUTHORIZED]);
            }

            if ($phone !== '' && $token !== '') {
                $texts->sendText(
                    number: $phone,
                    body: "✅ Autorização confirmada.\nVou te passar para um atendente agora. 👤",
                    openTicket: '0',
                    queueId: '0',
                    connectionToken: $token
                );
            }

            $safeUpdateTicket();
            return;
        }

        // DENIED
        if (in_array($remoteStatus, ['DENIED', 'NAO_AUTORIZADO', 'NOT_AUTHORIZED'], true)) {
            $auth->status    = BankAuthorization::STATUS_DENIED;
            $auth->failed_at = $now;
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_DENIED]);
            }

            if ($phone !== '' && $token !== '') {
                $texts->sendText(
                    number: $phone,
                    body: "Ainda não consegui confirmar a autorização.\nVou te passar para um atendente agora. 👤",
                    openTicket: '0',
                    queueId: '0',
                    connectionToken: $token
                );
            }

            $ok = $safeUpdateTicket();

            $sendInternal(
                "C6 | NÃO AUTORIZOU (DENIED)\n"
                . "AuthorizationID: {$auth->id}\n"
                . "CPF: {$auth->cpf}\n"
                . "Telefone: {$phone}\n"
                . "StatusRemoto: {$remoteStatus}\n"
                . "Handoff: " . ($ok ? 'OK' : 'FALHOU')
            );

            return;
        }

        // PENDING: lembrete com cooldown
        $cooldown = (int) config('inovachat.queue_webhook.reminder_cooldown_seconds', 120);
        $cooldown = max(15, $cooldown);

        $cooldownKey = 'inovachat:c6wait:reminder:' . ($ticketId !== '' ? $ticketId : $phone);
        if (! Cache::add($cooldownKey, 1, $cooldown)) {
            // Se houve tentativa de checagem, persiste o payload/checked_at
            if ($canCheckStatus) {
                $auth->save();
            }
            return;
        }

        $name = $auth->triage?->first_name ?: ($auth->triage?->name ?: 'Tudo certo');

        $text = "{$name}, se você já autorizou no C6, basta aguardar 1–2 min que eu já confirmo! ⏱️\n\n"
            . "Caso ainda não tenha feito, utilize o link abaixo:\n"
            . "🔗 {$auth->link}";

        if ($phone !== '' && $token !== '') {
            $texts->sendText(
                number: $phone,
                body: $text,
                openTicket: '0',
                queueId: '0',
                connectionToken: $token
            );
        }

        // Salva somente se houve tentativa de checagem (reduz IO)
        if ($canCheckStatus) {
            $auth->save();
        }
    }

    public function failed(Throwable $e): void
    {
        $logFailures = (bool) config('inovachat.logging.log_failures', true);
        if (! $logFailures) {
            return;
        }

        Log::error('ProcessInovachatC6WaitQueueInboundJob failed', [
            'authorization_id' => $this->authorizationId,
            'ticket_id'        => $this->ticketId,
            'exception'        => $e->getMessage(),
        ]);
    }
}
