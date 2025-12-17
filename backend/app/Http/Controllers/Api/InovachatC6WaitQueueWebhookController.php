<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAuthorization;
use App\Services\C6\C6AuthorizationService;
use App\Services\Inovachat\TagService;
use App\Services\Inovachat\TextMessageService;
use App\Services\Inovachat\TicketService;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class InovachatC6WaitQueueWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TextMessageService $texts,
        C6AuthorizationService $c6,
        TicketService $tickets,
        TagService $tags
    ): Response {
        $payload = $request->all();

        $fromMe  = filter_var(
            data_get($payload, 'fromMe', data_get($payload, 'body.fromMe', false)),
            FILTER_VALIDATE_BOOL
        );

        $queueId = (string) (
            data_get($payload, 'filaescolhidaid')
            ?: data_get($payload, 'queueId')
            ?: data_get($payload, 'body.filaescolhidaid')
            ?: ''
        );

        $ticketId = (string) (
            data_get($payload, 'chamadoId')
            ?: data_get($payload, 'ticketData.id')
            ?: data_get($payload, 'body.chamadoId')
            ?: ''
        );

        $sender = (string) (
            data_get($payload, 'sender')
            ?: data_get($payload, 'ticketData.contact.number')
            ?: data_get($payload, 'body.sender')
            ?: ''
        );

        $message = (string) (
            data_get($payload, 'mensagem')
            ?: data_get($payload, 'msg.message.conversation')
            ?: data_get($payload, 'body.mensagem')
            ?: ''
        );

        // token da conexão (injetado pelo middleware)
        $connectionToken = (string) $request->attributes->get('inovachat_connection_token', '');

        if ($fromMe) {
            return response()->noContent();
        }

        $waitQueueId = (string) config('inovachat.queue_webhook.c6_wait_queue_id');
        if ($waitQueueId === '' || $queueId !== $waitQueueId) {
            return response()->noContent();
        }

        $phone = Phone::normalize($sender);
        if (! $phone) {
            Log::warning('Queue webhook: invalid sender phone', [
                'sender' => $sender,
                'queueId' => $queueId,
                'ticketId' => $ticketId,
            ]);
            return response()->noContent();
        }

        // Busca autorização pendente mais recente (FILTRANDO pela conexão)
        $auth = BankAuthorization::query()
            ->where('bank', 'c6')
            ->where('status', BankAuthorization::STATUS_PENDING)
            ->where('phone', $phone)
            ->whereHas('triage', function ($q) use ($connectionToken) {
                if ($connectionToken !== '') {
                    $q->where('connection_token', $connectionToken);
                }
            })
            ->orderByDesc('id')
            ->first();

        if (! $auth) {
            $local = Phone::stripCountry($phone);
            if ($local) {
                $auth = BankAuthorization::query()
                    ->where('bank', 'c6')
                    ->where('status', BankAuthorization::STATUS_PENDING)
                    ->where('phone', 'like', '%' . $local)
                    ->whereHas('triage', function ($q) use ($connectionToken) {
                        if ($connectionToken !== '') {
                            $q->where('connection_token', $connectionToken);
                        }
                    })
                    ->orderByDesc('id')
                    ->first();
            }
        }

        if (! $auth || ! is_string($auth->link) || $auth->link === '') {
            Log::info('Queue webhook: no pending C6 authorization found', [
                'phone' => $phone,
                'ticketId' => $ticketId,
                'queueId' => $queueId,
                'message' => $message,
            ]);
            return response()->noContent();
        }

        $now = Carbon::now();

        $maxWaitMinutes = (int) config('c6bank.authorization.max_wait_minutes', 20);
        $maxWaitMinutes = max(1, $maxWaitMinutes);

        $createdAt      = $auth->created_at ?: $now;
        $elapsedSeconds = $createdAt->diffInSeconds($now);

        $handoffQueueId = (string) config('inovachat.handoff.queue_id');
        $handoffStatus  = (string) config('inovachat.handoff.status', 'pending');
        $tagIdNotAuth   = (int) config('inovachat.tags.c6_not_authorized_id', 0);

        if ($elapsedSeconds >= ($maxWaitMinutes * 60)) {
            $auth->status    = BankAuthorization::STATUS_TIMED_OUT;
            $auth->failed_at = $now;
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_TIMED_OUT]);
            }

            $texts->sendText(
                number: $phone,
                body: "⚠️ Entendi!\n\n"
                    . "Ainda não consegui confirmar a autorização do C6 Bank.\n"
                    . "Vou te encaminhar agora para um atendente humano para te ajudar. 👤",
                openTicket: '0',
                queueId: '0',
                connectionToken: $connectionToken
            );

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

                if ($tagIdNotAuth > 0) {
                    $tags->addTagsToTicket($ticketId, [$tagIdNotAuth], $connectionToken);
                }
            }

            Log::info('Queue webhook: timed out handled', [
                'authorization_id' => $auth->id,
                'ticketId' => $ticketId,
            ]);

            return response()->noContent();
        }

        // Polling agora (antes de responder)
        $result = $c6->checkAuthorizationStatus($auth->cpf);

        $auth->last_status_payload = $result['raw'] ?? null;
        $auth->last_checked_at     = $now;

        $remoteStatus = strtoupper($result['status'] ?? 'PENDING');

        if (in_array($remoteStatus, ['AUTHORIZED', 'AUTORIZADO', 'AUTORIZADO_COM_SUCESSO'], true)) {
            $auth->status        = BankAuthorization::STATUS_AUTHORIZED;
            $auth->authorized_at = $now;
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_AUTHORIZED]);
            }

            $texts->sendText(
                number: $phone,
                body: "🎉 Perfeito! Autorização confirmada no C6 Bank ✅\n\n"
                    . "Estou te encaminhando para um atendente agora. 👤",
                openTicket: '0',
                queueId: '0',
                connectionToken: $connectionToken
            );

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

            Log::info('Queue webhook: authorized handled', [
                'authorization_id' => $auth->id,
                'ticketId' => $ticketId,
            ]);

            return response()->noContent();
        }

        if (in_array($remoteStatus, ['DENIED', 'NAO_AUTORIZADO', 'NOT_AUTHORIZED'], true)) {
            $auth->status    = BankAuthorization::STATUS_DENIED;
            $auth->failed_at = $now;
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_DENIED]);
            }

            $texts->sendText(
                number: $phone,
                body: "❌ Não consegui confirmar a autorização do C6 Bank.\n\n"
                    . "Vou te encaminhar para um atendente humano para verificar as próximas opções. 👤",
                openTicket: '0',
                queueId: '0',
                connectionToken: $connectionToken
            );

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

                if ($tagIdNotAuth > 0) {
                    $tags->addTagsToTicket($ticketId, [$tagIdNotAuth], $connectionToken);
                }
            }

            Log::info('Queue webhook: denied handled', [
                'authorization_id' => $auth->id,
                'ticketId' => $ticketId,
            ]);

            return response()->noContent();
        }

        // PENDING: cooldown apenas para lembrete
        $cooldown = (int) config('inovachat.queue_webhook.reminder_cooldown_seconds', 120);
        $cooldown = max(15, $cooldown);

        $cooldownKey = 'inovachat:c6wait:reminder:' . ($ticketId !== '' ? $ticketId : $phone);
        if (! Cache::add($cooldownKey, 1, $cooldown)) {
            return response()->noContent();
        }

        $name = $auth->triage?->first_name ?: ($auth->triage?->name ?: 'Tudo certo');

        $text =
            "👋 {$name}, entendi!\n\n"
            . "✅ Para eu continuar sua análise, só falta autorizar no C6 Bank por aqui:\n{$auth->link}\n\n"
            . "Se você já autorizou, pode ficar tranquilo — eu confirmo em até 1 min. ⏱️🙌";

        $sent = $texts->sendText($phone, $text, '0', '0', $connectionToken);

        Log::info('Queue webhook: pending reminder sent', [
            'phone' => $phone,
            'ticketId' => $ticketId,
            'queueId' => $queueId,
            'sent' => $sent,
        ]);

        return response()->noContent();
    }
}
