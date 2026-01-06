<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInovachatC6WaitQueueInboundJob;
use App\Models\BankAuthorization;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class InovachatC6WaitQueueWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
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

            // eventos de update não são mensagem
            if ($acao === 'queue_webhook_update') {
                return response()->noContent();
            }

            $fromMe = $this->extractFromMe($request);

            // se é mensagem enviada por nós, ignora
            if ($fromMe === true) {
                return response()->noContent();
            }

            // eventos internos sem fromMe => payload inconsistente, ignora
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

            // ✅ A partir daqui, tudo que tem rede/IO vai para o worker
            ProcessInovachatC6WaitQueueInboundJob::dispatch(
                authorizationId: (int) $auth->id,
                ticketId: (string) $ticketId,
                phone: (string) $phone,
                connectionToken: (string) $connectionToken,
            )->onQueue((string) config('c6bank.job.queue', 'c6-auth'));

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
