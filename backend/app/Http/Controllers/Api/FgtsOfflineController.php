<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateFgtsOffPreviewJob;
use App\Jobs\ProcessFgtsOfflineJob;
use App\Models\FgtsOfflineJob;
use App\Support\Cpf;
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
            'has_preview' => (bool) $job->has_preview,
            'preview_updated_at' => $job->preview_updated_at,
            'preview_status' => $job->preview_status,
            'preview_requested_at' => $job->preview_requested_at,
            'preview_started_at' => $job->preview_started_at,
            'preview_finished_at' => $job->preview_finished_at,
            'preview_size_bytes' => $job->preview_size_bytes,
            'preview_rows' => $job->preview_rows,
            'preview_error' => $job->preview_error,
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

        // Cria registro do job (total_cpfs = 0; job calculará o total único na classificação)
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
            'preview_dirty'         => false,
            'preview_status'        => 'none',
        ]);

        // Cria spool e arquivo de CPFs em streaming — SEM materializar arrays, sem "primeira passada"
        try {
            [$spoolPath, $cpfsPath, $spoolBytes, $cpfsCount] = $this->createInitialSpool(
                $job->id,
                $this->tokenizeCpfsLazy($data['cpfs'])
            );
        } catch (\Throwable $e) {
            // Limpeza defensiva de caminhos esperados
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

            // Heurística simples: InvalidArgument => 422; demais => 500
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
            // Limpa arquivos criados e remove o job
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
            // total_cpfs ficará para o Job calcular com dedup de válidos e inválidos
        ]);

        // Enfileira somente com o ID (sem payload)
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

    public function requestPreview(Request $request, int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $force = (bool) $request->boolean('force', false);

        $reportsDiskName = (string) config('facta_off.storage.reports_disk', 'public');
        $reportsDisk = Storage::disk($reportsDiskName);

        if (
            empty($job->spool_path) || empty($job->spool_cpfs_path) ||
            !$reportsDisk->exists($job->spool_path) || !$reportsDisk->exists($job->spool_cpfs_path)
        ) {
            return response()->json(['message' => 'Prévia não disponível ainda (spool ausente).'], Response::HTTP_CONFLICT);
        }

        $hasFileReady = false;
        if ($job->preview_disk && $job->preview_path) {
            $pDisk = Storage::disk($job->preview_disk);
            $hasFileReady = $pDisk->exists($job->preview_path);
        }
        if ($hasFileReady && !$force && !$job->preview_dirty && $job->preview_status === 'ready') {
            return response()->json([
                'message' => 'Prévia já está pronta.',
                'preview_status' => $job->preview_status,
                'preview_rows' => $job->preview_rows,
                'preview_size_bytes' => $job->preview_size_bytes,
                'preview_updated_at' => $job->preview_updated_at,
            ], Response::HTTP_OK);
        }

        if (in_array($job->preview_status, ['queued', 'running'], true)) {
            return response()->json([
                'message' => 'Prévia já está sendo gerada.',
                'preview_status' => $job->preview_status,
            ], Response::HTTP_ACCEPTED);
        }

        $job->update([
            'preview_status' => 'queued',
            'preview_requested_at' => Carbon::now(),
            'preview_error' => null,
        ]);

        GenerateFgtsOffPreviewJob::dispatch($job->id);

        return response()->json([
            'message' => 'Geração de prévia enfileirada.',
            'preview_status' => 'queued',
        ], Response::HTTP_ACCEPTED);
    }

    /** 📥 Download da PRÉVIA (streaming) */
    public function downloadPreview(Request $request, int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $diskName = $job->preview_disk ?: (string) config('facta_off.storage.reports_disk', 'public');
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        if (!$job->preview_path || !$disk->exists($job->preview_path)) {
            return response()->json([
                'message' => 'Prévia ainda não está pronta.',
                'preview_status' => $job->preview_status ?? 'none',
            ], Response::HTTP_CONFLICT);
        }

        $fileName = $job->preview_name ?: "{$this->finalPrefix()}_{$job->id}_preview.xlsx";

        if (method_exists($disk, 'download')) {
            return $disk->download($job->preview_path, $fileName);
        }

        $stream = $disk->readStream($job->preview_path);
        if ($stream === false) {
            return response()->json(['message' => 'Falha ao abrir arquivo.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) fclose($stream);
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** Download do relatório FINAL (streaming) */
    public function download(int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if (!in_array($job->status, ['concluido', 'expirado', 'falhou'], true) || empty($job->file_disk) || empty($job->file_path)) {
            return response()->json(['message' => 'Relatório ainda não disponível.'], Response::HTTP_CONFLICT);
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($job->file_disk);
        $filename = $job->file_name ?: "fgts-offline-{$job->id}.xlsx";

        if (!$disk->exists($job->file_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (method_exists($disk, 'download')) {
            return $disk->download($job->file_path, $filename);
        }

        $stream = $disk->readStream($job->file_path);
        if ($stream === false) {
            return response()->json(['message' => 'Falha ao abrir arquivo.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->streamDownload(function () use ($stream) {
            fpassthru($stream);
            if (is_resource($stream)) fclose($stream);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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
            if ($job->preview_disk && $job->preview_path) {
                $disk = Storage::disk($job->preview_disk);
                if ($disk->exists($job->preview_path)) {
                    $disk->delete($job->preview_path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FGTS-OFF] Erro ao apagar prévia no cancel (job {$job->id}): ".$e->getMessage(), ['exception' => $e]);
        } finally {
            $job->update([
                'preview_disk' => null,
                'preview_path' => null,
                'preview_name' => null,
                'preview_updated_at' => null,
                'preview_dirty' => false,
                'preview_status' => 'none',
                'preview_requested_at' => null,
                'preview_started_at' => null,
                'preview_finished_at' => null,
                'preview_size_bytes' => 0,
                'preview_rows' => 0,
                'preview_error' => null,
            ]);
        }

        // Spool é apagado pelo Process job ao detectar cancelamento,
        // mas tentamos limpar aqui também por segurança.
        try {
            $diskName = (string) config('facta_off.storage.reports_disk', 'public');
            $disk = Storage::disk($diskName);
            foreach (['spool_path', 'spool_cpfs_path'] as $field) {
                $p = $job->{$field};
                if ($p && $disk->exists($p)) {
                    $disk->delete($p);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FGTS-OFF] Erro ao apagar spool no cancel (job {$job->id}): ".$e->getMessage());
        }

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
            if ($job->preview_disk && $job->preview_path) {
                $disk = Storage::disk($job->preview_disk);
                if ($disk->exists($job->preview_path)) {
                    $disk->delete($job->preview_path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FGTS-OFF] Erro ao apagar arquivo de prévia (job {$job->id}): ".$e->getMessage());
        }

        try {
            $diskName = (string) config('facta_off.storage.reports_disk', 'public');
            $disk = Storage::disk($diskName);
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

    /**
     * Generator/lazy tokenizer para entrada de CPFs (string ou array).
     * Não materializa listas grandes em memória.
     *
     * @param string|array $cpfs
     * @return \Generator<string>
     */
    private function tokenizeCpfsLazy($cpfs): \Generator
    {
        if (is_string($cpfs)) {
            // Delimiters: espaço, tab, quebra de linha, vírgula e ponto e vírgula
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

        // Fallback seguro
        if ($cpfs instanceof \Traversable) {
            foreach ($cpfs as $t) {
                yield $t;
            }
        }
    }

    /**
     * Cria spool inicial (CSV com cabeçalho) e arquivo de CPFs (um por linha),
     * escrevendo em streaming e sem deduplicar em memória.
     * Retorna [spoolPath, cpfsPath, spoolBytes, cpfsCount].
     *
     * @param int $jobId
     * @param iterable $allCpfs
     * @return array{0:string,1:string,2:int,3:int}
     */
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

        // Cria spool CSV com cabeçalho
        $spoolReal = $disk->path($spoolPath);
        $fp = fopen($spoolReal, 'c+');
        if ($fp === false) {
            throw new \RuntimeException("Não foi possível criar spool em {$spoolPath}");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fputcsv($fp, \App\Exports\FgtsOfflineExport::COLS, ';');
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        // Escreve CPFs normalizados (um por linha), sem arrays gigantes na memória
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
                    $norm = Cpf::normalize((string) $raw);
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
