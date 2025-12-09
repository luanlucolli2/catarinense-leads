<?php

namespace App\Jobs;

use App\Models\BankAuthorization;
use App\Services\C6\C6AuthorizationService;
use App\Services\Inovachat\TextMessageService;
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
        TextMessageService $texts
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

            // Encaminhar para fila humana via Inovachat:
            // Se quiser abrir novo ticket numa fila específica, configure estes envs.
            $handoffOpenTicket = env('INOVACHAT_HANDOFF_OPEN_TICKET', '1'); // "1" abre novo ticket
            $handoffQueueId    = env('INOVACHAT_HANDOFF_QUEUE_ID', '98');   // ID da fila humana

            if ($auth->phone) {
                $body = "Autorização de consulta no C6 Bank concluída.\n"
                    . "Agora vou te encaminhar para um atendente humano para seguir com a análise do crédito.";

                $texts->sendText(
                    $auth->phone,
                    $body,
                    $handoffOpenTicket,
                    $handoffQueueId
                );
            }

            Log::info('C6 authorization authorized and handed off', [
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
