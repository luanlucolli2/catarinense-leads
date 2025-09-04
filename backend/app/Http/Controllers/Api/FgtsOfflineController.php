<?php

namespace App\Http\Controllers\Api;

use App\Exports\FgtsOfflineExport;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessFgtsOfflineJob;
use App\Models\FgtsOfflineJob;
use App\Support\Cpf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
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
            'success_count' => $job->success_count,              // autorizado
            'not_authorized_count' => $job->not_authorized_count, // não autorizado
            'fail_count' => $job->fail_count,                    // erro
            'has_file' => $job->file_disk && $job->file_path,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'scheduled_for' => $job->scheduled_for,
            'scheduled_until' => $job->scheduled_until,
            'created_at' => $job->created_at,
            // prévia
            'has_preview' => $job->preview_disk && $job->preview_path,
            'preview_updated_at' => $job->preview_updated_at,
            // telemetria do spool (opcional para o front)
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

        $cpfs = $data['cpfs'];
        $tokens = is_string($cpfs)
            ? (preg_split('/[\s,;]+/u', $cpfs, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            : (is_array($cpfs) ? $cpfs : []);

        $valid = [];
        $invalid = [];

        foreach ($tokens as $t) {
            $norm = Cpf::normalize((string) $t);
            if ($norm === null)
                continue;

            if (Cpf::isValid($norm))
                $valid[] = $norm;
            else
                $invalid[] = $norm;
        }

        $valid = array_values(array_unique($valid));
        $invalid = array_values(array_diff(array_unique($invalid), $valid));

        if ((count($valid) + count($invalid)) === 0) {
            return response()->json([
                'message' => 'Nenhum CPF válido ou normalizável encontrado (8–11 dígitos; 8–10 serão completados com zeros à esquerda).'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // ---------- Agendamento ----------
        $tz = $data['timezone'] ?? 'America/Sao_Paulo';
        $runAt = isset($data['run_at']) ? Carbon::parse($data['run_at'], $tz) : null;
        $endAt = isset($data['end_at']) ? Carbon::parse($data['end_at'], $tz) : null;

        if ($runAt) {
            if ($runAt->greaterThan(Carbon::now($tz))) {
                $runAtUtc = $runAt->clone()->setTimezone('UTC');
                $endAtUtc = $endAt->clone()->setTimezone('UTC');

                $job = FgtsOfflineJob::create([
                    'user_id' => $request->user()->id,
                    'title' => $data['title'],
                    'status' => 'agendado',
                    'total_cpfs' => count($valid) + count($invalid),
                    'success_count' => 0,
                    'not_authorized_count' => 0,
                    'fail_count' => 0,
                    'scheduled_for' => $runAtUtc,
                    'scheduled_until' => $endAtUtc,
                    'preview_dirty' => false,
                ]);

                ProcessFgtsOfflineJob::dispatch($job->id, $request->user()->id, $job->title, $valid, $invalid)
                    ->delay($runAtUtc);

                return response()->json([
                    'id' => $job->id,
                    'status' => $job->status,
                    'scheduled_for' => $job->scheduled_for,
                    'scheduled_until' => $job->scheduled_until,
                ], Response::HTTP_ACCEPTED);
            }
        }

        // ---------- Execução imediata ----------
        $job = FgtsOfflineJob::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'status' => 'pendente',
            'total_cpfs' => count($valid) + count($invalid),
            'success_count' => 0,
            'not_authorized_count' => 0,
            'fail_count' => 0,
            'scheduled_for' => null,
            'scheduled_until' => null,
            'preview_dirty' => false,
        ]);

        ProcessFgtsOfflineJob::dispatch($job->id, $request->user()->id, $job->title, $valid, $invalid);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
        ], Response::HTTP_ACCEPTED);
    }

    /** Download do relatório FINAL */
    public function download(int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if (!in_array($job->status, ['concluido', 'expirado', 'falhou'], true) || empty($job->file_disk) || empty($job->file_path)) {
            return response()->json(['message' => 'Relatório ainda não disponível.'], Response::HTTP_CONFLICT);
        }

        $filename = $job->file_name ?: "fgts-offline-{$job->id}.xlsx";

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($job->file_disk);

        if (!$disk->exists($job->file_path)) {
            return response()->json(['message' => 'Arquivo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        if (method_exists($disk, 'download')) {
            return $disk->download($job->file_path, $filename);
        }

        $content = $disk->get($job->file_path);
        $mime = $disk->mimeType($job->file_path)
            ?? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response($content, Response::HTTP_OK, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Download da PRÉVIA sob demanda (gera XLSX a partir do SPOOL e inclui PENDENTES).
     * Use ?refresh=1 para forçar regeneração.
     */
    public function downloadPreview(Request $request, int $id)
    {
        $job = FgtsOfflineJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        // precisa ter spool criado pelo worker
        if (empty($job->spool_path) || empty($job->spool_cpfs_path)) {
            return response()->json(['message' => 'Prévia não disponível ainda.'], Response::HTTP_CONFLICT);
        }

        $diskName = config('facta_off.storage.reports_disk', 'public');
        $disk = Storage::disk($diskName);

        if (!$disk->exists($job->spool_path) || !$disk->exists($job->spool_cpfs_path)) {
            return response()->json(['message' => 'Prévia não disponível (spool ausente).'], Response::HTTP_CONFLICT);
        }

        $needRefresh = (bool) $request->boolean('refresh', true) // por padrão regenerar
            || empty($job->preview_disk) || empty($job->preview_path)
            || !$disk->exists($job->preview_path)
            || $job->preview_dirty;

        $fileName = ($job->preview_name ?: "{$this->finalPrefix()}_{$job->id}_preview.xlsx");
        $tmpName = preg_replace('/\.xlsx$/', '.tmp.xlsx', $fileName);
        $path = (string) config('facta_off.storage.dir_previews', 'fgts-off-previews') . "/{$fileName}";
        $tmpPath = (string) config('facta_off.storage.dir_previews', 'fgts-off-previews') . "/{$tmpName}";

        if ($needRefresh) {
            // Gerador: 1) lê spool CSV, 2) depois emite linhas "pendentes"
            $spoolReal = $disk->path($job->spool_path);
            $cpfsReal = $disk->path($job->spool_cpfs_path);

            $iteratorFactory = function () use ($spoolReal, $cpfsReal): \Generator {
                $done = [];

                // 1) processados no spool
                $fh = fopen($spoolReal, 'r');
                if ($fh !== false) {
                    try {
                        // lock compartilhado para consistência
                        flock($fh, LOCK_SH);
                        $header = fgetcsv($fh, 0, ';'); // pula cabeçalho
                        while (($data = fgetcsv($fh, 0, ';')) !== false) {
                            $assoc = [];
                            foreach (FgtsOfflineExport::COLS as $i => $key) {
                                $assoc[$key] = $data[$i] ?? null;
                            }
                            $cpf = (string) ($assoc['cpf'] ?? '');
                            if ($cpf !== '')
                                $done[$cpf] = true;
                            yield $assoc;
                        }
                    } finally {
                        flock($fh, LOCK_UN);
                        fclose($fh);
                    }
                }

                // 2) pendentes = CPFs do arquivo original que ainda não apareceram no spool
                $fh2 = fopen($cpfsReal, 'r');
                if ($fh2 !== false) {
                    try {
                        flock($fh2, LOCK_SH);
                        while (($line = fgets($fh2)) !== false) {
                            $cpf = trim($line);
                            if ($cpf === '' || isset($done[$cpf]))
                                continue;

                            $row = array_fill_keys(FgtsOfflineExport::COLS, null);
                            $row['cpf'] = $cpf;
                            $row['mensagem'] = 'Em andamento';
                            $row['consultadoEm'] = Carbon::now('America/Sao_Paulo')->format('d/m/Y H:i:s');

                            yield $row;
                        }
                    } finally {
                        flock($fh2, LOCK_UN);
                        fclose($fh2);
                    }
                }
            };

            $export = FgtsOfflineExport::fromGenerator($iteratorFactory);
            Excel::store($export, $tmpPath, $diskName);
            $disk->move($tmpPath, $path);

            $job->update([
                'preview_disk' => $diskName,
                'preview_path' => $path,
                'preview_name' => $fileName,
                'preview_updated_at' => Carbon::now(),
                'preview_dirty' => false,
            ]);
        }

        if (!$disk->exists($path)) {
            return response()->json(['message' => 'Falha ao gerar prévia.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (method_exists($disk, 'download')) {
            return $disk->download($path, $fileName);
        }

        $content = $disk->get($path);
        $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response($content, Response::HTTP_OK, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
        ]);
    }

    /** Cancelar job (apaga a PRÉVIA imediatamente) */
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

        // apaga PRÉVIA imediatamente
        try {
            if ($job->preview_disk && $job->preview_path) {
                $disk = Storage::disk($job->preview_disk);
                if ($disk->exists($job->preview_path)) {
                    $disk->delete($job->preview_path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning(
                "[FGTS-OFF] Erro ao apagar prévia no cancel (job {$job->id}): " . $e->getMessage(),
                ['exception' => $e]
            );
        } finally {

            $job->update([
                'preview_disk' => null,
                'preview_path' => null,
                'preview_name' => null,
                'preview_updated_at' => null,
                'preview_dirty' => false,
            ]);
        }

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'canceled_at' => $job->canceled_at,
            'cancel_reason' => $job->cancel_reason,
        ]);
    }

    /** Excluir job + arquivos (final, prévia e spool). Bloqueia se pendente/em_progresso/agendado. */
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

        // Apaga arquivo FINAL, se houver
        try {
            if ($job->file_disk && $job->file_path) {
                $disk = Storage::disk($job->file_disk);
                if ($disk->exists($job->file_path)) {
                    $disk->delete($job->file_path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FGTS-OFF] Erro ao apagar arquivo final (job {$job->id}): " . $e->getMessage());
        }

        // Apaga PRÉVIA, se ainda existir
        try {
            if ($job->preview_disk && $job->preview_path) {
                $disk = Storage::disk($job->preview_disk);
                if ($disk->exists($job->preview_path)) {
                    $disk->delete($job->preview_path);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FGTS-OFF] Erro ao apagar arquivo de prévia (job {$job->id}): " . $e->getMessage());
        }

        // Apaga SPOOL e lista de CPFs, se existirem
        try {
            $diskName = config('facta_off.storage.reports_disk', 'public');
            $disk = Storage::disk($diskName);
            foreach (['spool_path', 'spool_cpfs_path'] as $field) {
                $p = $job->{$field};
                if ($p && $disk->exists($p)) {
                    $disk->delete($p);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[FGTS-OFF] Erro ao apagar spool (job {$job->id}): " . $e->getMessage());
        }

        $job->delete();

        return response()->noContent(); // 204
    }

    private function finalPrefix(): string
    {
        return (string) config('facta_off.storage.final_prefix', 'fgts-offline');
    }
}
