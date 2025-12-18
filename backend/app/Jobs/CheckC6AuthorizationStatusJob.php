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
                $body =
                    "⚠️ Ainda não consegui confirmar a autorização do C6 Bank.\n\n"
                    . "Vou te encaminhar agora para um atendente humano para te ajudar a concluir e seguir com a análise. 👤";
                $texts->sendText($auth->phone, $body, '0', '0', $connectionToken);
            }

            // ✅ mudança: sem tag; envia mensagem interna ao time
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

        // POLLING
        $result = $c6->checkAuthorizationStatus($auth->cpf);

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
                $body = "Pronto! O banco já autorizou a consulta. Vou te encaminhar agora para o atendente que vai finalizar os valores com você.";
                $texts->sendText($auth->phone, $body, '0', '0', $connectionToken);
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
                $body = "Ainda não recebi a confirmação do banco. Para não te fazer esperar, vou te passar agora para um de nossos atendentes que vai te ajudar a finalizar. Só um instante.";
                $texts->sendText($auth->phone, $body, '0', '0', $connectionToken);
            }

            // ✅ mudança: sem tag; envia mensagem interna ao time
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
                        $this->buildReminderMessage($name, $auth->link, $slot),
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

        $msg = $this->buildNotAuthorizedInternalMessage($auth, $reason, $elapsedSeconds);

        $internalOk = $internalMessages->sendInternal(
            ticketId: $ticketId,
            body: $msg,
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

        $name = $auth->triage?->first_name
            ?: ($auth->triage?->name ?: 'Cliente');

        $link = (is_string($auth->link) && $auth->link !== '') ? $auth->link : null;

        $lines = [
            "C6 | NÃO AUTORIZADO ({$reason})",
            "Authorization ID: {$auth->id}",
            "Nome: {$name}",
            "CPF: {$auth->cpf}",
            "Telefone: " . ($auth->phone ?: '-'),
            "Tempo aguardando: {$mins} min",
        ];

        if ($link) {
            $lines[] = "Link: {$link}";
        }

        return implode("\n", $lines);
    }

    private function buildReminderMessage(string $name, string $link, int $slot): string
    {
        $variant = $slot % 3;

        return match ($variant) {
            0 => "Oi, {$name}. Passando para ver se você conseguiu abrir o link do banco. Pode ficar tranquilo que esse acesso é só para eu ver quanto libera para você, não mexe em nada na sua conta.\n\n"
                . "Se não conseguir agora, não tem problema. Você já está na nossa fila e logo um atendente vai te chamar aqui para te ajudar. 👤\n\n"
                . "Link: {$link}",

            1 => "{$name}, ainda não apareceu sua autorização aqui no sistema. Se você já fez, o banco pode demorar uns minutinhos para me avisar, tá?\n\n"
                . "Caso tenha tido qualquer dificuldade com o link, é só aguardar que um colega nosso vai falar com você em breve para resolver tudo por aqui. 👤\n\n"
                . "Link: {$link}",

            default => "Ainda estou por aqui para te ajudar a liberar seus valores, {$name}. Precisamos só dessa autorização rápida no link abaixo para eu ver o saldo:\n\n"
                . "🔗 {$link}\n\n"
                . "Se preferir falar direto com uma pessoa, é só esperar um pouquinho. Você já está na nossa fila e será atendido em breve. 👤",
        };
    }
}
