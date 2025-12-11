<?php

namespace App\Jobs;

use App\Models\BankAuthorization;
use App\Services\C6\C6AuthorizationService;
use App\Services\Inovachat\TextMessageService;
use App\Services\Inovachat\TicketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
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
        TicketService $tickets
    ): void {
        /** @var BankAuthorization|null $auth */
        $auth = BankAuthorization::with('triage')->find($this->authorizationId);

        if (! $auth) {
            Log::warning('CheckC6AuthorizationStatusJob: authorization not found', [
                'authorization_id' => $this->authorizationId,
            ]);

            return;
        }

        // Se já saiu de pending, não tem nada a fazer.
        if ($auth->status !== BankAuthorization::STATUS_PENDING) {
            return;
        }

        $now = Carbon::now();

        // Timeout de espera total (ex.: 8 horas)
        $maxMinutes = (int) env('C6_AUTH_STATUS_MAX_WAIT_MINUTES', 480);

        if ($auth->created_at && $auth->created_at->diffInMinutes($now) >= $maxMinutes) {
            $auth->status    = BankAuthorization::STATUS_TIMED_OUT;
            $auth->failed_at = $now;
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_TIMED_OUT]);
            }

            Log::info('C6 authorization timed out', [
                'authorization_id' => $auth->id,
                'cpf'              => $auth->cpf,
            ]);

            return;
        }

        $result = $c6->checkAuthorizationStatus($auth->cpf);

        $auth->last_status_payload = $result['raw'] ?? null;
        $auth->last_checked_at     = $now;

        $remoteStatus = strtoupper($result['status'] ?? 'PENDING');

        // Ajuste esse mapping conforme os valores reais do C6.
        if (in_array($remoteStatus, ['AUTHORIZED', 'AUTORIZADO'], true)) {
            $auth->status        = BankAuthorization::STATUS_AUTHORIZED;
            $auth->authorized_at = $now;
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_AUTHORIZED]);
            }

            // 1) Envia mensagem no MESMO ticket (sem abrir novo).
            if ($auth->phone) {
                $body = "Autorização de consulta no C6 Bank concluída.\n"
                    . "Agora vou te encaminhar para um atendente humano para seguir com a análise do crédito.";

                // openTicket = "0" => não abre ticket novo
                // queueId = "0"   => ignorado quando openTicket = "0"
                $texts->sendText(
                    $auth->phone,
                    $body,
                    '0',
                    '0'
                );
            }

            // 2) Atualiza o ticket para a fila dos vendedores (Estratégia A).
            $handoffQueueId = config('inovachat.handoff.queue_id');
            $handoffStatus  = config('inovachat.handoff.status', 'pending');

            if ($auth->triage && $auth->triage->ticket_id && $handoffQueueId) {
                $ticketId = (string) $auth->triage->ticket_id;

                $tickets->updateTicket(
                    $ticketId,
                    $handoffStatus,
                    (string) $handoffQueueId,
                    userId: null,
                    typebotSessionId: null,
                    customA: null,
                    customB: null
                );
            } else {
                Log::warning('C6 authorization authorized but cannot handoff ticket', [
                    'authorization_id' => $auth->id,
                    'cpf'              => $auth->cpf,
                    'has_triage'       => (bool) $auth->triage,
                    'triage_ticket_id' => $auth->triage->ticket_id ?? null,
                    'handoff_queue_id' => $handoffQueueId,
                ]);
            }

            Log::info('C6 authorization authorized and handed off on same ticket', [
                'authorization_id' => $auth->id,
                'cpf'              => $auth->cpf,
            ]);

            return;
        }

        if (in_array($remoteStatus, ['DENIED', 'NAO_AUTORIZADO', 'NOT_AUTHORIZED'], true)) {
            $auth->status    = BankAuthorization::STATUS_DENIED;
            $auth->failed_at = $now;
            $auth->save();

            if ($auth->triage) {
                $auth->triage->update(['status' => BankAuthorization::STATUS_DENIED]);
            }

            Log::info('C6 authorization denied', [
                'authorization_id' => $auth->id,
                'cpf'              => $auth->cpf,
            ]);

            // Aqui você PODE futuramente:
            // - enviar mensagem ao cliente
            // - mover para fila "C6 – Não Autorizados" ou encerrar o ticket via updateAPI.
            // Por enquanto, mantém comportamento mínimo atual.
            return;
        }

        // Qualquer outro status é tratado como "ainda em análise / pendente"
        $auth->status = BankAuthorization::STATUS_PENDING;
        $auth->save();

        // Reagenda novo polling
        $delaySeconds = (int) env('C6_AUTH_STATUS_POLL_INTERVAL', 60);

        self::dispatch($auth->id)
            ->delay($now->addSeconds($delaySeconds));

        Log::info('C6 authorization still pending, rescheduling polling', [
            'authorization_id' => $auth->id,
            'cpf'              => $auth->cpf,
            'remote_status'    => $remoteStatus,
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
}
