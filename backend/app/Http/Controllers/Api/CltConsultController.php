<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCltConsultJob;
use App\Jobs\FinalizeCltConsultReportJob;
use App\Models\CltConsultJob;
use App\Support\Cpf;
use App\Support\CltSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
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

        $reportsDiskName = (string) config('cltfacta.storage.reports_disk', 'local');
        $reportsDisk = Storage::disk($reportsDiskName);
        $spoolExists = $job->spool_path && $reportsDisk->exists($job->spool_path);

        return response()->json([
            'id' => $job->id,
            'title' => $job->title,
            'variant' => $job->variant,
            'status' => $job->status,
            'total_cpfs' => $job->total_cpfs,
            'success_count' => $job->success_count,
            'not_found_count' => $job->not_found_count,
            'fail_count' => $job->fail_count,
            'has_file' => (bool) $job->has_file,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
            'created_at' => $job->created_at,
            'paused_at' => $job->paused_at,

            // novo comportamento (igual FGTS)
            'preview_running' => in_array($job->status, ['pendente','em_progresso'], true) && $spoolExists,
            'spool_bytes' => $job->spool_bytes,
        ]);
    }

    public function store(Request $request)
    {
        $rules = [
            'title' => ['required', 'string', 'max:191'],
            'cpfs' => ['required'],
            'variant' => ['nullable', 'in:online,offline'],
        ];
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Os dados fornecidos são inválidos.',
                'errors' => $validator->errors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $data = $validator->validated();
        $variant = $data['variant'] ?? 'online';

        $job = CltConsultJob::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'status' => 'pendente',
            'variant' => $variant,
            'total_cpfs' => 0,
            'success_count' => 0,
            'not_found_count' => 0,
            'fail_count' => 0,
        ]);

        try {
            [$spoolPath, $cpfsPath, $spoolBytes, $cpfsCount] = $this->createInitialSpool(
                $job->id,
                $this->tokenizeCpfsLazy($data['cpfs'])
            );
        } catch (\Throwable $e) {
            $this->safeCleanupInit($job->id);
            $job->delete();
            Log::error("[CLT] Erro ao preparar spool (job {$job->id}): " . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Falha interna ao preparar arquivos do job.'], 500);
        }

        if ($cpfsCount === 0) {
            $this->safeCleanupPaths([$spoolPath, $cpfsPath]);
            $job->delete();
            return response()->json(['message' => 'Nenhum CPF normalizável encontrado.'], 422);
        }

        $job->update([
            'spool_path' => $spoolPath,
            'spool_cpfs_path' => $cpfsPath,
            'spool_bytes' => $spoolBytes,
        ]);

        ProcessCltConsultJob::dispatch($job->id);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
        ], Response::HTTP_ACCEPTED);
    }

    /** Estado “prévia” leve. Não enfileira nada (espelha o spool). */
    public function requestPreview(Request $request, int $id)
    {
        $job = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $disk = Storage::disk((string) config('cltfacta.storage.reports_disk', 'local'));
        $spoolExists = $job->spool_path && $disk->exists($job->spool_path);

        return response()->json([
            'queued' => false,
            'preview_running' => in_array($job->status, ['pendente','em_progresso'], true) && $spoolExists,
            'message' => 'Prévia espelha o spool no momento da leitura.',
        ], Response::HTTP_OK);
    }

    /** Streaming da PRÉVIA (CSV) com cabeçalho normalizado */
    public function downloadPreview(Request $request, int $id)
    {
        $job = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $disk = Storage::disk((string) config('cltfacta.storage.reports_disk', 'local'));

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

        $withBOM = (bool) env('CLT_CSV_BOM', true);
        $finalEol = strtoupper((string) config('cltfacta.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";

        return response()->streamDownload(function () use ($fh, $withBOM, $finalEol) {
            try {
                flock($fh, LOCK_SH);

                if ($withBOM) echo "\xEF\xBB\xBF";

                // trata possível BOM no spool
                $peek = fread($fh, 3);
                if ($peek !== "\xEF\xBB\xBF") {
                    fseek($fh, 0);
                }

                // descarta a 1ª linha do spool (cabeçalho original)
                fgets($fh);

                // escreve cabeçalho normalizado
                echo \App\Support\CltSchema::headerCsvLine(';') . $finalEol;

                // despeja o restante
                fpassthru($fh);
            } finally {
                flock($fh, LOCK_UN);
                if (is_resource($fh)) fclose($fh);
            }
        }, $filename, $headers);
    }

    /** Download do FINAL (CSV) */
    public function download(int $id)
    {
        $job = CltConsultJob::query()
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

        // CSV final já sai normalizado pelo job de finalização
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

    /** Cancelar job (apaga spool) — sem prévia */
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

        try {
            $disk = Storage::disk((string) config('cltfacta.storage.reports_disk', 'local'));
            foreach (['spool_path', 'spool_cpfs_path'] as $field) {
                $p = $job->{$field};
                if ($p && $disk->exists($p)) {
                    $disk->delete($p);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[CLT] Erro ao apagar spool no cancel (job {$job->id}): " . $e->getMessage());
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

    /** Excluir job + arquivos (final e spool). Bloqueia se pendente/em_progresso. */
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

        try {
            $diskName = (string) config('cltfacta.storage.reports_disk', 'local');
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

        return response()->noContent();
    }

    private function finalPrefix(): string
    {
        return (string) config('cltfacta.storage.final_prefix', 'clt-consulta');
    }

    /** Tokenizer lazy de CPFs — aceita string, array ou Traversable. */
    private function tokenizeCpfsLazy($cpfs): \Generator
    {
        if (is_string($cpfs)) {
            $tok = strtok($cpfs, " \t\n\r,;");
            while ($tok !== false) {
                yield $tok;
                $tok = strtok(" \t\n\r,;");
            }
            return;
        }
        if (is_array($cpfs)) {
            foreach ($cpfs as $t)
                yield $t;
            return;
        }
        if ($cpfs instanceof \Traversable) {
            foreach ($cpfs as $t)
                yield $t;
        }
    }

    /** Cria spool inicial + lista de CPFs (cabeçalho amigável) */
    private function createInitialSpool(int $jobId, iterable $allCpfs): array
    {
        $diskName = (string) config('cltfacta.storage.reports_disk', 'local');
        $disk = Storage::disk($diskName);

        $dirSpool = (string) (config('cltfacta.storage.dir_spool') ?? 'clt-spool');
        $finalPref = $this->finalPrefix();

        if (!$disk->exists($dirSpool)) {
            $disk->makeDirectory($dirSpool);
        }

        $spoolPath = "{$dirSpool}/{$finalPref}_{$jobId}.spool.csv";
        $cpfsPath = "{$dirSpool}/{$finalPref}_{$jobId}.cpfs.txt";

        $fp = fopen($disk->path($spoolPath), 'c+');
        if ($fp === false)
            throw new \RuntimeException("Não foi possível criar spool em {$spoolPath}");
        try {
            if (flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                // cabeçalho já normalizado (igual FGTS)
                fputcsv($fp, CltSchema::TITLES, ';');
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }

        $fp2 = fopen($disk->path($cpfsPath), 'c+');
        if ($fp2 === false)
            throw new \RuntimeException("Não foi possível criar cpfs em {$cpfsPath}");

        $count = 0;
        try {
            if (flock($fp2, LOCK_EX)) {
                ftruncate($fp2, 0);
                foreach ($allCpfs as $raw) {
                    $norm = Cpf::normalize((string) $raw);
                    if ($norm === null)
                        continue;
                    $digits = preg_replace('/\D+/', '', $norm);
                    if ($digits === '' || strlen($digits) !== 11)
                        continue;
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
        try {
            $bytes = (int) $disk->size($spoolPath);
        } catch (\Throwable) {
        }

        return [$spoolPath, $cpfsPath, $bytes, $count];
    }

    private function safeCleanupInit(int $jobId): void
    {
        try {
            $disk = Storage::disk((string) config('cltfacta.storage.reports_disk', 'local'));
            $dirSpool = (string) (config('cltfacta.storage.dir_spool') ?? 'clt-spool');
            $finalPref = $this->finalPrefix();
            foreach (["{$dirSpool}/{$finalPref}_{$jobId}.spool.csv", "{$dirSpool}/{$finalPref}_{$jobId}.cpfs.txt"] as $p) {
                if ($disk->exists($p))
                    $disk->delete($p);
            }
        } catch (\Throwable $e) {
            Log::warning("[CLT] Falha ao limpar após erro no createInitialSpool (job {$jobId}): " . $e->getMessage());
        }
    }

    private function safeCleanupPaths(array $relPaths): void
    {
        try {
            $disk = Storage::disk((string) config('cltfacta.storage.reports_disk', 'local'));
            foreach ($relPaths as $p) {
                if ($p && $disk->exists($p))
                    $disk->delete($p);
            }
        } catch (\Throwable $e) {
            Log::warning("[CLT] Erro limpando arquivos: " . $e->getMessage());
        }
    }
}
