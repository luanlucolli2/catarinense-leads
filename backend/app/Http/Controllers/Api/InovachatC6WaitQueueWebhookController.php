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
            $acao     = (string) $request->input('acao', '');
            $queueId  = $this->extractQueueId($request);
            $ticketId = $this->extractTicketId($request);
            $sender   = $this->extractSender($request);

            $connectionToken = (string) $request->attributes->get('inovachat_connection_token', '');
            if ($connectionToken === '') {
                return response()->noContent();
            }

            $waitQueueId = (string) config('inovachat.queue_webhook.c6_wait_queue_id');
            if ($waitQueueId === '' || $queueId !== $waitQueueId) {
                return response()->noContent();
            }

            if ($acao === 'queue_webhook_update') {
                return response()->noContent();
            }

            $fromMe = $this->extractFromMe($request);

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
                return response()->noContent();
            }

            $messageText = $this->extractMessageText($request);

            // Dedupe inbound (eventos duplicados do Inovachat)
            $dedupeTtl = (int) config('inovachat.queue_webhook.dedupe_ttl_seconds', 20);
            $dedupeTtl = max(5, $dedupeTtl);

            $msgFingerprint = $this->fingerprintInboundMessage($request, $ticketId, $phone, $messageText);
            $dedupeKey = 'inovachat:c6wait:inbound:' . $msgFingerprint;

            if (! Cache::add($dedupeKey, 1, $dedupeTtl)) {
                return response()->noContent();
            }

            // ✅ Lookup rápido (sem JOIN) usando connection_token desnormalizado
            $auth = BankAuthorization::query()
                ->where('bank', 'c6')
                ->where('status', BankAuthorization::STATUS_PENDING)
                ->where('phone', $phone)
                ->where('connection_token', $connectionToken)
                ->orderByDesc('id')
                ->first();

            // fallback: telefone local (sem +55 etc)
            if (! $auth) {
                $local = Phone::stripCountry($phone);
                if ($local) {
                    $auth = BankAuthorization::query()
                        ->where('bank', 'c6')
                        ->where('status', BankAuthorization::STATUS_PENDING)
                        ->where('phone', 'like', '%' . $local)
                        ->where('connection_token', $connectionToken)
                        ->orderByDesc('id')
                        ->first();
                }
            }

            // fallback para registros antigos (connection_token null) via JOIN
            if (! $auth) {
                $auth = BankAuthorization::query()
                    ->where('bank', 'c6')
                    ->where('status', BankAuthorization::STATUS_PENDING)
                    ->where('phone', $phone)
                    ->whereNull('connection_token')
                    ->whereHas('triage', function ($q) use ($connectionToken) {
                        $q->where('connection_token', $connectionToken);
                    })
                    ->orderByDesc('id')
                    ->first();
            }

            if (! $auth || ! is_string($auth->link) || $auth->link === '') {
                return response()->noContent();
            }

            $now = Carbon::now();

            $maxWaitMinutes = (int) config('c6bank.authorization.max_wait_minutes', 20);
            $maxWaitMinutes = max(1, $maxWaitMinutes);

            $createdAt      = $auth->created_at ?: $now;
            $elapsedSeconds = $createdAt->diffInSeconds($now);

            $handoffQueueId = (string) config('inovachat.handoff.queue_id');
            $handoffStatus  = (string) config('inovachat.handoff.status', 'pending');

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
                } catch (Throwable) {
                    return false;
                }
            };

            // TIMEOUT (fim do fluxo)
            if ($elapsedSeconds >= ($maxWaitMinutes * 60)) {
                $auth->status            = BankAuthorization::STATUS_TIMED_OUT;
                $auth->failed_at         = $now;
                $auth->last_checked_at   = $now;
                $auth->last_status_payload = [
                    'status' => 'TIMED_OUT',
                    'at'     => $now->toIso8601String(),
                ];
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

                return response()->noContent();
            }

            /**
             * ✅ NOVA REGRA:
             * - lock falhou => NÃO checa C6 agora (evita custo), mas NÃO fica mudo.
             * - seguimos para o fluxo de lembrete (cooldown) em PENDING.
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
                    // Falhou checar C6: trata como PENDING e segue (sem derrubar webhook)
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

                $texts->sendText(
                    number: $phone,
                    body: "✅ Autorização confirmada.\nVou te passar para um atendente agora. 👤",
                    openTicket: '0',
                    queueId: '0',
                    connectionToken: $connectionToken
                );

                $safeUpdateTicket();

                return response()->noContent();
            }

            // DENIED
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

                return response()->noContent();
            }

            // PENDING: (com ou sem checagem) aplica cooldown de lembrete
            // ⚠️ BUGFIX: era 1515 no seu trecho colado. O correto é 15.
            $cooldown = (int) config('inovachat.queue_webhook.reminder_cooldown_seconds', 120);
            $cooldown = max(15, $cooldown);

            $cooldownKey = 'inovachat:c6wait:reminder:' . ($ticketId !== '' ? $ticketId : $phone);
            if (! Cache::add($cooldownKey, 1, $cooldown)) {
                // Se não pode responder agora, ainda assim persiste last_checked_at/payload se houve tentativa
                if ($canCheckStatus) {
                    $auth->save();
                }
                return response()->noContent();
            }

            $name = $auth->triage?->first_name ?: ($auth->triage?->name ?: 'Tudo certo');

            $text = "{$name}, se você já autorizou no C6, basta aguardar 1–2 min que eu já confirmo! ⏱️\n\n"
                . "Caso ainda não tenha feito, utilize o link abaixo:\n"
                . "🔗 {$auth->link}";

            $texts->sendText(
                number: $phone,
                body: $text,
                openTicket: '0',
                queueId: '0',
                connectionToken: $connectionToken
            );

            // Salva somente se houve tentativa de checagem (reduz IO)
            if ($canCheckStatus) {
                $auth->save();
            }

            return response()->noContent();
        } catch (Throwable $e) {
            $logFailures = (bool) config('inovachat.logging.log_failures', true);
            if ($logFailures) {
                Log::error('Queue webhook: fatal exception (swallowed)', [
                    'exception' => $e->getMessage(),
                ]);
            }

            return response()->noContent();
        }
    }

    private function extractQueueId(Request $request): string
    {
        $val = $request->input('filaescolhidaid')
            ?: $request->input('queueId')
            ?: $request->input('ticketData.queueId')
            ?: $request->input('mensagem.queueId')
            ?: '';

        return is_scalar($val) ? (string) $val : '';
    }

    private function extractTicketId(Request $request): string
    {
        $val = $request->input('chamadoId')
            ?: $request->input('ticketData.id')
            ?: $request->input('mensagem.ticketId')
            ?: $request->input('mensagem.ticket.id')
            ?: '';

        return is_scalar($val) ? (string) $val : '';
    }

    private function extractSender(Request $request): string
    {
        $val = $request->input('sender')
            ?: $request->input('ticketData.contact.number')
            ?: $request->input('mensagem.contact.number')
            ?: $request->input('mensagem.ticket.contact.number')
            ?: '';

        return is_scalar($val) ? (string) $val : '';
    }

    private function extractFromMe(Request $request): ?bool
    {
        $candidates = [
            $request->input('fromMe'),
            $request->input('mensagem.fromMe'),
            $request->input('mensagem.body.fromMe'),
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

    private function extractMessageText(Request $request): string
    {
        $m = $request->input('mensagem');

        if (is_string($m)) {
            return trim($m);
        }

        if (is_array($m)) {
            if (array_is_list($m)) {
                $parts = [];
                foreach ($m as $item) {
                    if (is_array($item)) {
                        $t = $item['text'] ?? null;
                        if (is_string($t) && trim($t) !== '') {
                            $parts[] = trim($t);
                        }
                    }
                }
                return trim(implode("\n", $parts));
            }

            $body = $m['body'] ?? null;
            if (is_string($body)) {
                return trim($body);
            }
        }

        $fallback = $request->input('msg.message.conversation')
            ?: $request->input('body.mensagem')
            ?: $request->input('ticketData.lastMessage')
            ?: '';

        return is_string($fallback) ? trim($fallback) : '';
    }

    private function fingerprintInboundMessage(Request $request, string $ticketId, string $phone, string $messageText): string
    {
        $acao = (string) $request->input('acao', '');

        $messageId =
            $request->input('mensagem.wid')
            ?: $request->input('mensagem.id')
            ?: $request->input('mensagem.providerMessageId')
            ?: $request->input('mensagem.messageTimestamp')
            ?: $request->input('ticketData.updatedAt')
            ?: '';

        $basis = [
            'acao'      => $acao,
            'ticketId'  => $ticketId,
            'phone'     => $phone,
            'messageId' => is_scalar($messageId) ? (string) $messageId : '',
            'text'      => $messageText,
        ];

        return sha1(json_encode($basis, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
