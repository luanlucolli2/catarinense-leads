<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessFgtsOfflineJob;
use App\Models\FgtsOfflineJob;
use App\Support\Cpf;
use App\Support\FgtsOffSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Validator;

class FgtsOfflineController extends Controller
{
    public function index(Request $request)
    {
        $jobs = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($jobs);
    }

    public function show(int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $reportsDiskName = (string) config('facta_off.storage.reports_disk', 'public');
        $reportsDisk = Storage::disk($reportsDiskName);
        $spoolExists = $job->spool_path && $reportsDisk->exists($job->spool_path);

        return response()->json([
            'id' => $job->id,
            'title' => $job->title,
            'status' => $job->status,
            'total_cpfs' => $job->total_cpfs,
            'success_count' => $job->success_count,
            'not_authorized_count' => $job->not_authorized_count,
            'fail_count' => $job->fail_count,
            'has_file' => (bool) $job->has_file,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'scheduled_for' => $job->scheduled_for,
            'scheduled_until' => $job->scheduled_until,
            'created_at' => $job->created_at,
            'preview_running' => in_array($job->status, ['pendente','em_progresso'], true) && $spoolExists,
            'spool_bytes' => $job->spool_bytes,
        ]);
    }

    public function store(Request $request)
    {
        $rules = [
            'title' => ['required', 'string', 'max:191'],
            'cpfs' => ['required'],
            'run_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'required_with:run_at', 'after:run_at'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ];

        $validator = Validator::make($request->all(), $rules, [
            'end_at.required_with' => 'O campo end_at é obrigatório quando run_at está presente.',
            'end_at.after' => 'O horário final (end_at) deve ser maior que o horário inicial (run_at).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Os dados fornecidos são inválidos.',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $validator->validated();

        $tz    = $data['timezone'] ?? 'America/Sao_Paulo';
        $runAt = isset($data['run_at']) ? Carbon::parse($data['run_at'], $tz) : null;
        $endAt = isset($data['end_at']) ? Carbon::parse($data['end_at'], $tz) : null;

        $job = FgtsOfflineJob::create([
            'user_id'               => $request->user()->id,
            'title'                 => $data['title'],
            'status'                => $runAt && $runAt->greaterThan(Carbon::now($tz)) ? 'agendado' : 'pendente',
            'total_cpfs'            => 0,
            'success_count'         => 0,
            'not_authorized_count'  => 0,
            'fail_count'            => 0,
            'scheduled_for'         => $runAt ? $runAt->clone()->setTimezone('UTC') : null,
            'scheduled_until'       => $endAt ? $endAt->clone()->setTimezone('UTC') : null,
        ]);

        try {
            [$spoolPath, $cpfsPath, $spoolBytes, $cpfsCount] = $this->createInitialSpool(
                $job->id,
                $this->tokenizeCpfsLazy($data['cpfs'])
            );
        } catch (\Throwable $e) {
            try {
                $diskName = (string) config('facta_off.storage.reports_disk', 'public');
                $disk     = Storage::disk($diskName);
                $dirSpool = (string) (config('facta_off.storage.dir_spool') ?? 'fgts-off-spool');
                $finalPref= (string) config('facta_off.storage.final_prefix', 'fgts-offline');
                $spool    = "{$dirSpool}/{$finalPref}_{$job->id}.spool.csv";
                $cpfs     = "{$dirSpool}/{$finalPref}_{$job->id}.cpfs.txt";
                foreach ([$spool, $cpfs] as $p) {
                    if ($disk->exists($p)) { $disk->delete($p); }
                }
            } catch (\Throwable $e2) {
                Log::warning("[FGTS-OFF] Falha ao limpar após erro no createInitialSpool (job {$job->id}): ".$e2->getMessage());
            }

            $job->delete();

            $code = ($e instanceof \InvalidArgumentException)
                ? Response::HTTP_UNPROCESSABLE_ENTITY
                : Response::HTTP_INTERNAL_SERVER_ERROR;

            Log::error("[FGTS-OFF] Erro ao preparar spool (job {$job->id}): ".$e->getMessage(), ['exception' => $e]);

            return response()->json([
                'message' => $code === Response::HTTP_UNPROCESSABLE_ENTITY
                    ? 'Os dados fornecidos são inválidos para gerar o spool.'
                    : 'Falha interna ao preparar arquivos do job.',
            ], $code);
        }

        if ($cpfsCount === 0) {
            try {
                $diskName = (string) config('facta_off.storage.reports_disk', 'public');
                $disk = Storage::disk($diskName);
                foreach ([$spoolPath, $cpfsPath] as $p) {
                    if ($p && $disk->exists($p)) { $disk->delete($p); }
                }
            } catch (\Throwable $e) {
                Log::warning("[FGTS-OFF] Erro limpando arquivos após cpfsCount=0 (job {$job->id}): ".$e->getMessage());
            }
            $job->delete();

            return response()->json([
                'message' => 'Nenhum CPF normalizável encontrado após processar a entrada.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job->update([
            'spool_path'      => $spoolPath,
            'spool_cpfs_path' => $cpfsPath,
            'spool_bytes'     => $spoolBytes,
        ]);

        if ($job->status === 'agendado') {
            ProcessFgtsOfflineJob::dispatch($job->id)->delay($job->scheduled_for);
            return response()->json([
                'id'               => $job->id,
                'status'           => $job->status,
                'scheduled_for'    => $job->scheduled_for,
                'scheduled_until'  => $job->scheduled_until,
            ], Response::HTTP_ACCEPTED);
        }

        ProcessFgtsOfflineJob::dispatch($job->id);

        return response()->json([
            'id'     => $job->id,
            'status' => $job->status,
        ], Response::HTTP_ACCEPTED);
    }

    /** Estado “prévia” leve. Não enfileira nada. */
    public function requestPreview(Request $request, int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $disk = Storage::disk((string) config('facta_off.storage.reports_disk', 'public'));
        $spoolExists = $job->spool_path && $disk->exists($job->spool_path);

        return response()->json([
            'queued' => false,
            'preview_running' => in_array($job->status, ['pendente','em_progresso'], true) && $spoolExists,
            'message' => 'Prévia espelha o spool no momento da leitura.',
        ], Response::HTTP_OK);
    }

    /** Streaming da “prévia” com cabeçalho normalizado. */
    public function downloadPreview(Request $request, int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $disk = Storage::disk((string) config('facta_off.storage.reports_disk', 'public'));

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
        $withBOM = (bool) env('FGTS_OFF_CSV_BOM', true);
        $finalEol   = strtoupper((string) config('facta_off.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";

        return response()->streamDownload(function () use ($fh, $withBOM, $finalEol) {
            try {
                flock($fh, LOCK_SH);
                // BOM opcional
                if ($withBOM) echo "\xEF\xBB\xBF";

                // consome a primeira linha do spool (cabeçalho antigo)
                $peek = fread($fh, 3);
                if ($peek !== "\xEF\xBB\xBF") {
                    // não tinha BOM, volta ao início
                    fseek($fh, 0);
                }
                // lê e descarta a 1ª linha do arquivo original
                fgets($fh);

                // escreve o novo cabeçalho normalizado
                echo \App\Support\FgtsOffSchema::headerCsvLine(';') . $finalEol;

                // despeja o restante
                fpassthru($fh);
            } finally {
                flock($fh, LOCK_UN);
                if (is_resource($fh)) fclose($fh);
            }
        }, $filename, $headers);
    }

    /** Download do relatório FINAL (CSV) sem apagar. */
    public function download(int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if (!in_array($job->status, ['concluido', 'expirado', 'falhou'], true) || empty($job->file_disk) || empty($job->file_path)) {
            return response()->json(['message' => 'Relatório ainda não disponível.'], Response::HTTP_CONFLICT);
        }

        $disk = Storage::disk($job->file_disk);
        if (!$disk->exists($job->file_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $filename = $job->file_name ?: "fgts-offline-{$job->id}.csv";
        $fh = $disk->readStream($job->file_path);
        if ($fh === false) {
            return response()->json(['message' => 'Falha ao abrir arquivo.'], 500);
        }

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Accel-Buffering' => 'no',
        ];

        // CSV final já sai normalizado (BOM/EOL) pelo job de finalização
        $withBOM = false;

        return response()->streamDownload(function () use ($fh, $withBOM) {
            try {
                if ($withBOM) echo "\xEF\xBB\xBF";
                fpassthru($fh);
            } finally {
                if (is_resource($fh)) fclose($fh);
            }
        }, $filename, $headers);
    }

    public function cancel(Request $request, int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if (in_array($job->status, ['concluido', 'falhou', 'cancelado', 'expirado'], true)) {
            return response()->json([
                'message' => 'Job não pode ser cancelado neste estado.',
                'status' => $job->status,
            ], Response::HTTP_CONFLICT);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:191'],
        ]);

        $job->update([
            'status' => 'cancelado',
            'canceled_at' => now(),
            'cancel_reason' => $data['reason'] ?? null,
        ]);

        try {
            $disk = Storage::disk((string) config('facta_off.storage.reports_disk', 'public'));
            foreach (['spool_path', 'spool_cpfs_path'] as $field) {
                $p = $job->{$field};
                if ($p && $disk->exists($p)) {
                    $disk->delete($p);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FGTS-OFF] Erro ao apagar spool no cancel (job {$job->id}): ".$e->getMessage());
        }

        $job->update([
            'spool_path' => null,
            'spool_cpfs_path' => null,
            'spool_bytes' => 0,
        ]);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'canceled_at' => $job->canceled_at,
            'cancel_reason' => $job->cancel_reason,
        ]);
    }

    public function destroy(int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if (in_array($job->status, ['pendente', 'em_progresso', 'agendado'], true)) {
            return response()->json([
                'message' => 'Não é possível excluir enquanto o job está em andamento/agendado. Cancele primeiro.',
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
            Log::warning("[FGTS-OFF] Erro ao apagar arquivo final (job {$job->id}): ".$e->getMessage());
        }

        try {
            $disk = Storage::disk((string) config('facta_off.storage.reports_disk', 'public'));
            foreach (['spool_path', 'spool_cpfs_path'] as $field) {
                $p = $job->{$field};
                if ($p && $disk->exists($p)) {
                    $disk->delete($p);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FGTS-OFF] Erro ao apagar spool (job {$job->id}): ".$e->getMessage());
        }

        $job->delete();

        return response()->noContent();
    }

    private function finalPrefix(): string
    {
        return (string) config('facta_off.storage.final_prefix', 'fgts-offline');
    }

    private function tokenizeCpfsLazy($cpfs): \Generator
    {
        if (is_string($cpfs)) {
            $delims = " \t\n\r,;";
            $tok = strtok($cpfs, $delims);
            while ($tok !== false) {
                yield $tok;
                $tok = strtok($delims);
            }
            return;
        }

        if (is_array($cpfs)) {
            foreach ($cpfs as $t) {
                yield $t;
            }
            return;
        }

        if ($cpfs instanceof \Traversable) {
            foreach ($cpfs as $t) {
                yield $t;
            }
        }
    }

    private function createInitialSpool(int $jobId, iterable $allCpfs): array
    {
        $diskName = (string) config('facta_off.storage.reports_disk', 'public');
        $disk = Storage::disk($diskName);

        $dirSpool   = (string) (config('facta_off.storage.dir_spool') ?? 'fgts-off-spool');
        $finalPref  = (string) config('facta_off.storage.final_prefix', 'fgts-offline');

        if (!$disk->exists($dirSpool)) {
            $disk->makeDirectory($dirSpool);
        }

        $spoolName = "{$finalPref}_{$jobId}.spool.csv";
        $cpfsName  = "{$finalPref}_{$jobId}.cpfs.txt";

        $spoolPath = "{$dirSpool}/{$spoolName}";
        $cpfsPath  = "{$dirSpool}/{$cpfsName}";

        $spoolReal = $disk->path($spoolPath);
        $fp = fopen($spoolReal, 'c+');
        if ($fp === false) {
            throw new \RuntimeException("Não foi possível criar spool em {$spoolPath}");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                // Cabeçalho com títulos normalizados
                fputcsv($fp, FgtsOffSchema::TITLES, ';');
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        $cpfsReal = $disk->path($cpfsPath);
        $fp2 = fopen($cpfsReal, 'c+');
        if ($fp2 === false) {
            throw new \RuntimeException("Não foi possível criar cpfs em {$cpfsPath}");
        }

        $count = 0;
        try {
            if (flock($fp2, LOCK_EX)) {
                ftruncate($fp2, 0);
                foreach ($allCpfs as $raw) {
                    $norm = \App\Support\Cpf::normalize((string) $raw);
                    if ($norm === null) continue;
                    $digits = preg_replace('/\D+/', '', $norm);
                    if ($digits === '' || strlen($digits) !== 11) continue;
                    fwrite($fp2, $digits . "\n");
                    $count++;
                }
                fflush($fp2);
                flock($fp2, LOCK_UN);
            }
        } finally {
            fclose($fp2);
        }

        $bytes = 0;
        try { $bytes = (int) $disk->size($spoolPath); } catch (\Throwable) {}

        return [$spoolPath, $cpfsPath, $bytes, $count];
    }
}
