<?php

namespace App\Http\Controllers\Api;

use App\Exports\CltConsultExport;
use App\Http\Controllers\Controller;
use App\Models\CltConsultJob;
use App\Support\Cpf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class CltConsultController extends Controller
{
    public function index()
    {
        $jobs = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->paginate(15);

        return response()->json($jobs);
    }

    public function show(int $id)
    {
        $job = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        return response()->json([
            'id' => $job->id,
            'title' => $job->title,
            'status' => $job->status,
            'total_cpfs' => $job->total_cpfs,
            'success_count' => $job->success_count,
            'fail_count' => $job->fail_count,
            'not_found_count' => $job->not_found_count,
            'has_file' => $job->has_file,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'created_at' => $job->created_at,
            // prévia
            'has_preview' => $job->has_preview,
            'preview_updated_at' => $job->preview_updated_at,
            // telemetria do spool (opcional para o front)
            'spool_bytes' => $job->spool_bytes,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:191'],
            'cpfs' => ['required'],
        ]);

        $cpfs = $data['cpfs'];
        $tokens = is_string($cpfs)
            ? (preg_split('/[\s,;]+/u', $cpfs, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            : (is_array($cpfs) ? $cpfs : []);

        $valid = [];
        $invalid = [];

        foreach ($tokens as $t) {
            $norm = Cpf::normalize((string) $t);
            if ($norm === null) {
                continue;
            }
            if (Cpf::isValid($norm)) {
                $valid[] = $norm;
            } else {
                $invalid[] = $norm;
            }
        }

        $valid = array_values(array_unique($valid));
        $invalid = array_values(array_diff(array_unique($invalid), $valid));

        if ((count($valid) + count($invalid)) === 0) {
            return response()->json([
                'message' => 'Nenhum CPF válido ou normalizável encontrado (8–11 dígitos; 8–10 serão completados com zeros à esquerda).'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $job = CltConsultJob::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'status' => 'pendente',
            'total_cpfs' => count($valid) + count($invalid),
            'success_count' => 0,
            'fail_count' => 0,
            'not_found_count' => 0,
            // prévia/spool serão setados pelo Job
            // preview_dirty -> default false via migration
        ]);

        // ✔️ nova assinatura do Job: (int $jobId, array $cpfs, array $invalidCpfs = [])
        \App\Jobs\ProcessCltConsultJob::dispatch($job->id, $valid, $invalid);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
        ], Response::HTTP_ACCEPTED);
    }

    /** Download do relatório FINAL */
    public function download(int $id)
    {
        $job = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if (empty($job->file_disk) || empty($job->file_path)) {
            return response()->json(['message' => 'Relatório ainda não disponível.'], 409);
        }

        $filename = $job->file_name ?: "clt-consulta-{$job->id}.xlsx";

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
        $job = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        // precisa ter spool criado pelo worker
        if (empty($job->spool_path) || empty($job->spool_cpfs_path)) {
            return response()->json(['message' => 'Prévia não disponível ainda.'], Response::HTTP_CONFLICT);
        }

        $diskName = (string) config('cltfacta.storage.reports_disk');
        $disk = Storage::disk($diskName);

        if (!$disk->exists($job->spool_path) || !$disk->exists($job->spool_cpfs_path)) {
            return response()->json(['message' => 'Prévia não disponível (spool ausente).'], Response::HTTP_CONFLICT);
        }

        $dirPreviews = (string) config('cltfacta.storage.dir_previews', 'clt-previews');
        if (!$disk->exists($dirPreviews)) {
            $disk->makeDirectory($dirPreviews);
        }

        $fileName = ($job->preview_name ?: "clt-consulta_{$job->id}_preview.xlsx");
        $tmpName = preg_replace('/\.xlsx$/', '.tmp.xlsx', $fileName);
        $path = "{$dirPreviews}/{$fileName}";
        $tmpPath = "{$dirPreviews}/{$tmpName}";

        // Lock simples para evitar dupla geração concorrente
        $lock = Cache::lock("clt_preview_{$job->id}", 30);
        try {
            // espera até 10s por outro processo
            $lock->block(10);

            // 🔄 RECARREGA o job e recalcula a necessidade de refresh dentro do lock
            $job->refresh();

            $needRefresh = (bool) $request->boolean('refresh', false)
                || empty($job->preview_disk) || empty($job->preview_path)
                || !$disk->exists($job->preview_path)
                || (bool) $job->preview_dirty;

            if ($needRefresh) {
                $spoolReal = $disk->path($job->spool_path);
                $cpfsReal = $disk->path($job->spool_cpfs_path);

                $iteratorFactory = function () use ($spoolReal, $cpfsReal): \Generator {
                    $done = [];

                    // 1) linhas já processadas no spool
                    $fh = fopen($spoolReal, 'r');
                    if ($fh !== false) {
                        try {
                            flock($fh, LOCK_SH);
                            $header = fgetcsv($fh, 0, ';'); // pula cabeçalho
                            while (($data = fgetcsv($fh, 0, ';')) !== false) {
                                $assoc = [];
                                foreach (CltConsultExport::COLS as $i => $key) {
                                    $assoc[$key] = $data[$i] ?? null;
                                }
                                $cpf = (string) ($assoc['cpf'] ?? '');
                                if ($cpf !== '') {
                                    $done[$cpf] = true;
                                }
                                yield $assoc;
                            }
                        } finally {
                            flock($fh, LOCK_UN);
                            fclose($fh);
                        }
                    }

                    // 2) pendentes = CPFs originais que ainda não apareceram no spool
                    $fh2 = fopen($cpfsReal, 'r');
                    if ($fh2 !== false) {
                        try {
                            flock($fh2, LOCK_SH);
                            while (($line = fgets($fh2)) !== false) {
                                $cpf = trim($line);
                                if ($cpf === '' || isset($done[$cpf]))
                                    continue;

                                $row = array_fill_keys(CltConsultExport::COLS, null);
                                $row['cpf'] = $cpf;
                                $row['numeroVinculos'] = 0;
                                $row['mensagem'] = 'Em andamento';
                                yield $row;
                            }
                        } finally {
                            flock($fh2, LOCK_UN);
                            fclose($fh2);
                        }
                    }
                };

                $export = CltConsultExport::fromGenerator($iteratorFactory);
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
        } catch (\Throwable $e) {
            Log::warning("[CLT] Erro durante geração da prévia (job {$job->id}): " . $e->getMessage());
            return response()->json(['message' => 'Falha ao gerar prévia.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        } finally {
            optional($lock)->release();
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

    /** ✅ Cancelar job (apaga a PRÉVIA imediatamente) */
    public function cancel(Request $request, int $id)
    {
        $job = CltConsultJob::query()
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
            Log::warning("[CLT] Erro ao apagar prévia no cancel (job {$job->id}): " . $e->getMessage());
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

    /** ✅ Excluir job + arquivos (final, prévia e spool). Bloqueia se pendente/em_progresso. */
    public function destroy(int $id)
    {
        $job = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if (in_array($job->status, ['pendente', 'em_progresso'], true)) {
            return response()->json([
                'message' => 'Não é possível excluir enquanto o job está em andamento. Cancele primeiro.',
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
            Log::warning("[CLT] Erro ao apagar arquivo final (job {$job->id}): " . $e->getMessage());
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
            Log::warning("[CLT] Erro ao apagar arquivo de prévia (job {$job->id}): " . $e->getMessage());
        }

        // Apaga SPOOL e lista de CPFs, se existirem
        try {
            $diskName = (string) config('cltfacta.storage.reports_disk');
            $disk = Storage::disk($diskName);
            foreach (['spool_path', 'spool_cpfs_path'] as $field) {
                $p = $job->{$field};
                if ($p && $disk->exists($p)) {
                    $disk->delete($p);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[CLT] Erro ao apagar spool (job {$job->id}): " . $e->getMessage());
        }

        $job->delete();

        return response()->noContent(); // 204
    }
}
