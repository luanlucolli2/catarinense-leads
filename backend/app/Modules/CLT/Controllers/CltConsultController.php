<?php

namespace App\Modules\CLT\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CLT\Jobs\ProcessCltConsultJob;
use App\Modules\CLT\Models\CltConsultJob;
use App\Modules\CLT\Support\CltLog;
use App\Support\Cpf;
use App\Modules\CLT\Support\CltSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            'phase' => $job->phase,
            'phase2_total' => (int) ($job->phase2_total ?? 0),
            'phase2_attempt' => (int) ($job->phase2_attempt ?? 0),
            'phase2_aprovado_count' => (int) ($job->phase2_aprovado_count ?? 0),
            'phase2_nao_aprovado_count' => (int) ($job->phase2_nao_aprovado_count ?? 0),
            'total_cpfs' => $job->total_cpfs,
            'elegivel_count' => (int) ($job->elegivel_count ?? 0),
            'inelegivel_count' => (int) ($job->inelegivel_count ?? 0),
            'not_found_count' => $job->not_found_count,
            'fail_count' => $job->fail_count,
            'has_file' => (bool) $job->has_file,
            'started_at' => $job->started_at,
            'finished_at' => $job->finished_at,
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
            'phase2_aprovado_count' => 0,
            'phase2_nao_aprovado_count' => 0,
            'elegivel_count' => 0,
            'inelegivel_count' => 0,
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
            CltLog::error("[CLT] Erro ao preparar spool (job {$job->id}): " . $e->getMessage(), ['exception' => $e]);
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

        // ===== DISPATCH POR FILA SEPARADA =====
        $queue = $variant === 'offline'
            ? (string) config('cltfacta.job.queue_offline', 'clt-off')
            : (string) config('cltfacta.job.queue_online', 'clt-on');

        ProcessCltConsultJob::dispatch($job->id)->onQueue($queue);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
        ], Response::HTTP_ACCEPTED);
    }

    /** Estado “prévia” leve */
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
            'message' => 'Prévia espelha o spool e aplica progresso incremental da fase 2.',
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

        $withBOM = (bool) config('cltfacta.csv.embed_bom', true);
        $finalEol = strtoupper((string) config('cltfacta.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";
        $deltaMap = $this->loadPhase2DeltaMapForPreview($disk, $job->spool_path);
        $phase2Indexes = !empty($deltaMap) ? $this->phase2PreviewColumnIndexes() : [];

        return response()->streamDownload(function () use ($fh, $withBOM, $finalEol, $deltaMap, $phase2Indexes) {
            $out = @fopen('php://output', 'wb');
            try {
                if ($withBOM) echo "\xEF\xBB\xBF";

                // trata possível BOM no spool
                $peek = fread($fh, 3);
                if ($peek !== "\xEF\xBB\xBF") {
                    fseek($fh, 0);
                }

                // descarta a 1ª linha do spool (cabeçalho original)
                fgets($fh);

                // escreve cabeçalho normalizado
                $canWriteCsv = is_resource($out);
                if ($canWriteCsv) {
                    fwrite($out, CltSchema::headerCsvLine(';') . $finalEol);
                } else {
                    echo CltSchema::headerCsvLine(';') . $finalEol;
                }

                if (!empty($deltaMap) && $canWriteCsv) {
                    $lineNo = 0;
                    while (($csvRow = fgetcsv($fh, 0, ';')) !== false) {
                        $lineNo++;
                        if (isset($deltaMap[$lineNo]) && is_array($deltaMap[$lineNo])) {
                            $csvRow = $this->applyPhase2PatchToCsvRow($csvRow, $deltaMap[$lineNo], $phase2Indexes);
                        }

                        fputcsv($out, $csvRow, ';', '"', '\\', $finalEol);
                    }
                    return;
                }

                // Não segura lock aqui para não bloquear o writer do job.
                while (!feof($fh)) {
                    $chunk = fread($fh, 1024 * 256);
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                }
            } finally {
                if (is_resource($out)) fclose($out);
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

        $withBOM = false; // final já vem normalizado pelo job de finalização

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
            'phase' => null,
            'canceled_at' => now(),
            'cancel_reason' => $data['reason'] ?? null,
            'finished_at' => now(),
        ]);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
            'canceled_at' => $job->canceled_at,
            'cancel_reason' => $job->cancel_reason,
        ]);
    }

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
            CltLog::warning("[CLT] Erro ao apagar arquivo final (job {$job->id}): " . $e->getMessage());
        }

        try {
            $diskName = (string) config('cltfacta.storage.reports_disk', 'local');
            $disk = Storage::disk($diskName);
            $this->deleteSpoolArtifacts($disk, $job->spool_path, $job->spool_cpfs_path);
        } catch (\Throwable $e) {
            CltLog::warning("[CLT] Erro ao apagar spool (job {$job->id}): " . $e->getMessage());
        }

        $job->delete();

        return response()->noContent();
    }

    public function httpCounters(int $id)
    {
        $job = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if (($job->variant ?? 'online') !== 'online') {
            return response()->json([
                'message' => 'Contadores HTTP disponíveis apenas para jobs CLT online.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $counterFields = [
            'request_count',
            'response_count',
            'status_2xx_count',
            'status_4xx_count',
            'status_5xx_count',
            'status_other_count',
            'exception_count',
            'timeout_count',
            'connection_exception_count',
            'no_response_count',
        ];

        $summary = array_fill_keys($counterFields, 0);

        if (!Schema::hasTable('clt_job_http_counters')) {
            return response()->json([
                'id' => $job->id,
                'title' => $job->title,
                'variant' => $job->variant,
                'status' => $job->status,
                'available' => false,
                'summary' => $summary,
                'checks' => [
                    'request_balance_ok' => true,
                    'status_balance_ok' => true,
                ],
                'endpoints' => [],
                'updated_at' => now()->toIso8601String(),
            ]);
        }

        $rows = DB::table('clt_job_http_counters')
            ->where('job_id', $job->id)
            ->orderByDesc('request_count')
            ->orderBy('endpoint')
            ->get([
                'endpoint',
                'request_count',
                'response_count',
                'status_2xx_count',
                'status_4xx_count',
                'status_5xx_count',
                'status_other_count',
                'exception_count',
                'timeout_count',
                'connection_exception_count',
                'no_response_count',
                'updated_at',
            ]);

        $endpoints = [];
        $lastUpdatedAt = null;

        foreach ($rows as $row) {
            $entry = [
                'endpoint' => (string) ($row->endpoint ?? ''),
                'request_count' => max(0, (int) ($row->request_count ?? 0)),
                'response_count' => max(0, (int) ($row->response_count ?? 0)),
                'status_2xx_count' => max(0, (int) ($row->status_2xx_count ?? 0)),
                'status_4xx_count' => max(0, (int) ($row->status_4xx_count ?? 0)),
                'status_5xx_count' => max(0, (int) ($row->status_5xx_count ?? 0)),
                'status_other_count' => max(0, (int) ($row->status_other_count ?? 0)),
                'exception_count' => max(0, (int) ($row->exception_count ?? 0)),
                'timeout_count' => max(0, (int) ($row->timeout_count ?? 0)),
                'connection_exception_count' => max(0, (int) ($row->connection_exception_count ?? 0)),
                'no_response_count' => max(0, (int) ($row->no_response_count ?? 0)),
            ];

            foreach ($counterFields as $field) {
                $summary[$field] += (int) $entry[$field];
            }

            $rowUpdatedAt = isset($row->updated_at) ? strtotime((string) $row->updated_at) : false;
            if ($rowUpdatedAt !== false && ($lastUpdatedAt === null || $rowUpdatedAt > $lastUpdatedAt)) {
                $lastUpdatedAt = $rowUpdatedAt;
            }

            $endpoints[] = $entry;
        }

        $requestBalanceOk = $summary['request_count'] === ($summary['response_count'] + $summary['no_response_count']);
        $statusBalanceOk = $summary['response_count'] === (
            $summary['status_2xx_count']
            + $summary['status_4xx_count']
            + $summary['status_5xx_count']
            + $summary['status_other_count']
        );

        return response()->json([
            'id' => $job->id,
            'title' => $job->title,
            'variant' => $job->variant,
            'status' => $job->status,
            'available' => true,
            'summary' => $summary,
            'checks' => [
                'request_balance_ok' => $requestBalanceOk,
                'status_balance_ok' => $statusBalanceOk,
            ],
            'endpoints' => $endpoints,
            'updated_at' => $lastUpdatedAt !== null ? gmdate('c', $lastUpdatedAt) : now()->toIso8601String(),
        ]);
    }

    private function finalPrefix(): string
    {
        return (string) config('cltfacta.storage.final_prefix', 'clt-consulta');
    }

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
            $spoolPath = "{$dirSpool}/{$finalPref}_{$jobId}.spool.csv";
            foreach ([
                $spoolPath,
                "{$dirSpool}/{$finalPref}_{$jobId}.cpfs.txt",
                "{$spoolPath}.phase2.tmp",
                "{$spoolPath}.phase2.delta.ndjson",
            ] as $p) {
                if ($disk->exists($p))
                    $disk->delete($p);
            }
        } catch (\Throwable $e) {
            CltLog::warning("[CLT] Falha ao limpar após erro no createInitialSpool (job {$jobId}): " . $e->getMessage());
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
            CltLog::warning("[CLT] Erro limpando arquivos: " . $e->getMessage());
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadPhase2DeltaMapForPreview($disk, string $spoolPath): array
    {
        $deltaPath = "{$spoolPath}.phase2.delta.ndjson";
        if (!$disk->exists($deltaPath)) {
            return [];
        }

        $deltaReal = $disk->path($deltaPath);
        $fh = @fopen($deltaReal, 'rb');
        if ($fh === false) {
            return [];
        }

        $map = [];
        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $lineNo = (int) ($decoded['l'] ?? 0);
                if ($lineNo <= 0) {
                    continue;
                }

                $map[$lineNo] = [
                    'ap' => array_key_exists('ap', $decoded) ? $decoded['ap'] : null,
                    'mg' => array_key_exists('mg', $decoded) ? $decoded['mg'] : null,
                    'vm' => array_key_exists('vm', $decoded) ? $decoded['vm'] : null,
                    'pm' => array_key_exists('pm', $decoded) ? $decoded['pm'] : null,
                ];
            }
        } finally {
            @fclose($fh);
        }

        return $map;
    }

    /**
     * @return array<string,int>
     */
    private function phase2PreviewColumnIndexes(): array
    {
        static $indexes = null;
        if (is_array($indexes)) {
            return $indexes;
        }

        $lookup = array_flip(CltSchema::COLS);
        $indexes = [];
        foreach (
            [
                'politicaCreditoAprovado',
                'politicaCreditoMensagem',
                'politicaCreditoValorMaximoDisponivel',
                'politicaCreditoPrazoMaximoDisponivel',
            ] as $col
        ) {
            if (array_key_exists($col, $lookup)) {
                $indexes[$col] = (int) $lookup[$col];
            }
        }

        return $indexes;
    }

    /**
     * @param array<int,mixed> $csvRow
     * @param array<string,mixed> $patch
     * @param array<string,int> $indexes
     * @return array<int,mixed>
     */
    private function applyPhase2PatchToCsvRow(array $csvRow, array $patch, array $indexes): array
    {
        $colsCount = count(CltSchema::COLS);
        if (count($csvRow) < $colsCount) {
            $csvRow = array_pad($csvRow, $colsCount, null);
        }

        if (isset($indexes['politicaCreditoAprovado'])) {
            $csvRow[$indexes['politicaCreditoAprovado']] = $patch['ap'] ?? null;
        }
        if (isset($indexes['politicaCreditoMensagem'])) {
            $csvRow[$indexes['politicaCreditoMensagem']] = $patch['mg'] ?? null;
        }
        if (isset($indexes['politicaCreditoValorMaximoDisponivel'])) {
            $csvRow[$indexes['politicaCreditoValorMaximoDisponivel']] = $patch['vm'] ?? null;
        }
        if (isset($indexes['politicaCreditoPrazoMaximoDisponivel'])) {
            $csvRow[$indexes['politicaCreditoPrazoMaximoDisponivel']] = $patch['pm'] ?? null;
        }

        return $csvRow;
    }

    private function deleteSpoolArtifacts($disk, ?string $spoolPath, ?string $cpfsPath): void
    {
        $targets = [
            $spoolPath,
            $cpfsPath,
            $spoolPath ? "{$spoolPath}.phase2.tmp" : null,
            $spoolPath ? "{$spoolPath}.phase2.delta.ndjson" : null,
        ];

        foreach ($targets as $target) {
            if ($target && $disk->exists($target)) {
                $disk->delete($target);
            }
        }
    }
}
