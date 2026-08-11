<?php

namespace App\Modules\SomaClt\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\SomaClt\Jobs\StoreSomaCltExternalReportJob;
use App\Modules\SomaClt\Models\SomaCltConsultJob;
use App\Modules\SomaClt\Services\SomaCltExternalApiService;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class SomaCltConsultController extends Controller
{
    private const ACTIVE_STATUSES = ['agendado', 'pendente', 'em_progresso'];
    private const TERMINAL_STATUSES = ['concluido', 'falhou', 'cancelado'];

    public function index(Request $request)
    {
        $this->refreshActiveExternalJobs();

        $data = Validator::make($request->query(), [
            'status' => ['nullable', 'in:agendado,pendente,em_progresso,pausado,concluido,falhou,cancelado,todos'],
        ])->validate();

        $query = SomaCltConsultJob::query()->orderByDesc('created_at');
        if (($data['status'] ?? 'todos') !== 'todos') {
            $query->where('status', $data['status']);
        }

        return response()->json($query->paginate(15));
    }

    public function show(int $id)
    {
        $job = $this->ownedJob($id);
        $this->syncExternalJob($job);
        $job->refresh();

        return response()->json($this->jobPayload($job));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'mode' => ['required', 'in:uy3,celcoin,both'],
            'lines' => ['required', 'string'],
            'run_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ]);

        $lines = $this->normalizeLines($data['lines']);
        if ($lines instanceof \Illuminate\Http\JsonResponse) {
            return $lines;
        }

        $timezone = (string) ($data['timezone'] ?? 'America/Sao_Paulo');
        $runAt = isset($data['run_at']) ? Carbon::parse($data['run_at'], $timezone) : null;
        $scheduledFor = $runAt && $runAt->greaterThan(now($timezone)) ? $runAt->clone()->utc() : null;

        $lock = Cache::lock('soma_clt_consult_creation', 60);
        if (! $lock->get()) {
            return response()->json(['message' => 'Já existe uma criação de consulta Soma CLT em andamento.'], Response::HTTP_CONFLICT);
        }

        try {
            if ($this->hasActiveJob()) {
                return response()->json(['message' => 'Já existe uma consulta Soma CLT em andamento.'], Response::HTTP_CONFLICT);
            }

            try {
                $remote = app(SomaCltExternalApiService::class)->createJob(
                    $data['title'], $data['mode'], $lines['body'], $scheduledFor?->toIso8601String()
                );
                $externalJobId = $remote['id'] ?? null;
                if (! is_string($externalJobId) || $externalJobId === '') {
                    throw new \RuntimeException('A API externa não retornou o identificador do job.');
                }
            } catch (\Throwable $e) {
                Log::warning('[SOMA CLT] Falha ao criar job na API externa: ' . $e->getMessage());

                return response()->json(['message' => 'Não foi possível criar a consulta na API externa.'], Response::HTTP_BAD_GATEWAY);
            }

            $job = SomaCltConsultJob::create([
                'user_id' => $request->user()->id,
                'title' => $data['title'],
                'mode' => $data['mode'],
                'executor' => 'api',
                'external_job_id' => $externalJobId,
                'status' => $scheduledFor ? 'agendado' : 'pendente',
                'total_cpfs' => $lines['count'],
                'scheduled_for' => $scheduledFor,
            ]);
            $this->syncExternalJob($job, $remote);
            $job->refresh();

            return response()->json($this->jobPayload($job), Response::HTTP_ACCEPTED);
        } finally {
            $lock->release();
        }
    }

    public function requestPreview(int $id)
    {
        $job = $this->ownedJob($id);
        $this->syncExternalJob($job);
        $job->refresh();

        return response()->json([
            'queued' => false,
            'preview_running' => in_array($job->status, ['pendente', 'em_progresso', 'pausado'], true),
            'message' => 'Prévia disponível diretamente na API externa.',
        ]);
    }

    public function downloadPreview(int $id)
    {
        return $this->externalDownload($this->ownedJob($id), 'preview');
    }

    public function download(int $id)
    {
        $job = $this->ownedJob($id);
        if ($this->hasStoredReport($job)) {
            return $this->downloadStoredReport($job);
        }

        return $this->externalDownload($job, 'report');
    }

    public function pause(int $id)
    {
        $job = $this->ownedJob($id);
        if (! in_array($job->status, ['pendente', 'em_progresso'], true)) {
            return response()->json(['message' => 'Job não pode ser pausado neste estado.', 'status' => $job->status], Response::HTTP_CONFLICT);
        }

        return $this->control($job, 'pauseJob', 'pausar');
    }

    public function resume(int $id)
    {
        $job = $this->ownedJob($id);
        if ($job->status !== 'pausado') {
            return response()->json(['message' => 'Apenas jobs pausados podem ser retomados.', 'status' => $job->status], Response::HTTP_CONFLICT);
        }
        if ($this->hasActiveJob($job->id)) {
            return response()->json(['message' => 'Já existe uma consulta Soma CLT em andamento.'], Response::HTTP_CONFLICT);
        }

        return $this->control($job, 'resumeJob', 'retomar');
    }

    public function cancel(Request $request, int $id)
    {
        $job = $this->ownedJob($id);
        if (in_array($job->status, self::TERMINAL_STATUSES, true)) {
            return response()->json(['message' => 'Job não pode ser cancelado neste estado.', 'status' => $job->status], Response::HTTP_CONFLICT);
        }

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:191']]);
        try {
            $remote = app(SomaCltExternalApiService::class)->cancelJob($job->external_job_id);
            $this->syncExternalJob($job, $remote);
            $job->update(['cancel_reason' => $data['reason'] ?? null]);
            $job->refresh();
        } catch (\Throwable $e) {
            Log::warning("[SOMA CLT] Falha ao cancelar job externo {$job->id}: {$e->getMessage()}");

            return response()->json(['message' => 'Não foi possível cancelar a consulta na API externa.'], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json($this->jobPayload($job));
    }

    public function destroy(int $id)
    {
        $job = $this->ownedJob($id);
        if (! in_array($job->status, self::TERMINAL_STATUSES, true)) {
            return response()->json(['message' => 'Não é possível excluir enquanto o job está em andamento.', 'status' => $job->status], Response::HTTP_CONFLICT);
        }

        try {
            $response = app(SomaCltExternalApiService::class)->deleteJob($job->external_job_id);
        } catch (\Throwable $e) {
            Log::warning("[SOMA CLT] Falha ao excluir job externo {$job->id}: {$e->getMessage()}");

            return response()->json(['message' => 'Não foi possível excluir a consulta na API externa.'], Response::HTTP_BAD_GATEWAY);
        }
        if (! $response->successful() && $response->status() !== Response::HTTP_NOT_FOUND) {
            return response()->json(['message' => 'A API externa não permitiu excluir a consulta.'], $response->status() === Response::HTTP_CONFLICT ? Response::HTTP_CONFLICT : Response::HTTP_BAD_GATEWAY);
        }

        if ($this->hasStoredReport($job)) {
            Storage::disk($job->file_disk)->delete($job->file_path);
        }
        $job->delete();

        return response()->noContent();
    }

    private function control(SomaCltConsultJob $job, string $method, string $action)
    {
        try {
            $remote = app(SomaCltExternalApiService::class)->{$method}($job->external_job_id);
            $this->syncExternalJob($job, $remote);
            $job->refresh();
        } catch (\Throwable $e) {
            Log::warning("[SOMA CLT] Falha ao {$action} job externo {$job->id}: {$e->getMessage()}");

            return response()->json(['message' => "Não foi possível {$action} a consulta na API externa."], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json($this->jobPayload($job), Response::HTTP_ACCEPTED);
    }

    private function hasActiveJob(?int $excludingJobId = null): bool
    {
        $this->refreshActiveExternalJobs();
        $query = SomaCltConsultJob::query()->whereIn('status', self::ACTIVE_STATUSES);
        if ($excludingJobId !== null) {
            $query->whereKeyNot($excludingJobId);
        }

        return $query->exists();
    }

    private function refreshActiveExternalJobs(): void
    {
        SomaCltConsultJob::query()->whereIn('status', [...self::ACTIVE_STATUSES, 'pausado'])->each(function (SomaCltConsultJob $job): void {
            try {
                $this->syncExternalJob($job);
            } catch (\Throwable $e) {
                Log::warning("[SOMA CLT] Não foi possível atualizar job externo ativo {$job->id}: {$e->getMessage()}");
            }
        });
    }

    private function syncExternalJob(SomaCltConsultJob $job, ?array $remote = null): void
    {
        $remote ??= app(SomaCltExternalApiService::class)->getJob($job->external_job_id);
        $status = match ((string) ($remote['status'] ?? 'queued')) {
            'scheduled' => 'agendado', 'completed' => 'concluido', 'failed', 'expired' => 'falhou',
            'cancelled' => 'cancelado', 'paused' => 'pausado', 'running', 'pausing' => 'em_progresso', default => 'pendente',
        };
        $metrics = is_array($remote['metrics'] ?? null) ? $remote['metrics'] : [];
        $phase1Success = max(0, (int) ($metrics['phase1.success'] ?? 0));
        $phase1Declined = max(0, (int) ($metrics['phase1.declined'] ?? 0));
        $phase1Errors = max(0, (int) ($metrics['phase1.errors'] ?? 0));
        $phase1Pending = max(0, (int) ($metrics['phase1.pending'] ?? 0));
        $phase2Success = max(0, (int) ($metrics['phase2.success'] ?? 0));
        $phase2Declined = max(0, (int) ($metrics['phase2.declined'] ?? 0));
        $phase2Errors = max(0, (int) ($metrics['phase2.errors'] ?? 0));
        $hasReport = (bool) ($remote['has_report'] ?? false);
        $terminal = in_array($status, self::TERMINAL_STATUSES, true);

        $job->update([
            'status' => $status,
            'phase' => match ($remote['phase'] ?? null) { 'phase_1' => 'fase_1', 'phase_2' => 'fase_2', default => null },
            'total_cpfs' => max(0, (int) ($remote['total_count'] ?? data_get($remote, 'progress.phase_1.total') ?? $job->total_cpfs)),
            'success_count' => $phase1Success + $phase2Success,
            'policy_declined_count' => $phase1Declined + $phase2Declined,
            'fail_count' => $phase1Errors + $phase2Errors,
            'phase1_pending_count' => $phase1Pending,
            'phase1_success_count' => $phase1Success,
            'phase1_declined_count' => $phase1Declined,
            'phase1_errors_count' => $phase1Errors,
            'phase2_success_count' => $phase2Success,
            'phase2_declined_count' => $phase2Declined,
            'phase2_errors_count' => $phase2Errors,
            'external_has_report' => $hasReport,
            'scheduled_for' => $remote['scheduled_for'] ?? $job->scheduled_for,
            'started_at' => $remote['started_at'] ?? $job->started_at ?? ($status === 'em_progresso' ? now() : null),
            'paused_at' => $remote['paused_at'] ?? ($status === 'pausado' ? ($job->paused_at ?? now()) : null),
            'finished_at' => $remote['finished_at'] ?? ($terminal ? ($job->finished_at ?? now()) : null),
            'canceled_at' => $remote['cancelled_at'] ?? $remote['canceled_at'] ?? ($status === 'cancelado' ? ($job->canceled_at ?? now()) : null),
        ]);

        if ($hasReport && ! $this->hasStoredReport($job)) {
            StoreSomaCltExternalReportJob::dispatch($job->id);
        }
    }

    private function normalizeLines(string $raw): array|\Illuminate\Http\JsonResponse
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $normalized = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/\d/', $line) !== 1 || preg_match('/^\s*([\d.\-]+)(.*)$/u', $line, $matches) !== 1) {
                continue;
            }
            $cpf = preg_replace('/\D/', '', $matches[1]);
            if ($cpf === '') {
                continue;
            }
            $name = trim(preg_replace('/^[\s;,\t]+/u', '', $matches[2]) ?? '');
            $normalized[] = (strlen($cpf) < 11 ? str_pad($cpf, 11, '0', STR_PAD_LEFT) : $cpf) . ';' . $name;
        }
        if ($normalized === [] || count($normalized) > 40000) {
            return response()->json(['message' => 'Informe entre 1 e 40.000 linhas com CPF.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return ['body' => implode("\n", $normalized), 'count' => count($normalized)];
    }

    private function ownedJob(int $id): SomaCltConsultJob
    {
        return SomaCltConsultJob::query()->where('user_id', Auth::id())->findOrFail($id);
    }

    private function hasStoredReport(SomaCltConsultJob $job): bool
    {
        return $job->file_disk && $job->file_path && Storage::disk($job->file_disk)->exists($job->file_path);
    }

    private function downloadStoredReport(SomaCltConsultJob $job)
    {
        $stream = Storage::disk($job->file_disk)->readStream($job->file_path);
        if ($stream === false) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        return response()->streamDownload(function () use ($stream): void {
            try { fpassthru($stream); } finally { if (is_resource($stream)) fclose($stream); }
        }, $job->file_name ?: $this->filename($job) . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function externalDownload(SomaCltConsultJob $job, string $kind)
    {
        try {
            $response = $kind === 'preview'
                ? app(SomaCltExternalApiService::class)->preview($job->external_job_id)
                : app(SomaCltExternalApiService::class)->report($job->external_job_id);
        } catch (\Throwable $e) {
            Log::warning("[SOMA CLT] Falha ao baixar {$kind} externo {$job->id}: {$e->getMessage()}");

            return response()->json(['message' => 'Não foi possível baixar o arquivo da API externa.'], Response::HTTP_BAD_GATEWAY);
        }
        if (! $response->successful()) {
            $status = in_array($response->status(), [Response::HTTP_NOT_FOUND, Response::HTTP_CONFLICT], true) ? $response->status() : Response::HTTP_BAD_GATEWAY;

            return response()->json(['message' => 'Arquivo indisponível na API externa.'], $status);
        }

        $filename = $this->externalFilename($response, $this->filename($job) . ($kind === 'preview' ? '-preview' : '') . '.csv');

        return response()->streamDownload(fn () => print $response->body(), $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function externalFilename(HttpResponse $response, string $fallback): string
    {
        if (preg_match('/filename\\*?=(?:UTF-8\\x27\\x27|\")?([^\";]+)/i', (string) $response->header('Content-Disposition', ''), $matches) === 1) {
            return trim(rawurldecode($matches[1]), " \"");
        }

        return $fallback;
    }

    private function filename(SomaCltConsultJob $job): string
    {
        return (string) config('soma_clt.storage.final_prefix', 'soma-clt-consulta') . "-{$job->id}";
    }

    private function jobPayload(SomaCltConsultJob $job): array
    {
        return [
            'id' => $job->id, 'title' => $job->title, 'mode' => $job->mode, 'status' => $job->status,
            'phase' => $job->phase, 'total_cpfs' => $job->total_cpfs, 'success_count' => $job->success_count,
            'policy_declined_count' => $job->policy_declined_count, 'fail_count' => $job->fail_count,
            'phase1_pending_count' => $job->phase1_pending_count, 'phase1_success_count' => $job->phase1_success_count,
            'phase1_declined_count' => $job->phase1_declined_count, 'phase1_errors_count' => $job->phase1_errors_count,
            'phase2_success_count' => $job->phase2_success_count, 'phase2_declined_count' => $job->phase2_declined_count,
            'phase2_errors_count' => $job->phase2_errors_count,
            'has_file' => (bool) $job->external_has_report, 'started_at' => $job->started_at,
            'finished_at' => $job->finished_at, 'canceled_at' => $job->canceled_at, 'paused_at' => $job->paused_at,
            'cancel_reason' => $job->cancel_reason, 'scheduled_for' => $job->scheduled_for, 'created_at' => $job->created_at,
            'preview_running' => in_array($job->status, ['pendente', 'em_progresso', 'pausado'], true),
        ];
    }
}
