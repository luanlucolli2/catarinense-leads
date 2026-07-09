<?php

namespace App\Modules\HubCredito\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HubCredito\Models\HubCreditoConsultJob;
use App\Modules\HubCredito\Jobs\ProcessHubCreditoConsultJob;
use App\Modules\HubCredito\Support\HubCreditoSchema;
use App\Modules\HubCredito\Support\HubCreditoSpool;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class HubCreditoConsultController extends Controller
{
    public function index()
    {
        $jobs = HubCreditoConsultJob::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($jobs);
    }

    public function show(int $id)
    {
        $job = HubCreditoConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
        $spoolExists = $job->spool_path && $disk->exists($job->spool_path);
        $spoolHasDataRows = $spoolExists && HubCreditoSpool::hasDataRows($disk, $job->spool_path);

        return response()->json([
            'id' => $job->id,
            'title' => $job->title,
            'status' => $job->status,
            'phase' => $job->phase,
            'total_cpfs' => $job->total_cpfs,
            'aprovado_count' => $job->aprovado_count,
            'nao_aprovado_count' => $job->nao_aprovado_count,
            'pendencia_count' => $job->pendencia_count,
            'has_file' => (bool) $job->has_file,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'canceled_at' => $job->canceled_at,
            'cancel_reason' => $job->cancel_reason,
            'created_at' => $job->created_at,
            'preview_running' => in_array($job->status, ['pendente', 'em_progresso', 'cancelado'], true) && $spoolHasDataRows,
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
        ], [
            'title' => ['required', 'string', 'max:191'],
            'lines' => ['required'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Os dados fornecidos são inválidos.',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job = HubCreditoConsultJob::create([
            'user_id' => $request->user()->id,
            'title' => (string) $request->input('title'),
            'status' => 'pendente',
            'phase' => null,
            'total_cpfs' => 0,
            'aprovado_count' => 0,
            'nao_aprovado_count' => 0,
            'pendencia_count' => 0,
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

        $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
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

        $withBom = (bool) config('hubcredito.csv.embed_bom', true);
        $finalEol = strtoupper((string) config('hubcredito.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";

        return response()->streamDownload(function () use ($fh, $withBom, $finalEol) {
            try {
                flock($fh, LOCK_SH);

                if ($withBom) {
                    echo "\xEF\xBB\xBF";
                }

                $peek = fread($fh, 3);
                if ($peek !== "\xEF\xBB\xBF") {
                    fseek($fh, 0);
                }

                $headerLine = fgets($fh);
                if ($headerLine === false) {
                    $headerLine = HubCreditoSchema::headerCsvLine(';');
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
        $job = HubCreditoConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

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
        if (in_array($job->status, ['pendente', 'em_progresso'], true) || $cancelStopPending) {
            return response()->json([
                'message' => 'Não é possível excluir enquanto o job ainda está em andamento ou finalizando cancelamento.',
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
            Log::warning("[HUBCREDITO] Erro ao apagar arquivo final (job {$job->id}): {$e->getMessage()}");
        }

        try {
            $disk = Storage::disk((string) config('hubcredito.storage.reports_disk', 'local'));
            foreach (['spool_path', 'spool_inputs_path'] as $field) {
                $path = $job->{$field};
                if ($path && $disk->exists($path)) {
                    $disk->delete($path);
                }
            }

            $dirSpool = (string) config('hubcredito.storage.dir_spool', 'hubcredito-spool');
            $prefix = $this->finalPrefix() . '_' . $job->id;
            if ($disk->exists($dirSpool)) {
                foreach ($disk->files($dirSpool) as $rel) {
                    if (str_starts_with(basename($rel), $prefix)) {
                        $disk->delete($rel);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[HUBCREDITO] Erro ao apagar spool (job {$job->id}): {$e->getMessage()}");
        }

        $job->delete();

        return response()->noContent();
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
            $inputsPath = $job->spool_inputs_path ?? null;
            if ($inputsPath && $disk->exists($inputsPath)) {
                $disk->delete($inputsPath);
            }

            $dirSpool = (string) config('hubcredito.storage.dir_spool', 'hubcredito-spool');
            $prefix = $this->finalPrefix() . '_' . $job->id;
            if ($disk->exists($dirSpool)) {
                foreach ($disk->files($dirSpool) as $rel) {
                    if ($rel === $spoolPath) {
                        continue;
                    }
                    if (str_starts_with(basename($rel), $prefix)) {
                        try {
                            $disk->delete($rel);
                        } catch (\Throwable) {
                        }
                    }
                }
            }

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
}
