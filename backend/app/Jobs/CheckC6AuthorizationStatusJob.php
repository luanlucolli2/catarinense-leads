<?php

namespace App\Jobs;

use App\Models\BankAuthorization;
use App\Services\C6\C6AuthorizationService;
use App\Services\Inovachat\TagService;
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
        TagService $tags
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

        $createdAt       = $auth->created_at ?: $now;
        $elapsedSeconds  = $createdAt->diffInSeconds($now);
        $ticketId        = (string) ($auth->triage?->ticket_id ?: '');
        $handoffQueueId  = (string) config('inovachat.handoff.queue_id');
        $handoffStatus   = (string) config('inovachat.handoff.status', 'pending');
        $tagIdNotAuth    = (int) config('inovachat.tags.c6_not_authorized_id', 0);

        // token da conexão do lead (mesmo valor do token_origin)
        $connectionToken = (string) ($auth->triage?->connection_token ?: '');

        // TIMEOUT
        if ($elapsedSeconds >= ($maxWaitMinutes * 60)) {
            $this->markTimedOut($auth, $now);

            if ($auth->phone) {
                $body =
                    "⚠️ Ainda não consegui confirmar a autorização do C6 Bank.\n\n"
                    . "Vou te encaminhar agora para um atendente humano para te ajudar a concluir e seguir com a análise. 👤";
                $texts->sendText($auth->phone, $body, '0', '0', $connectionToken);
            }

            $this->handoffAndTag(
                auth: $auth,
                tickets: $tickets,
                tags: $tags,
                ticketId: $ticketId,
                handoffQueueId: $handoffQueueId,
                handoffStatus: $handoffStatus,
                tagId: $tagIdNotAuth,
                reason: 'timed_out',
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
                $body =
                    "🎉 Pronto! Autorização confirmada no C6 Bank ✅\n\n"
                    . "Agora vou te encaminhar para um atendente para seguir com a análise. 👤";
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
                'ticket_id'         => $ticketId,
            ]);

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

            if ($auth->phone) {
                $body =
                    "❌ Não consegui confirmar a autorização do C6 Bank.\n\n"
                    . "Vou te encaminhar para um atendente humano para verificar as próximas opções. 👤";
                $texts->sendText($auth->phone, $body, '0', '0', $connectionToken);
            }

            $this->handoffAndTag(
                auth: $auth,
                tickets: $tickets,
                tags: $tags,
                ticketId: $ticketId,
                handoffQueueId: $handoffQueueId,
                handoffStatus: $handoffStatus,
                tagId: $tagIdNotAuth,
                reason: 'denied',
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

    private function handoffAndTag(
        BankAuthorization $auth,
        TicketService $tickets,
        TagService $tags,
        string $ticketId,
        string $handoffQueueId,
        string $handoffStatus,
        int $tagId,
        string $reason,
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

        Log::info('C6 handoff executed', [
            'authorization_id' => $auth->id,
            'ticket_id'        => $ticketId,
            'handoff_queue_id' => $handoffQueueId,
            'ok'               => $ok,
            'reason'           => $reason,
        ]);

        if ($tagId > 0) {
            $tagOk = $tags->addTagsToTicket($ticketId, [$tagId], $connectionToken);

            Log::info('C6 tag applied', [
                'authorization_id' => $auth->id,
                'ticket_id'        => $ticketId,
                'tag_id'           => $tagId,
                'ok'               => $tagOk,
                'reason'           => $reason,
            ]);
        } else {
            Log::warning('C6 tag not applied (tag id not configured)', [
                'authorization_id' => $auth->id,
                'ticket_id'        => $ticketId,
                'reason'           => $reason,
            ]);
        }
    }

    private function buildReminderMessage(string $name, string $link, int $slot): string
    {
        $variant = $slot % 3;

        return match ($variant) {
            0 => "🔔 {$name}, lembrete rápido:\n\n"
                . "✅ Para continuar sua análise, autorize no C6 Bank aqui:\n{$link}\n\n"
                . "Se você já autorizou, pode ignorar — eu confirmo em instantes. 🙌",
            1 => "⏳ {$name}, ainda falta a autorização do C6 Bank:\n\n"
                . "🔗 {$link}\n\n"
                . "Assim que concluir, pode me mandar um “ok” aqui que eu confiro na hora. ✅",
            default => "🧾 {$name}, precisamos da autorização do C6 para seguir:\n\n"
                . "🔗 {$link}\n\n"
                . "É bem rapidinho 🙂 Se já fez, só aguarde 1 min que eu confirmo. ✅",
        };
    }
}
