<?php

namespace App\Modules\HubCredito\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HubCredito\Models\HubCreditoConsultJob;
use App\Modules\HubCredito\Jobs\ProcessHubCreditoConsultJob;
use App\Modules\HubCredito\Jobs\StoreHubCreditoExternalReportJob;
use App\Modules\HubCredito\Support\HubCreditoFiles;
use App\Modules\HubCredito\Support\HubCreditoPreviewSnapshot;
use App\Modules\HubCredito\Support\HubCreditoSchema;
use App\Modules\HubCredito\Support\HubCreditoSpool;
use App\Modules\HubCredito\Services\HubCreditoExternalApiService;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class HubCreditoConsultController extends Controller
{
    public function index(Request $request)
    {
        $data = Validator::make($request->query(), [
            'status' => ['nullable', 'in:agendado,pendente,em_progresso,pausado,concluido,falhou,cancelado,todos'],
        ])->validate();

        $this->refreshActiveExternalJobs();
        $jobsQuery = HubCreditoConsultJob::query()
            ->where('user_id', Auth::id());

        $status = $data['status'] ?? null;
        if (is_string($status) && $status !== '' && $status !== 'todos') {
            $jobsQuery->where('status', $status);
        }

        $jobs = $jobsQuery
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($jobs);
    }

    public function show(int $id)
    {
        $job = HubCreditoConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') $this->syncExternalJob($job);

        $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
        $spoolExists = $job->executor === 'local' && $job->spool_path && $disk->exists($job->spool_path);
        $spoolHasDataRows = $spoolExists && HubCreditoSpool::hasDataRows($disk, $job->spool_path);

        return response()->json([
            'id' => $job->id,
            'title' => $job->title,
            'executor' => $job->executor,
            'status' => $job->status,
            'phase' => $job->phase,
            'total_cpfs' => $job->total_cpfs,
            'aprovado_count' => $job->aprovado_count,
            'nao_aprovado_count' => $job->nao_aprovado_count,
            'fail_count' => $job->fail_count,
            'phase1_submitted_count' => $job->phase1_submitted_count,
            'phase1_not_approved_count' => $job->phase1_not_approved_count,
            'phase1_fail_count' => $job->phase1_fail_count,
            'phase2_approved_count' => $job->phase2_approved_count,
            'phase2_not_approved_count' => $job->phase2_not_approved_count,
            'phase2_fail_count' => $job->phase2_fail_count,
            'has_file' => $job->executor === 'api' ? (bool) $job->external_has_report : (bool) $job->has_file,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'canceled_at' => $job->canceled_at,
            'paused_at' => $job->paused_at,
            'scheduled_for' => $job->scheduled_for,
            'cancel_reason' => $job->cancel_reason,
            'created_at' => $job->created_at,
            'preview_running' => $job->executor === 'api' ? in_array($job->status, ['pendente', 'em_progresso', 'pausado'], true) : in_array($job->status, ['pendente', 'em_progresso', 'cancelado'], true) && $spoolHasDataRows,
            'spool_bytes' => $job->spool_bytes,
            'spool_path' => $job->spool_path,
            'spool_inputs_path' => $job->spool_inputs_path,
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
        ], [
            'title' => ['required', 'string', 'max:191'],
            'lines' => ['required'],
            'run_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Os dados fornecidos são inválidos.',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $timezone = (string) ($request->input('timezone') ?: 'America/Sao_Paulo');
        $runAt = $request->filled('run_at') ? Carbon::parse((string) $request->input('run_at'), $timezone) : null;
        $scheduledFor = $runAt && $runAt->greaterThan(Carbon::now($timezone)) ? $runAt->clone()->setTimezone('UTC') : null;
        $lines = $this->externalInput($rawLines);
        if (count(preg_split('/\r\n|\r|\n/', $lines, -1, PREG_SPLIT_NO_EMPTY)) > 40000) {
            return response()->json(['message' => 'O limite é de 40.000 linhas por consulta.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lock = Cache::lock('hubcredito_consult_creation', 60);
        if (!$lock->get()) return response()->json(['message' => 'Já existe uma criação de consulta Hub Crédito em andamento.'], Response::HTTP_CONFLICT);
        try {
            if ($this->hasActiveJob()) return response()->json(['message' => 'Já existe uma consulta Hub Crédito em andamento.'], Response::HTTP_CONFLICT);
            $job = null;
            try {
                $remote = app(HubCreditoExternalApiService::class)->createJob((string) $request->input('title'), $lines, $scheduledFor?->toIso8601String());
                $externalId = $remote['id'] ?? null;
                if (!is_string($externalId) || $externalId === '') throw new \RuntimeException('A API externa não retornou o identificador do job.');
                $job = HubCreditoConsultJob::create([
                    'user_id' => $request->user()->id, 'title' => (string) $request->input('title'), 'executor' => 'api',
                    'external_job_id' => $externalId, 'status' => $scheduledFor ? 'agendado' : 'pendente', 'scheduled_for' => $scheduledFor,
                ]);
                $this->syncExternalJob($job, $remote);
                return response()->json(['id' => $job->id, 'status' => $job->status, 'phase' => $job->phase, 'scheduled_for' => $job->scheduled_for], Response::HTTP_ACCEPTED);
            } catch (\Throwable $e) {
                if ($job instanceof HubCreditoConsultJob) $job->delete();
                Log::warning('[HUBCREDITO] Falha ao criar job na API externa: ' . $e->getMessage());
                return response()->json(['message' => 'Não foi possível criar a consulta na API externa.'], Response::HTTP_BAD_GATEWAY);
            }
        } finally {
            $lock->release();
        }

        $job = HubCreditoConsultJob::create([
            'user_id' => $request->user()->id,
            'title' => (string) $request->input('title'),
            'status' => 'pendente',
            'phase' => null,
            'total_cpfs' => 0,
            'aprovado_count' => 0,
            'nao_aprovado_count' => 0,
            'fail_count' => 0,
        ]);

        try {
            [$spoolPath, $inputsPath, $spoolBytes, $linesCount] = $this->createInitialSpool($job->id, $this->tokenizeLinesLazy($rawLines));
        } catch (\Throwable $e) {
            $this->safeCleanupInit($job->id);
            $job->delete();
            Log::error("[HUBCREDITO] Erro ao preparar spool (job {$job->id}): {$e->getMessage()}", ['exception' => $e]);

            return response()->json(['message' => 'Falha interna ao preparar arquivos do job.'], 500);
        }

        if ($linesCount === 0) {
            $this->safeCleanupPaths([$spoolPath, $inputsPath]);
            $job->delete();

            return response()->json(['message' => 'Nenhuma linha válida encontrada.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job->update([
            'spool_path' => $spoolPath,
            'spool_inputs_path' => $inputsPath,
            'spool_bytes' => $spoolBytes,
        ]);

        ProcessHubCreditoConsultJob::dispatch($job->id)
            ->onQueue((string) config('hubcredito.job.queue', 'hubcredito-clt'));

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
        ], Response::HTTP_ACCEPTED);
    }

    public function requestPreview(Request $request, int $id)
    {
        $job = HubCreditoConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            $this->syncExternalJob($job);
            return response()->json(['queued' => false, 'preview_running' => in_array($job->status, ['pendente', 'em_progresso', 'pausado'], true), 'message' => 'Prévia disponível diretamente na API externa.']);
        }

        $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
        $spoolExists = $job->spool_path && $disk->exists($job->spool_path);
        $spoolHasDataRows = $spoolExists && HubCreditoSpool::hasDataRows($disk, $job->spool_path);

        return response()->json([
            'queued' => false,
            'preview_running' => in_array($job->status, ['pendente', 'em_progresso', 'cancelado'], true) && $spoolHasDataRows,
            'message' => 'Prévia espelha o spool no momento da leitura.',
        ], Response::HTTP_OK);
    }

    public function downloadPreview(Request $request, int $id)
    {
        $job = HubCreditoConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') return $this->externalDownload($job, 'preview');

        $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
        if (empty($job->spool_path) || !$disk->exists($job->spool_path)) {
            return response()->json(['message' => 'Spool indisponível.'], Response::HTTP_CONFLICT);
        }

        $snapshot = HubCreditoPreviewSnapshot::create($disk, $job->spool_path);
        if (!is_resource($snapshot)) {
            return response()->json(['message' => 'Falha ao abrir snapshot do arquivo.'], 500);
        }

        $filename = "{$this->finalPrefix()}_{$job->id}_preview.csv";
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Accel-Buffering' => 'no',
        ];

        $withBom = (bool) config('hubcredito.csv.embed_bom', true);
        $finalEol = strtoupper((string) config('hubcredito.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";

        return response()->streamDownload(function () use ($snapshot, $withBom, $finalEol) {
            try {
                if ($withBom) {
                    echo "\xEF\xBB\xBF";
                }

                $peek = fread($snapshot, 3);
                if ($peek !== "\xEF\xBB\xBF") {
                    rewind($snapshot);
                }

                $headerLine = fgets($snapshot);
                if ($headerLine === false) {
                    $headerLine = HubCreditoSchema::headerCsvLine(';');
                }

                echo rtrim((string) $headerLine, "\r\n") . $finalEol;
                fpassthru($snapshot);
            } finally {
                if (is_resource($snapshot)) {
                    fclose($snapshot);
                }
            }
        }, $filename, $headers);
    }

    public function download(int $id)
    {
        $job = HubCreditoConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            if ($this->hasStoredReport($job)) return $this->downloadStoredReport($job);
            return $this->externalDownload($job, 'report');
        }

        if (!in_array($job->status, ['concluido', 'falhou', 'cancelado'], true) || empty($job->file_disk) || empty($job->file_path)) {
            return response()->json(['message' => 'Relatório ainda não disponível.'], Response::HTTP_CONFLICT);
        }

        $disk = Storage::disk($job->file_disk);
        if (!$disk->exists($job->file_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_NOT_FOUND);
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
        }, $job->file_name ?: "{$this->finalPrefix()}-{$job->id}.csv", $headers);
    }

    public function cancel(Request $request, int $id)
    {
        $job = HubCreditoConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            if (in_array($job->status, ['concluido', 'falhou', 'cancelado'], true)) return response()->json(['message' => 'Job não pode ser cancelado neste estado.', 'status' => $job->status], Response::HTTP_CONFLICT);
            $data = $request->validate(['reason' => ['nullable', 'string', 'max:191']]);
            try {
                $this->syncExternalJob($job, app(HubCreditoExternalApiService::class)->cancelJob((string) $job->external_job_id));
                $job->update(['cancel_reason' => $data['reason'] ?? null]);
            } catch (\Throwable $e) { return response()->json(['message' => 'Não foi possível cancelar a consulta na API externa.'], Response::HTTP_BAD_GATEWAY); }
            $job->refresh();
            return response()->json(['id' => $job->id, 'status' => $job->status, 'phase' => $job->phase, 'canceled_at' => $job->canceled_at, 'cancel_reason' => $job->cancel_reason, 'finished_at' => $job->finished_at]);
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

    public function destroy(int $id)
    {
        $job = HubCreditoConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $cancelStopPending = $job->status === 'cancelado' && empty($job->finished_at);
        if (in_array($job->status, ['agendado', 'pendente', 'em_progresso', 'pausado'], true) || $cancelStopPending) {
            return response()->json([
                'message' => 'Não é possível excluir enquanto o job ainda está em andamento ou finalizando cancelamento.',
                'status' => $job->status,
            ], Response::HTTP_CONFLICT);
        }

        if ($job->executor === 'api') {
            try { $response = app(HubCreditoExternalApiService::class)->deleteJob((string) $job->external_job_id); }
            catch (\Throwable) { return response()->json(['message' => 'Não foi possível excluir a consulta na API externa.'], Response::HTTP_BAD_GATEWAY); }
            if (!$response->successful() && $response->status() !== Response::HTTP_NOT_FOUND) return response()->json(['message' => 'A API externa não permitiu excluir a consulta.'], $response->status() === Response::HTTP_CONFLICT ? Response::HTTP_CONFLICT : Response::HTTP_BAD_GATEWAY);
        }

        try {
            if ($job->file_disk && $job->file_path) {
                $disk = Storage::disk($job->file_disk);
                if ($disk->exists($job->file_path)) {
                    $disk->delete($job->file_path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[HUBCREDITO] Erro ao apagar arquivo final (job {$job->id}): {$e->getMessage()}");
        }

        try {
            $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
            $dirSpool = (string) config('hubcredito.storage.dir_spool', 'hubcredito-spool');
            HubCreditoFiles::deleteTransientFiles(
                $disk,
                $dirSpool,
                $this->finalPrefix(),
                $job->id,
                [$job->spool_path, $job->spool_inputs_path]
            );
        } catch (\Throwable $e) {
            Log::warning("[HUBCREDITO] Erro ao apagar spool (job {$job->id}): {$e->getMessage()}");
        }

        $job->delete();

        return response()->noContent();
    }

    public function pause(int $id)
    {
        $job = HubCreditoConsultJob::query()->where('user_id', Auth::id())->findOrFail($id);
        if ($job->executor !== 'api' || !in_array($job->status, ['pendente', 'em_progresso'], true)) return response()->json(['message' => 'Job não pode ser pausado neste estado.'], Response::HTTP_CONFLICT);
        try { $this->syncExternalJob($job, app(HubCreditoExternalApiService::class)->pauseJob((string) $job->external_job_id)); }
        catch (\Throwable) { return response()->json(['message' => 'Não foi possível pausar a consulta na API externa.'], Response::HTTP_BAD_GATEWAY); }
        $job->refresh();
        return response()->json(['id' => $job->id, 'status' => $job->status, 'phase' => $job->phase, 'paused_at' => $job->paused_at], Response::HTTP_ACCEPTED);
    }

    public function resume(int $id)
    {
        $job = HubCreditoConsultJob::query()->where('user_id', Auth::id())->findOrFail($id);
        if ($job->status !== 'pausado') return response()->json(['message' => 'Apenas jobs pausados podem ser retomados.'], Response::HTTP_CONFLICT);
        if ($this->hasActiveJob($job->id)) return response()->json(['message' => 'Já existe uma consulta Hub Crédito em andamento.'], Response::HTTP_CONFLICT);
        if ($job->executor !== 'api') return response()->json(['message' => 'Retomada indisponível para este job.'], Response::HTTP_CONFLICT);
        try { $this->syncExternalJob($job, app(HubCreditoExternalApiService::class)->resumeJob((string) $job->external_job_id)); }
        catch (\Throwable) { return response()->json(['message' => 'Não foi possível retomar a consulta na API externa.'], Response::HTTP_BAD_GATEWAY); }
        $job->refresh();
        return response()->json(['id' => $job->id, 'status' => $job->status, 'phase' => $job->phase], Response::HTTP_ACCEPTED);
    }

    private function finalPrefix(): string
    {
        return (string) config('hubcredito.storage.final_prefix', 'hubcredito-consulta');
    }

    private function finalizeCancelledPreservingUsefulPreview(HubCreditoConsultJob $job): void
    {
        $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
        $spoolPath = $job->spool_path ?? null;
        $hasDataRows = HubCreditoSpool::hasDataRows($disk, $spoolPath);

        try {
            $dirSpool = (string) config('hubcredito.storage.dir_spool', 'hubcredito-spool');
            $keepPaths = $hasDataRows && is_string($spoolPath) ? [$spoolPath] : [];
            $extraPaths = array_filter([$job->spool_path, $job->spool_inputs_path]);
            HubCreditoFiles::deleteTransientFiles(
                $disk,
                $dirSpool,
                $this->finalPrefix(),
                $job->id,
                $extraPaths,
                $keepPaths
            );

            if (!$hasDataRows && $spoolPath && $disk->exists($spoolPath)) {
                $disk->delete($spoolPath);
            }
        } catch (\Throwable $e) {
            Log::warning("[HUBCREDITO] Erro ao finalizar cancelamento (job {$job->id}): {$e->getMessage()}");
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
            foreach ($lines as $item) {
                $line = trim((string) $item);
                if ($line !== '') {
                    yield $line;
                }
            }
            return;
        }

        if ($lines instanceof \Traversable) {
            foreach ($lines as $item) {
                $line = trim((string) $item);
                if ($line !== '') {
                    yield $line;
                }
            }
        }
    }

    private function createInitialSpool(int $jobId, iterable $lines): array
    {
        $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
        $dirSpool = (string) config('hubcredito.storage.dir_spool', 'hubcredito-spool');
        $finalPrefix = $this->finalPrefix();

        if (!$disk->exists($dirSpool)) {
            $disk->makeDirectory($dirSpool);
        }

        $spoolPath = "{$dirSpool}/{$finalPrefix}_{$jobId}.spool.csv";
        $inputsPath = "{$dirSpool}/{$finalPrefix}_{$jobId}.inputs.txt";

        $fp = fopen($disk->path($spoolPath), 'c+');
        if ($fp === false) {
            throw new \RuntimeException("Não foi possível criar spool em {$spoolPath}");
        }

        try {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fputcsv($fp, HubCreditoSchema::TITLES, ';');
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
            $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
            $dirSpool = (string) config('hubcredito.storage.dir_spool', 'hubcredito-spool');
            $finalPrefix = $this->finalPrefix();
            foreach ([
                "{$dirSpool}/{$finalPrefix}_{$jobId}.spool.csv",
                "{$dirSpool}/{$finalPrefix}_{$jobId}.inputs.txt",
            ] as $path) {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[HUBCREDITO] Falha ao limpar após erro no createInitialSpool (job {$jobId}): {$e->getMessage()}");
        }
    }

    private function safeCleanupPaths(array $paths): void
    {
        try {
            $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
            foreach ($paths as $path) {
                if ($path && $disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[HUBCREDITO] Erro limpando arquivos: {$e->getMessage()}");
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

    private function hasActiveJob(?int $excludingJobId = null): bool
    {
        $this->refreshActiveExternalJobs();
        $query = HubCreditoConsultJob::query()->whereIn('status', ['agendado', 'pendente', 'em_progresso']);
        if ($excludingJobId !== null) $query->whereKeyNot($excludingJobId);
        return $query->exists();
    }

    private function refreshActiveExternalJobs(): void
    {
        HubCreditoConsultJob::query()->where('executor', 'api')->whereIn('status', ['agendado', 'pendente', 'em_progresso', 'pausado'])->get()->each(function (HubCreditoConsultJob $job): void {
            try { $this->syncExternalJob($job); } catch (\Throwable $e) { Log::warning("[HUBCREDITO] Não foi possível atualizar job externo ativo {$job->id}: {$e->getMessage()}"); }
        });
    }

    private function externalInput(mixed $lines): string
    {
        if (is_string($lines)) return $lines;
        if (is_array($lines) || $lines instanceof \Traversable) return implode("\n", array_filter(array_map(static fn ($line) => trim((string) $line), is_array($lines) ? $lines : iterator_to_array($lines))));
        return '';
    }

    private function syncExternalJob(HubCreditoConsultJob $job, ?array $remote = null): void
    {
        if (!$job->external_job_id) throw new \RuntimeException('Job externo sem identificador.');
        $remote ??= app(HubCreditoExternalApiService::class)->getJob((string) $job->external_job_id);
        $status = match ((string) ($remote['status'] ?? 'queued')) {
            'scheduled' => 'agendado', 'completed' => 'concluido', 'failed', 'expired' => 'falhou', 'cancelled' => 'cancelado', 'paused' => 'pausado', 'running', 'pausing' => 'em_progresso', default => 'pendente',
        };
        $metrics = (array) ($remote['metrics'] ?? []);
        $report = (bool) ($remote['has_report'] ?? false);
        $terminal = in_array($status, ['concluido', 'falhou', 'cancelado'], true);
        $phase = match ($remote['phase'] ?? null) { 'phase_1' => 'fase_1', 'phase_2' => 'fase_2', default => null };
        $p1Sent = max(0, (int) ($metrics['phase1.submitted'] ?? 0));
        $p1No = max(0, (int) ($metrics['phase1.not_approved'] ?? 0));
        $p1Fail = max(0, (int) ($metrics['phase1.errors'] ?? 0));
        $p2Ok = max(0, (int) ($metrics['phase2.approved'] ?? 0));
        $p2No = max(0, (int) ($metrics['phase2.not_approved'] ?? 0));
        $p2Fail = max(0, (int) ($metrics['phase2.errors'] ?? 0));
        $job->update([
            'status' => $status, 'phase' => $phase,
            'total_cpfs' => max(0, (int) ($remote['total_count'] ?? data_get($remote, 'progress.phase_1.total') ?? 0)),
            'aprovado_count' => $p2Ok, 'nao_aprovado_count' => $p2No, 'fail_count' => $p1Fail + $p2Fail,
            'phase1_submitted_count' => $p1Sent, 'phase1_not_approved_count' => $p1No, 'phase1_fail_count' => $p1Fail,
            'phase2_approved_count' => $p2Ok, 'phase2_not_approved_count' => $p2No, 'phase2_fail_count' => $p2Fail,
            'external_has_report' => $report, 'scheduled_for' => $remote['scheduled_for'] ?? $job->scheduled_for,
            'started_at' => $remote['started_at'] ?? $job->started_at ?? ($status === 'em_progresso' ? now() : null),
            'paused_at' => $remote['paused_at'] ?? ($status === 'pausado' ? ($job->paused_at ?? now()) : null),
            'finished_at' => $remote['finished_at'] ?? ($terminal && ($status !== 'cancelado' || $report) ? ($job->finished_at ?? now()) : null),
            'canceled_at' => $remote['cancelled_at'] ?? ($status === 'cancelado' ? ($job->canceled_at ?? now()) : null),
        ]);
        if ($report && !$this->hasStoredReport($job)) StoreHubCreditoExternalReportJob::dispatch($job->id);
    }

    private function hasStoredReport(HubCreditoConsultJob $job): bool
    {
        return $job->file_disk && $job->file_path && Storage::disk($job->file_disk)->exists($job->file_path);
    }

    private function downloadStoredReport(HubCreditoConsultJob $job)
    {
        $stream = Storage::disk((string) $job->file_disk)->readStream((string) $job->file_path);
        if ($stream === false) return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_NOT_FOUND);
        return response()->streamDownload(function () use ($stream) { try { fpassthru($stream); } finally { if (is_resource($stream)) fclose($stream); } }, $job->file_name ?: "{$this->finalPrefix()}-{$job->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function externalDownload(HubCreditoConsultJob $job, string $kind)
    {
        try { $response = $kind === 'preview' ? app(HubCreditoExternalApiService::class)->preview((string) $job->external_job_id) : app(HubCreditoExternalApiService::class)->report((string) $job->external_job_id); }
        catch (\Throwable) { return response()->json(['message' => 'Não foi possível baixar o arquivo da API externa.'], Response::HTTP_BAD_GATEWAY); }
        if (!$response->successful()) return response()->json(['message' => 'Arquivo indisponível na API externa.'], in_array($response->status(), [Response::HTTP_NOT_FOUND, Response::HTTP_CONFLICT], true) ? $response->status() : Response::HTTP_BAD_GATEWAY);
        return response()->streamDownload(fn () => print $response->body(), "{$this->finalPrefix()}-{$job->id}" . ($kind === 'preview' ? '-preview' : '') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
