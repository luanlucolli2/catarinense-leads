<?php

namespace App\Modules\V8Fgts\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\V8Fgts\Jobs\ProcessV8FgtsConsultJob;
use App\Modules\V8Fgts\Models\V8FgtsConsultJob;
use App\Modules\V8Fgts\Services\V8FgtsExternalApiService;
use App\Modules\V8Fgts\Support\V8FgtsSchema;
use App\Modules\V8Fgts\Support\V8FgtsSpool;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class V8FgtsConsultController extends Controller
{
    public function index()
    {
        $this->refreshActiveExternalJobs();

        $jobs = V8FgtsConsultJob::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($jobs);
    }

    public function show(int $id)
    {
        $job = V8FgtsConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            $this->syncExternalJob($job);
        }

        $disk = Storage::disk((string) config('v8_fgts.storage.reports_disk', 'local'));
        $spoolExists = $job->executor === 'local' && $job->spool_path && $disk->exists($job->spool_path);
        $spoolHasDataRows = $spoolExists && V8FgtsSpool::hasDataRows($disk, $job->spool_path);

        return response()->json([
            'id' => $job->id,
            'title' => $job->title,
            'status' => $job->status,
            'phase' => $job->phase,
            'total_cpfs' => $job->total_cpfs,
            'success_count' => $job->success_count,
            'nao_elegivel_count' => $job->nao_elegivel_count,
            'fail_count' => $job->fail_count,
            'has_file' => (bool) $job->has_file,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'canceled_at' => $job->canceled_at,
            'cancel_reason' => $job->cancel_reason,
            'created_at' => $job->created_at,
            'preview_running' => $job->executor === 'api'
                ? in_array($job->status, ['pendente', 'em_progresso', 'cancelado'], true)
                : in_array($job->status, ['pendente', 'em_progresso', 'cancelado'], true) && $spoolHasDataRows,
            'spool_bytes' => $job->spool_bytes,
        ]);
    }

    public function store(Request $request)
    {
        $rawCpfs = $request->input('cpfs', $request->input('lines', $request->input('entries', $request->input('rows'))));

        $validator = Validator::make([
            'title' => $request->input('title'),
            'cpfs' => $rawCpfs,
            'executor' => $request->input('executor', 'local'),
        ], [
            'title' => ['required', 'string', 'max:191'],
            'cpfs' => ['required'],
            'executor' => ['required', 'in:local,api'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Os dados fornecidos são inválidos.',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $executor = (string) $request->input('executor', 'local');
        $lock = Cache::lock('v8_fgts_consult_creation', 60);
        if (!$lock->get()) {
            return response()->json(['message' => 'Já existe uma criação de consulta V8 FGTS em andamento.'], Response::HTTP_CONFLICT);
        }

        try {
            if ($this->hasActiveJob()) {
                return response()->json(['message' => 'Já existe uma consulta V8 FGTS em andamento.'], Response::HTTP_CONFLICT);
            }

            if ($executor === 'api') {
                return $this->storeExternalJob($request, $rawCpfs);
            }

            return $this->storeLocalJob($request, $rawCpfs);
        } finally {
            $lock->release();
        }
    }

    private function storeLocalJob(Request $request, mixed $rawCpfs)
    {
        $job = $this->newJob($request, 'local');

        try {
            [$spoolPath, $cpfsPath, $spoolBytes, $cpfsCount] = $this->createInitialSpool(
                $job->id,
                $rawCpfs
            );
        } catch (\Throwable $e) {
            $this->safeCleanupInit($job->id);
            $job->delete();
            Log::error("[V8-FGTS] Erro ao preparar spool (job {$job->id}): " . $e->getMessage(), ['exception' => $e]);

            return response()->json(['message' => 'Falha interna ao preparar arquivos do job.'], 500);
        }

        if ($cpfsCount === 0) {
            $this->safeCleanupPaths([$spoolPath, $cpfsPath]);
            $job->delete();

            return response()->json(['message' => 'Nenhum CPF normalizável encontrado.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job->update([
            'spool_path' => $spoolPath,
            'spool_cpfs_path' => $cpfsPath,
            'spool_bytes' => $spoolBytes,
        ]);

        ProcessV8FgtsConsultJob::dispatch($job->id)
            ->onQueue((string) config('v8_fgts.job.queue', 'v8-fgts'));

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
        ], Response::HTTP_ACCEPTED);
    }

    private function storeExternalJob(Request $request, mixed $rawCpfs)
    {
        $job = $this->newJob($request, 'api');

        try {
            $remote = app(V8FgtsExternalApiService::class)->createJob(
                $job->title,
                $this->externalInput($rawCpfs)
            );
            $externalJobId = $remote['id'] ?? null;
            if (!is_string($externalJobId) || $externalJobId === '') {
                throw new \RuntimeException('A API externa não retornou o identificador do job.');
            }

            $job->update(['external_job_id' => $externalJobId]);
            $this->syncExternalJob($job, $remote);
        } catch (\Throwable $e) {
            $job->delete();
            Log::warning('[V8-FGTS] Falha ao criar job na API externa: ' . $e->getMessage());

            return response()->json(['message' => 'Não foi possível criar a consulta na API externa.'], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
        ], Response::HTTP_ACCEPTED);
    }

    public function requestPreview(Request $request, int $id)
    {
        $job = V8FgtsConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            $this->syncExternalJob($job);

            return response()->json([
                'queued' => false,
                'preview_running' => in_array($job->status, ['pendente', 'em_progresso', 'cancelado'], true),
                'message' => 'Prévia disponível diretamente na API externa.',
            ]);
        }

        $disk = Storage::disk((string) config('v8_fgts.storage.reports_disk', 'local'));
        $spoolExists = $job->spool_path && $disk->exists($job->spool_path);
        $spoolHasDataRows = $spoolExists && V8FgtsSpool::hasDataRows($disk, $job->spool_path);

        return response()->json([
            'queued' => false,
            'preview_running' => in_array($job->status, ['pendente', 'em_progresso', 'cancelado'], true) && $spoolHasDataRows,
            'message' => 'Prévia espelha o spool no momento da leitura.',
        ], Response::HTTP_OK);
    }

    public function downloadPreview(Request $request, int $id)
    {
        $job = V8FgtsConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            return $this->externalDownload($job, 'preview');
        }

        $disk = Storage::disk((string) config('v8_fgts.storage.reports_disk', 'local'));
        if (!empty($job->spool_path)) {
            $this->fixDiskPathPermissions($disk, dirname($job->spool_path), true);
            $this->fixDiskPathPermissions($disk, $job->spool_path);
        }

        if (empty($job->spool_path) || !$disk->exists($job->spool_path)) {
            return response()->json(['message' => 'Spool indisponível.'], Response::HTTP_CONFLICT);
        }

        $fh = @fopen($disk->path($job->spool_path), 'rb');
        if ($fh === false) {
            return response()->json(['message' => 'Falha ao abrir arquivo.'], 500);
        }

        $filename = "{$this->finalPrefix()}_{$job->id}_preview.csv";
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Accel-Buffering' => 'no',
        ];

        $withBOM = (bool) config('v8_fgts.csv.embed_bom', true);
        $finalEol = strtoupper((string) config('v8_fgts.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";

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

                $headerLine = fgets($fh);
                if ($headerLine === false) {
                    $headerLine = V8FgtsSchema::headerCsvLine(';');
                }
                echo rtrim((string) $headerLine, "\r\n") . $finalEol;
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
        $job = V8FgtsConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->executor === 'api') {
            return $this->externalDownload($job, 'report');
        }

        if (!in_array($job->status, ['concluido', 'falhou', 'cancelado'], true) || empty($job->file_disk) || empty($job->file_path)) {
            return response()->json(['message' => 'Relatório ainda não disponível.'], Response::HTTP_CONFLICT);
        }

        $disk = Storage::disk($job->file_disk);
        $this->fixDiskPathPermissions($disk, dirname($job->file_path), true);
        $this->fixDiskPathPermissions($disk, $job->file_path);
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
        $job = V8FgtsConsultJob::query()
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
                $remote = app(V8FgtsExternalApiService::class)->cancelJob((string) $job->external_job_id);
                $this->syncExternalJob($job, $remote);
                $job->update(['cancel_reason' => $data['reason'] ?? null]);
            } catch (\Throwable $e) {
                Log::warning("[V8-FGTS] Falha ao cancelar job externo {$job->id}: " . $e->getMessage());

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
        $job = V8FgtsConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $cancelStopPending = $job->status === 'cancelado' && empty($job->finished_at);
        if (in_array($job->status, ['pendente', 'em_progresso'], true) || $cancelStopPending) {
            return response()->json([
                'message' => 'Não é possível excluir enquanto o job ainda está em andamento ou finalizando cancelamento.',
                'status' => $job->status,
            ], Response::HTTP_CONFLICT);
        }

        if ($job->executor === 'api') {
            try {
                $response = app(V8FgtsExternalApiService::class)->deleteJob((string) $job->external_job_id);
            } catch (\Throwable $e) {
                Log::warning("[V8-FGTS] Falha ao excluir job externo {$job->id}: " . $e->getMessage());

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
            Log::warning("[V8-FGTS] Erro ao apagar arquivo final (job {$job->id}): " . $e->getMessage());
        }

        try {
            $disk = Storage::disk((string) config('v8_fgts.storage.reports_disk', 'local'));
            $dir = $this->jobSpoolDir($job->id);
            if ($disk->exists($dir)) {
                $disk->deleteDirectory($dir);
            }
        } catch (\Throwable $e) {
            Log::warning("[V8-FGTS] Erro ao apagar spool (job {$job->id}): " . $e->getMessage());
        }

        $job->delete();

        return response()->noContent();
    }

    private function newJob(Request $request, string $executor): V8FgtsConsultJob
    {
        return V8FgtsConsultJob::create([
            'user_id' => $request->user()->id,
            'title' => (string) $request->input('title'),
            'executor' => $executor,
            'status' => 'pendente',
            'phase' => null,
            'total_cpfs' => 0,
            'success_count' => 0,
            'nao_elegivel_count' => 0,
            'fail_count' => 0,
        ]);
    }

    private function hasActiveJob(): bool
    {
        $this->refreshActiveExternalJobs();

        return V8FgtsConsultJob::query()
            ->whereIn('status', ['pendente', 'em_progresso'])
            ->exists();
    }

    private function refreshActiveExternalJobs(): void
    {
        $externalJobs = V8FgtsConsultJob::query()
            ->where('executor', 'api')
            ->whereIn('status', ['pendente', 'em_progresso'])
            ->get();

        foreach ($externalJobs as $job) {
            try {
                $this->syncExternalJob($job);
            } catch (\Throwable $e) {
                Log::warning("[V8-FGTS] Não foi possível atualizar job externo ativo {$job->id}: " . $e->getMessage());
            }
        }
    }

    private function externalInput(mixed $rawCpfs): string
    {
        if (is_string($rawCpfs)) {
            return $rawCpfs;
        }

        if (is_array($rawCpfs) || $rawCpfs instanceof \Traversable) {
            return implode("\n", array_filter(array_map(
                static fn ($value) => trim((string) $value),
                is_array($rawCpfs) ? $rawCpfs : iterator_to_array($rawCpfs)
            ), static fn ($value) => $value !== ''));
        }

        return '';
    }

    private function syncExternalJob(V8FgtsConsultJob $job, ?array $remote = null): void
    {
        if (empty($job->external_job_id)) {
            throw new \RuntimeException('Job externo sem identificador.');
        }

        $remote ??= app(V8FgtsExternalApiService::class)->getJob((string) $job->external_job_id);
        $remoteStatus = (string) ($remote['status'] ?? 'queued');
        $status = match ($remoteStatus) {
            'completed' => 'concluido',
            'failed', 'expired' => 'falhou',
            'cancelled' => 'cancelado',
            'running', 'pausing', 'paused' => 'em_progresso',
            default => 'pendente',
        };
        $metrics = is_array($remote['metrics'] ?? null) ? $remote['metrics'] : [];
        $pipeline = is_array($metrics['pipeline'] ?? null) ? $metrics['pipeline'] : [];
        $total = $remote['total_count'] ?? data_get($remote, 'progress.phase_1.total') ?? 0;
        $terminal = in_array($status, ['concluido', 'falhou', 'cancelado'], true);

        $job->update([
            'status' => $status,
            'phase' => is_string($remote['phase'] ?? null) ? $remote['phase'] : null,
            'total_cpfs' => max(0, (int) $total),
            'success_count' => max(0, (int) ($pipeline['success'] ?? $metrics['pipeline.success'] ?? 0)),
            'nao_elegivel_count' => max(0, (int) ($pipeline['not_eligible'] ?? $metrics['pipeline.not_eligible'] ?? 0)),
            'fail_count' => max(0, (int) ($pipeline['errors'] ?? $metrics['pipeline.errors'] ?? 0)),
            'external_has_report' => (bool) ($remote['has_report'] ?? false),
            'started_at' => $remote['started_at'] ?? $job->started_at ?? ($status === 'em_progresso' ? now() : null),
            'finished_at' => $remote['finished_at'] ?? ($terminal ? ($job->finished_at ?? now()) : null),
            'canceled_at' => $remote['canceled_at'] ?? ($status === 'cancelado' ? ($job->canceled_at ?? now()) : null),
        ]);
    }

    private function externalDownload(V8FgtsConsultJob $job, string $kind)
    {
        try {
            $response = $kind === 'preview'
                ? app(V8FgtsExternalApiService::class)->preview((string) $job->external_job_id)
                : app(V8FgtsExternalApiService::class)->report((string) $job->external_job_id);
        } catch (\Throwable $e) {
            Log::warning("[V8-FGTS] Falha ao baixar {$kind} externo {$job->id}: " . $e->getMessage());

            return response()->json(['message' => 'Não foi possível baixar o arquivo da API externa.'], Response::HTTP_BAD_GATEWAY);
        }

        if (!$response->successful()) {
            $status = in_array($response->status(), [404, 409], true) ? $response->status() : Response::HTTP_BAD_GATEWAY;

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
        return (string) config('v8_fgts.storage.final_prefix', 'v8-fgts-consulta');
    }

    private function finalizeCancelledPreservingUsefulPreview(V8FgtsConsultJob $job): void
    {
        $disk = Storage::disk((string) config('v8_fgts.storage.reports_disk', 'local'));
        $spoolPath = $job->spool_path ?? null;
        $hasDataRows = V8FgtsSpool::hasDataRows($disk, $spoolPath);

        try {
            $cpfsPath = $job->spool_cpfs_path ?? null;
            if ($cpfsPath && $disk->exists($cpfsPath)) {
                $disk->delete($cpfsPath);
            }

            $this->cleanupSpoolArtifacts($disk, $job->id, $hasDataRows ? $spoolPath : null);

            if (!$hasDataRows && $spoolPath && $disk->exists($spoolPath)) {
                $disk->delete($spoolPath);
            }
        } catch (\Throwable $e) {
            Log::warning("[V8-FGTS] Erro ao finalizar cancelamento (job {$job->id}): " . $e->getMessage());
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
            'spool_cpfs_path' => null,
            'spool_bytes' => $spoolBytes,
        ]);
    }

    private function createInitialSpool(int $jobId, mixed $rawCpfs): array
    {
        $disk = Storage::disk((string) config('v8_fgts.storage.reports_disk', 'local'));
        $jobDir = $this->jobSpoolDir($jobId);
        $finalPrefix = $this->finalPrefix();

        if (!$disk->exists($jobDir)) {
            $disk->makeDirectory($jobDir);
        }
        $this->fixDiskPathPermissions($disk, $jobDir, true);

        $spoolPath = "{$jobDir}/{$finalPrefix}_{$jobId}.spool.csv";
        $cpfsPath = "{$jobDir}/{$finalPrefix}_{$jobId}.input.txt";

        $fp = fopen($disk->path($spoolPath), 'c+');
        if ($fp === false) {
            throw new \RuntimeException("Não foi possível criar spool em {$spoolPath}");
        }

        try {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fputcsv($fp, V8FgtsSchema::TITLES, ';');
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        $fp2 = fopen($disk->path($cpfsPath), 'c+');
        if ($fp2 === false) {
            throw new \RuntimeException("Não foi possível criar inputs em {$cpfsPath}");
        }

        $count = 0;
        try {
            if (flock($fp2, LOCK_EX)) {
                ftruncate($fp2, 0);
                if (is_string($rawCpfs)) {
                    $payload = trim($rawCpfs);
                    if ($payload !== '') {
                        fwrite($fp2, $rawCpfs);
                        $count = 1;
                    }
                } elseif (is_array($rawCpfs) || $rawCpfs instanceof \Traversable) {
                    foreach ($rawCpfs as $raw) {
                        $value = trim((string) $raw);
                        if ($value === '') {
                            continue;
                        }

                        fwrite($fp2, $value . "\n");
                        $count++;
                    }
                }
                fflush($fp2);
                flock($fp2, LOCK_UN);
            }
        } finally {
            fclose($fp2);
        }

        $bytes = 0;
        try {
            $bytes = (int) $disk->size($spoolPath);
        } catch (\Throwable) {
        }

        $this->fixDiskPathPermissions($disk, $spoolPath);
        $this->fixDiskPathPermissions($disk, $cpfsPath);

        return [$spoolPath, $cpfsPath, $bytes, $count];
    }

    private function fixDiskPathPermissions(FilesystemAdapter $disk, ?string $relativePath, bool $directory = false): void
    {
        if (!is_string($relativePath) || $relativePath === '') {
            return;
        }

        try {
            $absolutePath = $disk->path($relativePath);
        } catch (\Throwable) {
            return;
        }

        if ($absolutePath === '' || !file_exists($absolutePath)) {
            return;
        }

        $uid = (int) env('WWWUSER', 1000);
        $gid = (int) env('WWWGROUP', 1000);

        @chown($absolutePath, $uid);
        @chgrp($absolutePath, $gid);
        @chmod($absolutePath, $directory ? 0775 : 0664);
    }

    private function safeCleanupInit(int $jobId): void
    {
        try {
            $disk = Storage::disk((string) config('v8_fgts.storage.reports_disk', 'local'));
            $dir = $this->jobSpoolDir($jobId);
            if ($disk->exists($dir)) {
                $disk->deleteDirectory($dir);
            }
        } catch (\Throwable $e) {
            Log::warning("[V8-FGTS] Falha ao limpar após erro no createInitialSpool (job {$jobId}): " . $e->getMessage());
        }
    }

    private function safeCleanupPaths(array $relPaths): void
    {
        try {
            $disk = Storage::disk((string) config('v8_fgts.storage.reports_disk', 'local'));
            foreach ($relPaths as $path) {
                if ($path && $disk->exists($path)) {
                    $disk->delete($path);
                }

                $dir = is_string($path) ? dirname($path) : null;
                if ($dir && $dir !== '.' && $disk->exists($dir)) {
                    $disk->deleteDirectory($dir);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[V8-FGTS] Erro limpando arquivos: " . $e->getMessage());
        }
    }

    private function cleanupSpoolArtifacts($disk, int $jobId, ?string $preserveRel = null): void
    {
        try {
            $jobDir = $this->jobSpoolDir($jobId);
            if (!$disk->exists($jobDir)) {
                return;
            }

            foreach ($disk->files($jobDir) as $rel) {
                if ($preserveRel !== null && $rel === $preserveRel) {
                    continue;
                }
                try {
                    $disk->delete($rel);
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }
    }

    private function jobSpoolDir(int $jobId): string
    {
        $dirSpool = (string) (config('v8_fgts.storage.dir_spool') ?? 'v8-fgts-spool');

        return "{$dirSpool}/{$jobId}";
    }
}
