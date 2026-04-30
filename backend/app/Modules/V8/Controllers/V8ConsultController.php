<?php

namespace App\Modules\V8\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\V8\Jobs\DispatchV8ConsultJob;
use App\Modules\V8\Models\V8ConsultJob;
use App\Modules\V8\Support\V8Schema;
use App\Modules\V8\Support\V8Spool;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class V8ConsultController extends Controller
{
    public function index()
    {
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

        $reportsDiskName = (string) config('v8.storage.reports_disk', 'local');
        $reportsDisk = Storage::disk($reportsDiskName);
        $spoolExists = $job->spool_path && $reportsDisk->exists($job->spool_path);
        $spoolHasDataRows = $spoolExists && V8Spool::hasDataRows($reportsDisk, $job->spool_path);

        return response()->json([
            'id' => $job->id,
            'title' => $job->title,
            'status' => $job->status,
            'phase' => $job->phase,
            'reuse_recent_consults' => (bool) $job->reuse_recent_consults,
            'reuse_recent_consults_days' => (int) ($job->reuse_recent_consults_days ?? 30),
            'total_cpfs' => $job->total_cpfs,
            'success_count' => $job->success_count,
            'nao_elegivel_count' => $job->nao_elegivel_count,
            'fail_count' => $job->fail_count,
            'has_file' => (bool) $job->has_file,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'canceled_at' => $job->canceled_at,
            'paused_at' => $job->paused_at,
            'cancel_reason' => $job->cancel_reason,
            'scheduled_for' => $job->scheduled_for,
            'created_at' => $job->created_at,
            'preview_running' => in_array($job->status, ['pendente', 'em_progresso', 'pausado', 'cancelado'], true) && $spoolHasDataRows,
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

        $job = V8ConsultJob::create([
            'user_id' => $request->user()->id,
            'title' => (string) $request->input('title'),
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
