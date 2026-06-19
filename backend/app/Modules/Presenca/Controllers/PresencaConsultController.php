<?php

namespace App\Modules\Presenca\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Presenca\Jobs\DispatchPresencaConsultJob;
use App\Modules\Presenca\Jobs\ProcessPresencaConsultJob;
use App\Modules\Presenca\Models\PresencaConsultJob;
use App\Modules\Presenca\Support\PresencaLog;
use App\Modules\Presenca\Support\PresencaSchema;
use App\Modules\Presenca\Support\PresencaSpool;
use App\Support\Cpf;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class PresencaConsultController extends Controller
{
    public function index(Request $request)
    {
        $data = Validator::make($request->query(), [
            'status' => ['nullable', 'in:agendado,pendente,em_progresso,pausado,concluido,falhou,cancelado,todos'],
        ])->validate();

        $jobsQuery = PresencaConsultJob::query();

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
        $job = PresencaConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $reportsDiskName = (string) config('presenca.storage.reports_disk', 'local');
        $reportsDisk = Storage::disk($reportsDiskName);
        $spoolExists = $job->spool_path && $reportsDisk->exists($job->spool_path);
        $spoolHasDataRows = $spoolExists && PresencaSpool::hasDataRows($reportsDisk, $job->spool_path);

        return response()->json([
            'id' => $job->id,
            'title' => $job->title,
            'status' => $job->status,
            'phase' => $job->phase,
            'total_cpfs' => (int) ($job->total_cpfs ?? 0),
            'success_count' => (int) ($job->success_count ?? 0),
            'policy_declined_count' => (int) ($job->policy_declined_count ?? 0),
            'fail_count' => (int) ($job->fail_count ?? 0),
            'has_file' => (bool) $job->has_file,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'canceled_at' => $job->canceled_at,
            'paused_at' => $job->paused_at,
            'cancel_reason' => $job->cancel_reason,
            'scheduled_for' => $job->scheduled_for,
            'created_at' => $job->created_at,
            'preview_running' => in_array($job->status, ['pendente', 'em_progresso', 'pausado', 'cancelado'], true) && $spoolHasDataRows,
            'spool_bytes' => (int) ($job->spool_bytes ?? 0),
        ]);
    }

    public function store(Request $request)
    {
        /** @var UploadedFile|null $uploadedFile */
        $uploadedFile = $request->file('file');
        $rawLines = $request->input('lines', $request->input('entries', $request->input('rows')));

        $validator = Validator::make([
            'title' => $request->input('title'),
            'lines' => $rawLines,
            'file' => $uploadedFile,
            'run_at' => $request->input('run_at'),
            'timezone' => $request->input('timezone'),
        ], [
            'title' => ['required', 'string', 'max:191'],
            'lines' => ['required_without:file'],
            'file' => ['nullable', 'file'],
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
        $runAtRaw = $request->input('run_at');
        $runAt = is_string($runAtRaw) && $runAtRaw !== ''
            ? Carbon::parse($runAtRaw, $timezone)
            : null;
        $scheduledFor = $runAt && $runAt->greaterThan(Carbon::now($timezone))
            ? $runAt->clone()->setTimezone('UTC')
            : null;

        $job = PresencaConsultJob::create([
            'user_id' => $request->user()->id,
            'title' => (string) $request->input('title'),
            'status' => $scheduledFor ? 'agendado' : 'pendente',
            'phase' => null,
            'total_cpfs' => 0,
            'success_count' => 0,
            'policy_declined_count' => 0,
            'fail_count' => 0,
            'scheduled_for' => $scheduledFor,
        ]);

        try {
            if ($uploadedFile instanceof UploadedFile) {
                if (!$uploadedFile->isValid()) {
                    throw new \RuntimeException('Arquivo enviado inválido para processamento.');
                }

                [$spoolPath, $inputsPath, $spoolBytes, $linesCount] = $this->createInitialSpoolFromUploadedFile(
                    $job->id,
                    $uploadedFile
                );
            } else {
                [$spoolPath, $inputsPath, $spoolBytes, $linesCount] = $this->createInitialSpool(
                    $job->id,
                    $this->tokenizeLinesLazy($rawLines)
                );
            }
        } catch (\Throwable $e) {
            $this->safeCleanupInit($job->id);
            $job->delete();
            PresencaLog::error("[PRESENCA] Erro ao preparar spool (job {$job->id}): " . $e->getMessage(), ['exception' => $e]);
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
            'total_cpfs' => $linesCount,
        ]);

        if ($job->status === 'pendente') {
            ProcessPresencaConsultJob::dispatch($job->id)
                ->onQueue((string) config('presenca.job.queue', 'presenca'));
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
        $job = PresencaConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $disk = Storage::disk((string) config('presenca.storage.reports_disk', 'local'));
        $spoolExists = $job->spool_path && $disk->exists($job->spool_path);
        $spoolHasDataRows = $spoolExists && PresencaSpool::hasDataRows($disk, $job->spool_path);

        return response()->json([
            'queued' => false,
            'preview_running' => in_array($job->status, ['pendente', 'em_progresso', 'pausado', 'cancelado'], true) && $spoolHasDataRows,
            'message' => 'Prévia espelha o spool no momento da leitura.',
        ], Response::HTTP_OK);
    }

    public function downloadPreview(Request $request, int $id)
    {
        $job = PresencaConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $disk = Storage::disk((string) config('presenca.storage.reports_disk', 'local'));

        if (empty($job->spool_path) || !$disk->exists($job->spool_path)) {
            return response()->json(['message' => 'Spool indisponível.'], Response::HTTP_CONFLICT);
        }

        if (!PresencaSpool::hasDataRows($disk, $job->spool_path)) {
            return response()->json(['message' => 'Prévia indisponível: nenhum resultado gravado ainda.'], Response::HTTP_CONFLICT);
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

        $withBOM = (bool) config('presenca.csv.embed_bom', true);
        $finalEol = strtoupper((string) config('presenca.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";

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

                echo PresencaSchema::headerCsvLine(';') . $finalEol;

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
        $job = PresencaConsultJob::query()
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
        $job = PresencaConsultJob::query()
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
        $job = PresencaConsultJob::query()
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
        $job = PresencaConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->status !== 'pausado') {
            return response()->json([
                'message' => 'Apenas jobs pausados podem ser retomados.',
                'status' => $job->status,
            ], Response::HTTP_CONFLICT);
        }

        $disk = Storage::disk((string) config('presenca.storage.reports_disk', 'local'));
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

        DispatchPresencaConsultJob::dispatch($job->id)
            ->delay(now()->addSeconds(2))
            ->onQueue((string) config('presenca.job.queue', 'presenca'));

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
        ], Response::HTTP_ACCEPTED);
    }

    public function destroy(int $id)
    {
        $job = PresencaConsultJob::query()
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
            PresencaLog::warning("[PRESENCA] Erro ao apagar arquivo final (job {$job->id}): " . $e->getMessage());
        }

        try {
            $disk = Storage::disk((string) config('presenca.storage.reports_disk', 'local'));
            foreach (['spool_path', 'spool_inputs_path'] as $field) {
                $p = $job->{$field};
                if ($p && $disk->exists($p)) {
                    $disk->delete($p);
                }
            }
        } catch (\Throwable $e) {
            PresencaLog::warning("[PRESENCA] Erro ao apagar spool (job {$job->id}): " . $e->getMessage());
        }

        $job->delete();

        return response()->noContent();
    }

    private function finalPrefix(): string
    {
        return (string) config('presenca.storage.final_prefix', 'presenca-consulta');
    }

    private function finalizeCancelledPreservingUsefulPreview(PresencaConsultJob $job): void
    {
        $disk = Storage::disk((string) config('presenca.storage.reports_disk', 'local'));
        $spoolPath = $job->spool_path ?? null;
        $hasDataRows = PresencaSpool::hasDataRows($disk, $spoolPath);

        try {
            $inputsPath = $job->spool_inputs_path ?? null;
            if ($inputsPath && $disk->exists($inputsPath)) {
                $disk->delete($inputsPath);
            }

            if (!$hasDataRows && $spoolPath && $disk->exists($spoolPath)) {
                $disk->delete($spoolPath);
            }
        } catch (\Throwable $e) {
            PresencaLog::warning("[PRESENCA] Erro ao finalizar cancelamento preservando prévia (job {$job->id}): " . $e->getMessage());
        }

        $spoolBytes = 0;
        if ($hasDataRows && $spoolPath && $disk->exists($spoolPath)) {
            try {
                $spoolBytes = (int) $disk->size($spoolPath);
            } catch (\Throwable) {
                $spoolBytes = 0;
            }
        }

        $job->updateQuietly([
            'spool_path' => $hasDataRows ? $spoolPath : null,
            'spool_inputs_path' => null,
            'spool_bytes' => $spoolBytes,
            'phase' => null,
            'finished_at' => $job->finished_at ?? now(),
        ]);
    }

    private function tokenizeLinesLazy($lines): \Generator
    {
        if (is_string($lines)) {
            $token = strtok($lines, "\r\n");
            while ($token !== false) {
                yield $token;
                $token = strtok("\r\n");
            }
            return;
        }

        if (is_array($lines)) {
            foreach ($lines as $line) {
                yield $line;
            }
            return;
        }

        if ($lines instanceof \Traversable) {
            foreach ($lines as $line) {
                yield $line;
            }
        }
    }

    private function createInitialSpoolFromUploadedFile(int $jobId, UploadedFile $uploadedFile): array
    {
        $realPath = $uploadedFile->getRealPath();
        if (!is_string($realPath) || $realPath === '' || !is_file($realPath)) {
            throw new \RuntimeException('Arquivo temporário de upload indisponível.');
        }

        $handle = @fopen($realPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Falha ao abrir stream de leitura do upload.');
        }

        try {
            return $this->createInitialSpool($jobId, $this->iterateHandleLines($handle));
        } finally {
            fclose($handle);
        }
    }

    private function iterateHandleLines($handle): \Generator
    {
        while (($line = fgets($handle)) !== false) {
            yield $line;
        }
    }

    private function createInitialSpool(int $jobId, iterable $allLines): array
    {
        $diskName = (string) config('presenca.storage.reports_disk', 'local');
        $disk = Storage::disk($diskName);

        $dirSpool = (string) (config('presenca.storage.dir_spool') ?? 'presenca-spool');
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
                fputcsv($fp, PresencaSchema::TITLES, ';');
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

                foreach ($allLines as $rawLine) {
                    [$cpf, $nome] = $this->parseEntryLine($rawLine);
                    if (!$cpf || !$nome) {
                        continue;
                    }

                    fputcsv($fp2, [$cpf, $nome], ';');
                    $count++;
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

        return [$spoolPath, $inputsPath, $bytes, $count];
    }

    private function parseEntryLine($rawLine): array
    {
        if (is_array($rawLine)) {
            $cpfRaw = (string) ($rawLine['cpf'] ?? $rawLine[0] ?? '');
            $nomeRaw = (string) ($rawLine['nome'] ?? $rawLine[1] ?? '');
            $cpf = Cpf::normalize($cpfRaw);
            $nome = trim(preg_replace('/\s+/', ' ', $nomeRaw) ?? '');
            return [$cpf, $nome !== '' ? $nome : null];
        }

        $line = trim((string) $rawLine);
        if ($line === '') {
            return [null, null];
        }

        if (str_contains($line, ';')) {
            $parts = str_getcsv($line, ';');
            $cpf = Cpf::normalize((string) ($parts[0] ?? ''));
            $nome = trim(preg_replace('/\s+/', ' ', (string) ($parts[1] ?? '')) ?? '');
            return [$cpf, $nome !== '' ? $nome : null];
        }

        if (str_contains($line, ',')) {
            $parts = str_getcsv($line, ',');
            $cpf = Cpf::normalize((string) ($parts[0] ?? ''));
            $nome = trim(preg_replace('/\s+/', ' ', (string) ($parts[1] ?? '')) ?? '');
            return [$cpf, $nome !== '' ? $nome : null];
        }

        if (preg_match('/^([0-9\.\/-]+)\s+(.+)$/', $line, $m)) {
            $cpf = Cpf::normalize($m[1] ?? '');
            $nome = trim(preg_replace('/\s+/', ' ', (string) ($m[2] ?? '')) ?? '');
            return [$cpf, $nome !== '' ? $nome : null];
        }

        return [null, null];
    }

    private function safeCleanupInit(int $jobId): void
    {
        try {
            $disk = Storage::disk((string) config('presenca.storage.reports_disk', 'local'));
            $dirSpool = (string) (config('presenca.storage.dir_spool') ?? 'presenca-spool');
            $finalPref = $this->finalPrefix();
            $targets = [
                "{$dirSpool}/{$finalPref}_{$jobId}.spool.csv",
                "{$dirSpool}/{$finalPref}_{$jobId}.inputs.txt",
            ];

            foreach ($targets as $path) {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        } catch (\Throwable $e) {
            PresencaLog::warning("[PRESENCA] Falha ao limpar após erro no createInitialSpool (job {$jobId}): " . $e->getMessage());
        }
    }

    private function safeCleanupPaths(array $relPaths): void
    {
        try {
            $disk = Storage::disk((string) config('presenca.storage.reports_disk', 'local'));
            foreach ($relPaths as $path) {
                if ($path && $disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        } catch (\Throwable $e) {
            PresencaLog::warning("[PRESENCA] Erro limpando arquivos: " . $e->getMessage());
        }
    }
}
