<?php

namespace App\Modules\V8\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\V8\Jobs\DispatchV8ConsultJob;
use App\Modules\V8\Jobs\StoreV8ExternalReportJob;
use App\Modules\V8\Models\V8ConsultJob;
use App\Modules\V8\Services\V8ExternalApiService;
use App\Modules\V8\Support\V8Schema;
use App\Modules\V8\Support\V8Spool;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class V8ConsultController extends Controller
{
    public function index()
    {
        $this->refreshActiveExternalJobs();

        $jobs = V8ConsultJob::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($jobs);
    }

    public function show(int $id)
    {
        $job = V8ConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            $this->syncExternalJob($job);
        }

        $reportsDiskName = (string) config('v8.storage.reports_disk', 'local');
        $reportsDisk = Storage::disk($reportsDiskName);
        $spoolExists = $job->executor === 'local' && $job->spool_path && $reportsDisk->exists($job->spool_path);
        $spoolHasDataRows = $spoolExists && V8Spool::hasDataRows($reportsDisk, $job->spool_path);

        return response()->json([
            'id' => $job->id,
            'title' => $job->title,
            'executor' => $job->executor,
            'status' => $job->status,
            'phase' => $job->phase,
            'reuse_recent_consults' => (bool) $job->reuse_recent_consults,
            'reuse_recent_consults_days' => (int) ($job->reuse_recent_consults_days ?? 30),
            'total_cpfs' => $job->total_cpfs,
            'success_count' => $job->success_count,
            'nao_elegivel_count' => $job->nao_elegivel_count,
            'fail_count' => $job->fail_count,
            'has_file' => $job->executor === 'api' ? (bool) $job->external_has_report : (bool) $job->has_file,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'canceled_at' => $job->canceled_at,
            'paused_at' => $job->paused_at,
            'cancel_reason' => $job->cancel_reason,
            'scheduled_for' => $job->scheduled_for,
            'created_at' => $job->created_at,
            'preview_running' => $job->executor === 'api'
                ? in_array($job->status, ['pendente', 'em_progresso', 'pausado'], true)
                : in_array($job->status, ['pendente', 'em_progresso', 'pausado', 'cancelado'], true) && $spoolHasDataRows,
            'spool_bytes' => $job->spool_bytes,
            'spool_path' => $job->spool_path,
            'spool_inputs_path' => $job->spool_inputs_path,
            'phase1_submitted_count' => $job->phase1_submitted_count,
            'phase1_not_eligible_count' => $job->phase1_not_eligible_count,
            'phase1_errors_count' => $job->phase1_errors_count,
            'phase2_approved_count' => $job->phase2_approved_count,
            'phase2_not_approved_count' => $job->phase2_not_approved_count,
            'phase2_errors_count' => $job->phase2_errors_count,
        ]);
    }

    public function store(Request $request)
    {
        $rawLines = $request->input('lines', $request->input('entries', $request->input('rows')));

        $validator = Validator::make([
            'title' => $request->input('title'),
            'lines' => $rawLines,
            'run_at' => $request->input('run_at'),
            'timezone' => $request->input('timezone'),
            'reuse_recent_consults' => $request->input('reuse_recent_consults'),
            'reuse_recent_consults_days' => $request->input('reuse_recent_consults_days'),
        ], [
            'title' => ['required', 'string', 'max:191'],
            'lines' => ['required'],
            'run_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
            'reuse_recent_consults' => ['nullable', 'boolean'],
            'reuse_recent_consults_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Os dados fornecidos são inválidos.',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $reuseRecentConsults = filter_var($request->input('reuse_recent_consults', false), FILTER_VALIDATE_BOOL);
        $reuseRecentConsultsDays = max(1, min(90, (int) $request->input('reuse_recent_consults_days', 30)));
        $timezone = (string) ($request->input('timezone') ?: 'America/Sao_Paulo');
        $runAtRaw = $request->input('run_at');
        $runAt = is_string($runAtRaw) && $runAtRaw !== ''
            ? Carbon::parse($runAtRaw, $timezone)
            : null;
        $scheduledFor = $runAt && $runAt->greaterThan(Carbon::now($timezone))
            ? $runAt->clone()->setTimezone('UTC')
            : null;

        $lock = Cache::lock('v8_consult_creation', 60);
        if (!$lock->get()) {
            return response()->json(['message' => 'Já existe uma criação de consulta V8 CLT em andamento.'], Response::HTTP_CONFLICT);
        }

        try {
            if ($this->hasActiveJob()) {
                return response()->json(['message' => 'Já existe uma consulta V8 CLT em andamento.'], Response::HTTP_CONFLICT);
            }

            return $this->storeExternalJob($request, $rawLines, $reuseRecentConsults, $reuseRecentConsultsDays, $scheduledFor);
        } finally {
            $lock->release();
        }
    }

    private function storeLocalJob(Request $request, mixed $rawLines, bool $reuseRecentConsults, int $reuseRecentConsultsDays, ?Carbon $scheduledFor)
    {
        $job = V8ConsultJob::create([
            'user_id' => $request->user()->id,
            'title' => (string) $request->input('title'),
            'executor' => 'local',
            'status' => $scheduledFor ? 'agendado' : 'pendente',
            'total_cpfs' => 0,
            'success_count' => 0,
            'nao_elegivel_count' => 0,
            'fail_count' => 0,
            'scheduled_for' => $scheduledFor,
            'reuse_recent_consults' => $reuseRecentConsults,
            'reuse_recent_consults_days' => $reuseRecentConsultsDays,
        ]);

        try {
            [$spoolPath, $inputsPath, $spoolBytes, $linesCount] = $this->createInitialSpool(
                $job->id,
                $this->tokenizeLinesLazy($rawLines)
            );
        } catch (\Throwable $e) {
            $this->safeCleanupInit($job->id);
            $job->delete();
            Log::error("[V8] Erro ao preparar spool (job {$job->id}): " . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Falha interna ao preparar arquivos do job.'], 500);
        }

        if ($linesCount === 0) {
            $this->safeCleanupPaths([$spoolPath, $inputsPath]);
            $job->delete();
            return response()->json(['message' => 'Nenhuma linha válida encontrada.'], 422);
        }

        $job->update([
            'spool_path' => $spoolPath,
            'spool_inputs_path' => $inputsPath,
            'spool_bytes' => $spoolBytes,
        ]);

        if ($job->status === 'pendente') {
            DispatchV8ConsultJob::dispatch($job->id)
                ->onQueue((string) config('v8.job.queue', 'v8'));
        }

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
            'scheduled_for' => $job->scheduled_for,
        ], Response::HTTP_ACCEPTED);
    }

    public function requestPreview(Request $request, int $id)
    {
        $job = V8ConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            $this->syncExternalJob($job);

            return response()->json([
                'queued' => false,
                'preview_running' => in_array($job->status, ['pendente', 'em_progresso', 'pausado'], true),
                'message' => 'Prévia disponível diretamente na API externa.',
            ]);
        }

        $disk = Storage::disk((string) config('v8.storage.reports_disk', 'local'));
        $spoolExists = $job->spool_path && $disk->exists($job->spool_path);
        $spoolHasDataRows = $spoolExists && V8Spool::hasDataRows($disk, $job->spool_path);

        return response()->json([
            'queued' => false,
            'preview_running' => in_array($job->status, ['pendente', 'em_progresso', 'pausado', 'cancelado'], true) && $spoolHasDataRows,
            'message' => 'Prévia espelha o spool no momento da leitura.',
        ], Response::HTTP_OK);
    }

    public function downloadPreview(Request $request, int $id)
    {
        $job = V8ConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            return $this->externalDownload($job, 'preview');
        }

        $disk = Storage::disk((string) config('v8.storage.reports_disk', 'local'));

        if (empty($job->spool_path) || !$disk->exists($job->spool_path)) {
            return response()->json(['message' => 'Spool indisponível.'], Response::HTTP_CONFLICT);
        }

        $real = $disk->path($job->spool_path);
        $fh = @fopen($real, 'rb');
        if ($fh === false) {
            return response()->json(['message' => 'Falha ao abrir arquivo.'], 500);
        }

        $filename = "{$this->finalPrefix()}_{$job->id}_preview.csv";
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Accel-Buffering' => 'no',
        ];

        $withBOM = (bool) config('v8.csv.embed_bom', true);
        $finalEol = strtoupper((string) config('v8.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";

        return response()->streamDownload(function () use ($fh, $withBOM, $finalEol) {
            try {
                flock($fh, LOCK_SH);

                if ($withBOM) {
                    echo "\xEF\xBB\xBF";
                }

                $peek = fread($fh, 3);
                if ($peek !== "\xEF\xBB\xBF") {
                    fseek($fh, 0);
                }

                fgets($fh);

                echo V8Schema::headerCsvLine(';') . $finalEol;

                fpassthru($fh);
            } finally {
                flock($fh, LOCK_UN);
                if (is_resource($fh)) {
                    fclose($fh);
                }
            }
        }, $filename, $headers);
    }

    public function download(int $id)
    {
        $job = V8ConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            if ($this->hasStoredReport($job)) {
                return $this->downloadStoredReport($job);
            }

            return $this->externalDownload($job, 'report');
        }

        if (!in_array($job->status, ['concluido', 'falhou', 'cancelado'], true) || empty($job->file_disk) || empty($job->file_path)) {
            return response()->json(['message' => 'Relatório ainda não disponível.'], 409);
        }

        $disk = Storage::disk($job->file_disk);
        $filename = $job->file_name ?: "{$this->finalPrefix()}-{$job->id}.csv";

        if (!$disk->exists($job->file_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], 404);
        }

        $fh = $disk->readStream($job->file_path);
        if ($fh === false) {
            return response()->json(['message' => 'Falha ao abrir arquivo.'], 500);
        }

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Accel-Buffering' => 'no',
        ];

        return response()->streamDownload(function () use ($fh) {
            try {
                fpassthru($fh);
            } finally {
                if (is_resource($fh)) {
                    fclose($fh);
                }
            }
        }, $filename, $headers);
    }

    public function cancel(Request $request, int $id)
    {
        $job = V8ConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            if (in_array($job->status, ['concluido', 'falhou', 'cancelado'], true)) {
                return response()->json([
                    'message' => 'Job não pode ser cancelado neste estado.',
                    'status' => $job->status,
                ], Response::HTTP_CONFLICT);
            }

            $data = $request->validate(['reason' => ['nullable', 'string', 'max:191']]);

            try {
                $remote = app(V8ExternalApiService::class)->cancelJob((string) $job->external_job_id);
                $this->syncExternalJob($job, $remote);
                $job->update(['cancel_reason' => $data['reason'] ?? null]);
            } catch (\Throwable $e) {
                Log::warning("[V8] Falha ao cancelar job externo {$job->id}: " . $e->getMessage());

                return response()->json(['message' => 'Não foi possível cancelar a consulta na API externa.'], Response::HTTP_BAD_GATEWAY);
            }

            $job->refresh();

            return response()->json([
                'id' => $job->id,
                'status' => $job->status,
                'phase' => $job->phase,
                'canceled_at' => $job->canceled_at,
                'cancel_reason' => $job->cancel_reason,
                'finished_at' => $job->finished_at,
            ]);
        }

        if (in_array($job->status, ['concluido', 'falhou', 'cancelado'], true)) {
            return response()->json([
                'message' => 'Job não pode ser cancelado neste estado.',
                'status' => $job->status,
            ], Response::HTTP_CONFLICT);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $waitForWorkerToStop = $job->status === 'em_progresso';

        $job->update([
            'status' => 'cancelado',
            'phase' => null,
            'canceled_at' => now(),
            'paused_at' => null,
            'cancel_reason' => $data['reason'] ?? null,
            'finished_at' => $waitForWorkerToStop ? null : now(),
        ]);

        if (!$waitForWorkerToStop) {
            $this->finalizeCancelledPreservingUsefulPreview($job);
            $job->refresh();
        }

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
            'canceled_at' => $job->canceled_at,
            'cancel_reason' => $job->cancel_reason,
            'finished_at' => $job->finished_at,
        ]);
    }

    public function pause(int $id)
    {
        $job = V8ConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            try {
                $remote = app(V8ExternalApiService::class)->pauseJob((string) $job->external_job_id);
                $this->syncExternalJob($job, $remote);
            } catch (\Throwable $e) {
                Log::warning("[V8] Falha ao pausar job externo {$job->id}: " . $e->getMessage());

                return response()->json(['message' => 'Não foi possível pausar a consulta na API externa.'], Response::HTTP_BAD_GATEWAY);
            }

            $job->refresh();

            return response()->json([
                'id' => $job->id,
                'status' => $job->status,
                'phase' => $job->phase,
                'paused_at' => $job->paused_at,
            ], Response::HTTP_ACCEPTED);
        }

        if ($job->status === 'pausado') {
            return response()->json([
                'id' => $job->id,
                'status' => $job->status,
                'phase' => $job->phase,
                'paused_at' => $job->paused_at,
            ]);
        }

        if (!in_array($job->status, ['pendente', 'em_progresso'], true)) {
            return response()->json([
                'message' => 'Job não pode ser pausado neste estado.',
                'status' => $job->status,
            ], Response::HTTP_CONFLICT);
        }

        $job->update([
            'status' => 'pausado',
            'paused_at' => now(),
        ]);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
            'paused_at' => $job->paused_at,
        ]);
    }

    public function resume(int $id)
    {
        $job = V8ConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            try {
                $remote = app(V8ExternalApiService::class)->resumeJob((string) $job->external_job_id);
                $this->syncExternalJob($job, $remote);
            } catch (\Throwable $e) {
                Log::warning("[V8] Falha ao retomar job externo {$job->id}: " . $e->getMessage());

                return response()->json(['message' => 'Não foi possível retomar a consulta na API externa.'], Response::HTTP_BAD_GATEWAY);
            }

            $job->refresh();

            return response()->json([
                'id' => $job->id,
                'status' => $job->status,
                'phase' => $job->phase,
            ], Response::HTTP_ACCEPTED);
        }

        if ($job->status !== 'pausado') {
            return response()->json([
                'message' => 'Apenas jobs pausados podem ser retomados.',
                'status' => $job->status,
            ], Response::HTTP_CONFLICT);
        }

        $disk = Storage::disk((string) config('v8.storage.reports_disk', 'local'));
        if (
            empty($job->spool_path)
            || empty($job->spool_inputs_path)
            || !$disk->exists($job->spool_path)
            || !$disk->exists($job->spool_inputs_path)
        ) {
            return response()->json([
                'message' => 'Spool indisponível para retomar o job.',
            ], Response::HTTP_CONFLICT);
        }

        $job->update([
            'status' => 'pendente',
            'paused_at' => null,
            'finished_at' => null,
            'canceled_at' => null,
            'cancel_reason' => null,
        ]);

        DispatchV8ConsultJob::dispatch($job->id)
            ->delay(now()->addSeconds(2))
            ->onQueue((string) config('v8.job.queue', 'v8'));

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
        ], Response::HTTP_ACCEPTED);
    }

    private function cleanupSpoolArtifacts($disk, int $jobId, ?string $preserveRel = null): void
    {
        try {
            $dirSpool = (string) config('v8.storage.dir_spool', 'v8-spool');
            $prefix = (string) config('v8.storage.final_prefix', 'v8-consulta');
            $prefix = $prefix . '_' . $jobId;

            if (!$disk->exists($dirSpool)) {
                return;
            }

            foreach ($disk->files($dirSpool) as $rel) {
                $base = basename($rel);
                if ($preserveRel !== null && $rel === $preserveRel) {
                    continue;
                }
                if (str_starts_with($base, $prefix)) {
                    try {
                        $disk->delete($rel);
                    } catch (\Throwable) {
                    }
                }
            }
        } catch (\Throwable) {
        }
    }

    public function destroy(int $id)
    {
        $job = V8ConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $cancelStopPending = $job->status === 'cancelado' && empty($job->finished_at);

        if (in_array($job->status, ['agendado', 'pendente', 'em_progresso', 'pausado'], true) || $cancelStopPending) {
            return response()->json([
                'message' => 'Não é possível excluir enquanto o job ainda está agendado, em andamento, pausado ou finalizando o cancelamento.',
                'status' => $job->status,
            ], Response::HTTP_CONFLICT);
        }

        if ($job->executor === 'api') {
            try {
                $response = app(V8ExternalApiService::class)->deleteJob((string) $job->external_job_id);
            } catch (\Throwable $e) {
                Log::warning("[V8] Falha ao excluir job externo {$job->id}: " . $e->getMessage());

                return response()->json(['message' => 'Não foi possível excluir a consulta na API externa.'], Response::HTTP_BAD_GATEWAY);
            }

            if (!$response->successful() && $response->status() !== Response::HTTP_NOT_FOUND) {
                $status = $response->status() === Response::HTTP_CONFLICT
                    ? Response::HTTP_CONFLICT
                    : Response::HTTP_BAD_GATEWAY;

                return response()->json(['message' => 'A API externa não permitiu excluir a consulta.'], $status);
            }
        }

        try {
            if ($job->file_disk && $job->file_path) {
                $disk = Storage::disk($job->file_disk);
                if ($disk->exists($job->file_path)) {
                    $disk->delete($job->file_path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[V8] Erro ao apagar arquivo final (job {$job->id}): " . $e->getMessage());
        }

        try {
            $diskName = (string) config('v8.storage.reports_disk', 'local');
            $disk = Storage::disk($diskName);
            foreach (['spool_path', 'spool_inputs_path'] as $field) {
                $p = $job->{$field};
                if ($p && $disk->exists($p)) {
                    $disk->delete($p);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[V8] Erro ao apagar spool (job {$job->id}): " . $e->getMessage());
        }

        $job->delete();

        return response()->noContent();
    }

    private function storeExternalJob(Request $request, mixed $rawLines, bool $reuseRecentConsults, int $reuseRecentConsultsDays, ?Carbon $scheduledFor)
    {
        $job = V8ConsultJob::create([
            'user_id' => $request->user()->id,
            'title' => (string) $request->input('title'),
            'executor' => 'api',
            'status' => $scheduledFor ? 'agendado' : 'pendente',
            'total_cpfs' => 0,
            'success_count' => 0,
            'nao_elegivel_count' => 0,
            'fail_count' => 0,
            'scheduled_for' => $scheduledFor,
            'reuse_recent_consults' => $reuseRecentConsults,
            'reuse_recent_consults_days' => $reuseRecentConsultsDays,
        ]);

        try {
            $remote = app(V8ExternalApiService::class)->createJob(
                $job->title,
                $this->externalInput($rawLines),
                $reuseRecentConsults,
                $scheduledFor?->toIso8601String()
            );
            $externalJobId = $remote['id'] ?? null;
            if (!is_string($externalJobId) || $externalJobId === '') {
                throw new \RuntimeException('A API externa não retornou o identificador do job.');
            }

            $job->update(['external_job_id' => $externalJobId]);
            $this->syncExternalJob($job, $remote);
        } catch (\Throwable $e) {
            $job->delete();
            Log::warning('[V8] Falha ao criar job na API externa: ' . $e->getMessage());

            return response()->json(['message' => 'Não foi possível criar a consulta na API externa.'], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
            'scheduled_for' => $job->scheduled_for,
        ], Response::HTTP_ACCEPTED);
    }

    private function hasActiveJob(): bool
    {
        $this->refreshActiveExternalJobs();

        return V8ConsultJob::query()
            ->whereIn('status', ['agendado', 'pendente', 'em_progresso', 'pausado'])
            ->exists();
    }

    private function refreshActiveExternalJobs(): void
    {
        $externalJobs = V8ConsultJob::query()
            ->where('executor', 'api')
            ->whereIn('status', ['agendado', 'pendente', 'em_progresso', 'pausado'])
            ->get();

        foreach ($externalJobs as $job) {
            try {
                $this->syncExternalJob($job);
            } catch (\Throwable $e) {
                Log::warning("[V8] Não foi possível atualizar job externo ativo {$job->id}: " . $e->getMessage());
            }
        }
    }

    private function externalInput(mixed $rawLines): string
    {
        if (is_string($rawLines)) {
            return $rawLines;
        }

        if (is_array($rawLines) || $rawLines instanceof \Traversable) {
            return implode("\n", array_filter(array_map(
                static fn ($value) => trim((string) $value),
                is_array($rawLines) ? $rawLines : iterator_to_array($rawLines)
            ), static fn ($value) => $value !== ''));
        }

        return '';
    }

    private function syncExternalJob(V8ConsultJob $job, ?array $remote = null): void
    {
        if (empty($job->external_job_id)) {
            throw new \RuntimeException('Job externo sem identificador.');
        }

        $remote ??= app(V8ExternalApiService::class)->getJob((string) $job->external_job_id);
        $remoteStatus = (string) ($remote['status'] ?? 'queued');
        $status = match ($remoteStatus) {
            'scheduled' => 'agendado',
            'completed' => 'concluido',
            'failed', 'expired' => 'falhou',
            'cancelled' => 'cancelado',
            'paused' => 'pausado',
            'running', 'pausing' => 'em_progresso',
            default => 'pendente',
        };
        $metrics = is_array($remote['metrics'] ?? null) ? $remote['metrics'] : [];
        $phase1Submitted = max(0, (int) ($metrics['phase1.submitted'] ?? 0));
        $phase1NotEligible = max(0, (int) ($metrics['phase1.not_eligible'] ?? 0));
        $phase1Errors = max(0, (int) ($metrics['phase1.errors'] ?? 0));
        $phase2Approved = max(0, (int) ($metrics['phase2.approved'] ?? 0));
        $phase2NotApproved = max(0, (int) ($metrics['phase2.not_approved'] ?? 0));
        $phase2Errors = max(0, (int) ($metrics['phase2.errors'] ?? 0));
        $hasReport = (bool) ($remote['has_report'] ?? false);
        $terminal = in_array($status, ['concluido', 'falhou', 'cancelado'], true);

        $job->update([
            'status' => $status,
            'phase' => match ($remote['phase'] ?? null) {
                'phase_1' => 'fase_1',
                'phase_2' => 'fase_2',
                default => null,
            },
            'total_cpfs' => max(0, (int) ($remote['total_count'] ?? data_get($remote, 'progress.phase_1.total') ?? 0)),
            'success_count' => $phase2Approved,
            'nao_elegivel_count' => $phase1NotEligible + $phase2NotApproved,
            'fail_count' => $phase1Errors + $phase2Errors,
            'phase1_submitted_count' => $phase1Submitted,
            'phase1_not_eligible_count' => $phase1NotEligible,
            'phase1_errors_count' => $phase1Errors,
            'phase2_approved_count' => $phase2Approved,
            'phase2_not_approved_count' => $phase2NotApproved,
            'phase2_errors_count' => $phase2Errors,
            'external_has_report' => $hasReport,
            'scheduled_for' => $remote['scheduled_for'] ?? $job->scheduled_for,
            'started_at' => $remote['started_at'] ?? $job->started_at ?? ($status === 'em_progresso' ? now() : null),
            'paused_at' => $remote['paused_at'] ?? ($status === 'pausado' ? ($job->paused_at ?? now()) : null),
            'finished_at' => $remote['finished_at'] ?? ($terminal && ($status !== 'cancelado' || $hasReport) ? ($job->finished_at ?? now()) : null),
            'canceled_at' => $remote['cancelled_at'] ?? ($status === 'cancelado' ? ($job->canceled_at ?? now()) : null),
        ]);

        if ($hasReport && !$this->hasStoredReport($job)) {
            StoreV8ExternalReportJob::dispatch($job->id);
        }
    }

    private function hasStoredReport(V8ConsultJob $job): bool
    {
        return $job->file_disk && $job->file_path && Storage::disk($job->file_disk)->exists($job->file_path);
    }

    private function downloadStoredReport(V8ConsultJob $job)
    {
        $disk = Storage::disk($job->file_disk);
        $stream = $disk->readStream($job->file_path);
        if ($stream === false) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        return response()->streamDownload(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, $job->file_name ?: "{$this->finalPrefix()}-{$job->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function externalDownload(V8ConsultJob $job, string $kind)
    {
        try {
            $response = $kind === 'preview'
                ? app(V8ExternalApiService::class)->preview((string) $job->external_job_id)
                : app(V8ExternalApiService::class)->report((string) $job->external_job_id);
        } catch (\Throwable $e) {
            Log::warning("[V8] Falha ao baixar {$kind} externo {$job->id}: " . $e->getMessage());

            return response()->json(['message' => 'Não foi possível baixar o arquivo da API externa.'], Response::HTTP_BAD_GATEWAY);
        }

        if (!$response->successful()) {
            $status = in_array($response->status(), [Response::HTTP_NOT_FOUND, Response::HTTP_CONFLICT], true)
                ? $response->status()
                : Response::HTTP_BAD_GATEWAY;

            return response()->json(['message' => 'Arquivo indisponível na API externa.'], $status);
        }

        $filename = $this->externalFilename($response, "{$this->finalPrefix()}-{$job->id}" . ($kind === 'preview' ? '-preview' : '') . '.csv');

        return response()->streamDownload(function () use ($response) {
            echo $response->body();
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function externalFilename(HttpResponse $response, string $fallback): string
    {
        $header = (string) $response->header('Content-Disposition', '');
        if (preg_match('/filename\\*?=(?:UTF-8\\x27\\x27|\")?([^\";]+)/i', $header, $matches) === 1) {
            return trim(rawurldecode($matches[1]), " \"");
        }

        return $fallback;
    }

    private function finalPrefix(): string
    {
        return (string) config('v8.storage.final_prefix', 'v8-consulta');
    }

    private function finalizeCancelledPreservingUsefulPreview(V8ConsultJob $job): void
    {
        $disk = Storage::disk((string) config('v8.storage.reports_disk', 'local'));
        $spoolPath = $job->spool_path ?? null;
        $hasDataRows = V8Spool::hasDataRows($disk, $spoolPath);

        try {
            $inputsPath = $job->spool_inputs_path ?? null;
            if ($inputsPath && $disk->exists($inputsPath)) {
                $disk->delete($inputsPath);
            }

            $this->cleanupSpoolArtifacts($disk, $job->id, $hasDataRows ? $spoolPath : null);

            if (!$hasDataRows && $spoolPath && $disk->exists($spoolPath)) {
                $disk->delete($spoolPath);
            }
        } catch (\Throwable $e) {
            Log::warning("[V8] Erro ao finalizar cancelamento (job {$job->id}): " . $e->getMessage());
        }

        $spoolBytes = 0;
        if ($hasDataRows && $spoolPath && $disk->exists($spoolPath)) {
            try {
                $spoolBytes = (int) $disk->size($spoolPath);
            } catch (\Throwable) {
                $spoolBytes = 0;
            }
        }

        $job->update([
            'spool_path' => $hasDataRows ? $spoolPath : null,
            'spool_inputs_path' => null,
            'spool_bytes' => $spoolBytes,
        ]);
    }

    private function tokenizeLinesLazy($lines): \Generator
    {
        if (is_string($lines)) {
            $tok = strtok($lines, "\n");
            while ($tok !== false) {
                $line = trim($tok);
                if ($line !== '') {
                    yield $line;
                }
                $tok = strtok("\n");
            }
            return;
        }
        if (is_array($lines)) {
            foreach ($lines as $t) {
                $line = trim((string) $t);
                if ($line !== '') {
                    yield $line;
                }
            }
            return;
        }
        if ($lines instanceof \Traversable) {
            foreach ($lines as $t) {
                $line = trim((string) $t);
                if ($line !== '') {
                    yield $line;
                }
            }
        }
    }

    private function createInitialSpool(int $jobId, iterable $lines): array
    {
        $diskName = (string) config('v8.storage.reports_disk', 'local');
        $disk = Storage::disk($diskName);

        $dirSpool = (string) (config('v8.storage.dir_spool') ?? 'v8-spool');
        $finalPref = $this->finalPrefix();

        if (!$disk->exists($dirSpool)) {
            $disk->makeDirectory($dirSpool);
        }

        $spoolPath = "{$dirSpool}/{$finalPref}_{$jobId}.spool.csv";
        $inputsPath = "{$dirSpool}/{$finalPref}_{$jobId}.inputs.txt";

        $fp = fopen($disk->path($spoolPath), 'c+');
        if ($fp === false) {
            throw new \RuntimeException("Não foi possível criar spool em {$spoolPath}");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fputcsv($fp, V8Schema::TITLES, ';');
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        $fp2 = fopen($disk->path($inputsPath), 'c+');
        if ($fp2 === false) {
            throw new \RuntimeException("Não foi possível criar inputs em {$inputsPath}");
        }

        $count = 0;
        try {
            if (flock($fp2, LOCK_EX)) {
                ftruncate($fp2, 0);
                foreach ($lines as $line) {
                    $line = trim((string) $line);
                    if ($line === '') {
                        continue;
                    }
                    fwrite($fp2, $line . "\n");
                    $count++;
                }
                fflush($fp2);
                flock($fp2, LOCK_UN);
            }
        } finally {
            fclose($fp2);
        }

        $this->fixSpoolPermissions($disk->path($spoolPath), $disk->path($inputsPath));

        $bytes = 0;
        try {
            $bytes = (int) $disk->size($spoolPath);
        } catch (\Throwable) {
        }

        return [$spoolPath, $inputsPath, $bytes, $count];
    }

    private function safeCleanupInit(int $jobId): void
    {
        try {
            $disk = Storage::disk((string) config('v8.storage.reports_disk', 'local'));
            $dirSpool = (string) (config('v8.storage.dir_spool') ?? 'v8-spool');
            $finalPref = $this->finalPrefix();
            foreach ([
                "{$dirSpool}/{$finalPref}_{$jobId}.spool.csv",
                "{$dirSpool}/{$finalPref}_{$jobId}.inputs.txt",
            ] as $p) {
                if ($disk->exists($p)) {
                    $disk->delete($p);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[V8] Falha ao limpar após erro no createInitialSpool (job {$jobId}): " . $e->getMessage());
        }
    }

    private function safeCleanupPaths(array $relPaths): void
    {
        try {
            $disk = Storage::disk((string) config('v8.storage.reports_disk', 'local'));
            foreach ($relPaths as $p) {
                if ($p && $disk->exists($p)) {
                    $disk->delete($p);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[V8] Erro limpando arquivos: " . $e->getMessage());
        }
    }

    private function fixSpoolPermissions(string ...$paths): void
    {
        $uid = (int) env('WWWUSER', 1000);
        $gid = (int) env('WWWGROUP', 1000);
        foreach ($paths as $path) {
            if ($path === '' || !file_exists($path)) {
                continue;
            }
            @chown($path, $uid);
            @chgrp($path, $gid);
            @chmod($path, 0664);
        }
    }
}
