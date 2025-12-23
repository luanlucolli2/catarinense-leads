<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAuthorization;
use App\Services\C6\C6AuthorizationService;
use App\Services\Inovachat\InternalMessageService;
use App\Services\Inovachat\TextMessageService;
use App\Services\Inovachat\TicketService;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class InovachatC6WaitQueueWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TextMessageService $texts,
        C6AuthorizationService $c6,
        TicketService $tickets,
        InternalMessageService $internal
    ): Response {
        try {
            $payload = $request->all();

            $acao     = (string) data_get($payload, 'acao', '');
            $queueId  = $this->extractQueueId($payload);
            $ticketId = $this->extractTicketId($payload);
            $sender   = $this->extractSender($payload);

            // token da conexão (injetado pelo middleware)
            $connectionToken = (string) $request->attributes->get('inovachat_connection_token', '');

            // Multi-conexões: sem token, não processa (evita cross-tenant por telefone)
            if ($connectionToken === '') {
                Log::warning('Queue webhook: missing connection token attribute (middleware)', [
                    'acao'     => $acao,
                    'queueId'  => $queueId,
                    'ticketId' => $ticketId,
                ]);
                return response()->noContent();
            }

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
                return response()->noContent();
            }

            $isInbound = ($acao === 'queue_webhook') || ($acao === 'queue_webhook_from_internal' && $fromMe === false);
            if (! $isInbound) {
                return response()->noContent();
            }

            $phone = Phone::normalize($sender);
            if (! $phone) {
                Log::warning('Queue webhook: invalid sender phone', [
                    'sender'   => $sender,
                    'acao'     => $acao,
                    'queueId'  => $queueId,
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
                    $q->where('connection_token', $connectionToken);
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
                            $q->where('connection_token', $connectionToken);
                        })
                        ->orderByDesc('id')
                        ->first();
                }
            }

            if (! $auth || ! is_string($auth->link) || $auth->link === '') {
                Log::info('Queue webhook: no pending C6 authorization found', [
                    'phone'    => $phone,
                    'ticketId' => $ticketId,
                    'queueId'  => $queueId,
                    'acao'     => $acao,
                    'message'  => $messageText,
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

            // helper local: envia whisper sem quebrar o fluxo
            $sendInternal = function (string $body) use ($internal, $ticketId, $connectionToken): bool {
                if ($ticketId === '' || $connectionToken === '') {
                    return false;
                }
                return $internal->sendInternal($ticketId, $body, $connectionToken);
            };

            $safeUpdateTicket = function () use ($tickets, $ticketId, $handoffStatus, $handoffQueueId, $connectionToken): bool {
                if ($ticketId === '' || $handoffQueueId === '' || $connectionToken === '') {
                    return false;
                }

                try {
                    return (bool) $tickets->updateTicket(
                        ticketId: $ticketId,
                        status: $handoffStatus,
                        queueId: $handoffQueueId,
                        userId: null,
                        typebotSessionId: null,
                        customA: null,
                        customB: null,
                        connectionToken: $connectionToken
                    );
                } catch (Throwable $e) {
                    Log::error('Queue webhook: updateTicket exception', [
                        'ticketId'  => $ticketId,
                        'queueId'   => $handoffQueueId,
                        'exception' => $e->getMessage(),
                    ]);
                    return false;
                }
            };

            if ($elapsedSeconds >= ($maxWaitMinutes * 60)) {
                $auth->status    = BankAuthorization::STATUS_TIMED_OUT;
                $auth->failed_at = $now;
                $auth->save();

                if ($auth->triage) {
                    $auth->triage->update(['status' => BankAuthorization::STATUS_TIMED_OUT]);
                }

                $texts->sendText(
                    number: $phone,
                    body: "⏱️ Não consegui confirmar a autorização.\nVou te passar para um atendente agora. 👤",
                    openTicket: '0',
                    queueId: '0',
                    connectionToken: $connectionToken
                );

                $ok = $safeUpdateTicket();

                $sendInternal(
                    "C6 | NÃO AUTORIZOU (TIMEOUT)\n"
                        . "AuthorizationID: {$auth->id}\n"
                        . "CPF: {$auth->cpf}\n"
                        . "Telefone: {$phone}\n"
                        . "Handoff: " . ($ok ? 'OK' : 'FALHOU')
                );

                Log::info('Queue webhook: timed out handled', [
                    'authorization_id' => $auth->id,
                    'ticketId' => $ticketId,
                ]);

                return response()->noContent();
            }

            // Polling (C6 pode dar timeout): NÃO derruba webhook
            try {
                $result = $c6->checkAuthorizationStatus($auth->cpf);
            } catch (Throwable $e) {
                $auth->last_status_payload = [
                    'error' => true,
                    'message' => $e->getMessage(),
                    'at' => $now->toIso8601String(),
                ];
                $auth->last_checked_at = $now;
                $auth->save();

                Log::warning('Queue webhook: C6 status check failed; treating as PENDING', [
                    'authorization_id' => $auth->id,
                    'cpf'              => $auth->cpf,
                    'exception'        => $e->getMessage(),
                ]);

                $result = ['status' => 'PENDING', 'raw' => $auth->last_status_payload];
            }

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
                    body: "✅ Autorização confirmada.\nVou te passar para um atendente agora. 👤",
                    openTicket: '0',
                    queueId: '0',
                    connectionToken: $connectionToken
                );

                $safeUpdateTicket();

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
                    body: "Ainda não consegui confirmar a autorização.\nVou te passar para um atendente agora. 👤",
                    openTicket: '0',
                    queueId: '0',
                    connectionToken: $connectionToken
                );

                $ok = $safeUpdateTicket();

                $sendInternal(
                    "C6 | NÃO AUTORIZOU (DENIED)\n"
                        . "AuthorizationID: {$auth->id}\n"
                        . "CPF: {$auth->cpf}\n"
                        . "Telefone: {$phone}\n"
                        . "StatusRemoto: {$remoteStatus}\n"
                        . "Handoff: " . ($ok ? 'OK' : 'FALHOU')
                );

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

            $text = "{$name}, se você já autorizou no C6, basta aguardar 1–2 min que eu já confirmo! ⏱️\n\n"
                . "Caso ainda não tenha feito, utilize o link abaixo:\n"
                . "🔗 {$auth->link}";

            $sent = $texts->sendText(
                number: $phone,
                body: $text,
                openTicket: '0',
                queueId: '0',
                connectionToken: $connectionToken
            );

            Log::info('Queue webhook: pending reminder sent', [
                'phone'    => $phone,
                'ticketId' => $ticketId,
                'queueId'  => $queueId,
                'acao'     => $acao,
                'message'  => $messageText,
                'sent'     => $sent,
            ]);

            return response()->noContent();
        } catch (Throwable $e) {
            // Nunca derruba o webhook da fila por erro inesperado
            Log::error('Queue webhook: fatal exception (swallowed)', [
                'exception' => $e->getMessage(),
            ]);

            return response()->noContent();
        }
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

        if (is_string($m)) {
            return trim($m);
        }

        if (is_array($m)) {
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

            $body = data_get($m, 'body');
            if (is_string($body)) {
                return trim($body);
            }
        }

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
