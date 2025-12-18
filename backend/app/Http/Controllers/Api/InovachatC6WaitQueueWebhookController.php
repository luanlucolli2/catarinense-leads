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

        $acao     = (string) data_get($payload, 'acao', '');
        $queueId  = $this->extractQueueId($payload);
        $ticketId = $this->extractTicketId($payload);
        $sender   = $this->extractSender($payload);

        // token da conexão (injetado pelo middleware)
        $connectionToken = (string) $request->attributes->get('inovachat_connection_token', '');

        // Só processa a fila esperada
        $waitQueueId = (string) config('inovachat.queue_webhook.c6_wait_queue_id');
        if ($waitQueueId === '' || $queueId !== $waitQueueId) {
            return response()->noContent();
        }

        // Ignora eventos de update (não são mensagem de chat)
        if ($acao === 'queue_webhook_update') {
            return response()->noContent();
        }

        // Extrai "fromMe" de forma robusta
        $fromMe = $this->extractFromMe($payload);

        /**
         * Regras de origem:
         * - se conseguimos afirmar fromMe=true => é nosso (ignora)
         * - se acao=queue_webhook => tratar como cliente (inbound)
         * - se acao=queue_webhook_from_internal:
         *     - se fromMe conhecido => respeita
         *     - se fromMe ausente => por segurança, ignora (evita duplicar processamento)
         */
        if ($fromMe === true) {
            return response()->noContent();
        }

        if ($acao === 'queue_webhook_from_internal' && $fromMe === null) {
            // Sem sinal claro de direção, esse evento costuma duplicar o inbound.
            return response()->noContent();
        }

        // Aqui, consideramos inbound:
        $isInbound = ($acao === 'queue_webhook') || ($acao === 'queue_webhook_from_internal' && $fromMe === false);

        if (! $isInbound) {
            return response()->noContent();
        }

        $phone = Phone::normalize($sender);
        if (! $phone) {
            Log::warning('Queue webhook: invalid sender phone', [
                'sender' => $sender,
                'acao' => $acao,
                'queueId' => $queueId,
                'ticketId' => $ticketId,
            ]);
            return response()->noContent();
        }

        $messageText = $this->extractMessageText($payload);

        // Idempotência curta: evita processar duplicado (ex.: queue_webhook + from_internal)
        $msgFingerprint = $this->fingerprintInboundMessage($payload, $ticketId, $phone, $messageText);
        $dedupeTtl = (int) config('inovachat.queue_webhook.dedupe_ttl_seconds', 20);
        $dedupeTtl = max(5, $dedupeTtl);

        $dedupeKey = 'inovachat:c6wait:inbound:' . $msgFingerprint;
        if (! Cache::add($dedupeKey, 1, $dedupeTtl)) {
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
                'acao' => $acao,
                'message' => $messageText,
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

        $remoteStatus = strtoupper((string) ($result['status'] ?? 'PENDING'));

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
            "{$name}, entendi!\n\n"
            . "✅ Para eu continuar sua análise, só falta autorizar no C6 Bank por aqui:\n{$auth->link}\n\n"
            . "Se você já autorizou, pode ficar tranquilo, eu confirmo em até 1 min. ⏱️🙌";

        $sent = $texts->sendText(
            number: $phone,
            body: $text,
            openTicket: '0',
            queueId: '0',
            connectionToken: $connectionToken
        );

        Log::info('Queue webhook: pending reminder sent', [
            'phone' => $phone,
            'ticketId' => $ticketId,
            'queueId' => $queueId,
            'acao' => $acao,
            'message' => $messageText,
            'sent' => $sent,
        ]);

        return response()->noContent();
    }

    private function extractQueueId(array $payload): string
    {
        $val = data_get($payload, 'filaescolhidaid')
            ?: data_get($payload, 'queueId')
            ?: data_get($payload, 'ticketData.queueId')
            ?: data_get($payload, 'mensagem.queueId')
            ?: '';

        return is_scalar($val) ? (string) $val : '';
    }

    private function extractTicketId(array $payload): string
    {
        $val = data_get($payload, 'chamadoId')
            ?: data_get($payload, 'ticketData.id')
            ?: data_get($payload, 'mensagem.ticketId')
            ?: data_get($payload, 'mensagem.ticket.id')
            ?: '';

        return is_scalar($val) ? (string) $val : '';
    }

    private function extractSender(array $payload): string
    {
        $val = data_get($payload, 'sender')
            ?: data_get($payload, 'ticketData.contact.number')
            ?: data_get($payload, 'mensagem.contact.number')
            ?: data_get($payload, 'mensagem.ticket.contact.number')
            ?: '';

        return is_scalar($val) ? (string) $val : '';
    }

    /**
     * Retorna:
     * - true/false quando o payload traz sinal claro
     * - null quando não há sinal confiável
     */
    private function extractFromMe(array $payload): ?bool
    {
        $candidates = [
            data_get($payload, 'fromMe', null),
            data_get($payload, 'mensagem.fromMe', null),
            data_get($payload, 'mensagem.body.fromMe', null),
        ];

        foreach ($candidates as $v) {
            if (is_bool($v)) return $v;
            if (is_int($v) && ($v === 0 || $v === 1)) return (bool) $v;
            if (is_string($v)) {
                $vv = strtolower(trim($v));
                if (in_array($vv, ['true', '1', 'yes'], true)) return true;
                if (in_array($vv, ['false', '0', 'no'], true)) return false;
            }
        }

        return null;
    }

    private function extractMessageText(array $payload): string
    {
        $m = data_get($payload, 'mensagem');

        // Caso 1: string direta
        if (is_string($m)) {
            return trim($m);
        }

        // Caso 2: array de blocos [{type,text}]
        if (is_array($m)) {
            // pode ser lista de itens, ou objeto (assoc) dependendo do evento
            // se for lista:
            if (array_is_list($m)) {
                $parts = [];
                foreach ($m as $item) {
                    if (is_array($item)) {
                        $t = data_get($item, 'text');
                        if (is_string($t) && trim($t) !== '') {
                            $parts[] = trim($t);
                        }
                    }
                }
                return trim(implode("\n", $parts));
            }

            // se for objeto (assoc) no formato interno: { body: "...", ... }
            $body = data_get($m, 'body');
            if (is_string($body)) {
                return trim($body);
            }
        }

        // Caso 3: outras chaves alternativas (fallbacks)
        $fallback = data_get($payload, 'msg.message.conversation')
            ?: data_get($payload, 'body.mensagem')
            ?: data_get($payload, 'ticketData.lastMessage')
            ?: '';

        return is_string($fallback) ? trim($fallback) : '';
    }

    private function fingerprintInboundMessage(array $payload, string $ticketId, string $phone, string $messageText): string
    {
        $acao = (string) data_get($payload, 'acao', '');

        $messageId =
            data_get($payload, 'mensagem.wid')
            ?: data_get($payload, 'mensagem.id')
            ?: data_get($payload, 'mensagem.providerMessageId')
            ?: data_get($payload, 'mensagem.messageTimestamp')
            ?: data_get($payload, 'ticketData.updatedAt')
            ?: null;

        $basis = [
            'acao' => $acao,
            'ticketId' => $ticketId,
            'phone' => $phone,
            'messageId' => is_scalar($messageId) ? (string) $messageId : '',
            'text' => $messageText,
        ];

        return sha1(json_encode($basis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
