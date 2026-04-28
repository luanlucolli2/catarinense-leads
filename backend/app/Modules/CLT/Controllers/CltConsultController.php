<?php

namespace App\Modules\CLT\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CLT\Jobs\DispatchCltConsultJob;
use App\Modules\CLT\Jobs\ProcessCltConsultJob;
use App\Modules\CLT\Models\CltConsultJob;
use App\Modules\CLT\Support\CltLog;
use App\Modules\CLT\Support\CltSchema;
use App\Modules\CLT\Support\CltSpool;
use App\Modules\CLT\Support\CltVariant;
use App\Support\Cpf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class CltConsultController extends Controller
{
    public function index(Request $request)
    {
        $data = Validator::make($request->query(), [
            'status' => ['nullable', 'in:agendado,pendente,em_progresso,pausado,concluido,falhou,cancelado,todos'],
            'variant' => ['nullable', 'in:online,offline,hybrid,on,off,hyb,todos'],
        ])->validate();

        $jobsQuery = CltConsultJob::query();

        $status = $data['status'] ?? null;
        if (is_string($status) && $status !== '' && $status !== 'todos') {
            $jobsQuery->where('status', $status);
        }

        $variant = $data['variant'] ?? null;
        if (is_string($variant) && $variant !== '' && $variant !== 'todos') {
            $variantNormalized = CltVariant::normalizeFilter($variant);

            if ($variantNormalized === 'online') {
                $jobsQuery->where(function ($q) {
                    $q->where('variant', 'online')
                        ->orWhereNull('variant');
                });
            } else {
                $jobsQuery->where('variant', $variantNormalized);
            }
        }

        $jobs = $jobsQuery
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
        $spoolHasDataRows = $spoolExists && CltSpool::hasDataRows($reportsDisk, $job->spool_path);

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
            'paused_at' => $job->paused_at,
            'canceled_at' => $job->canceled_at,
            'cancel_reason' => $job->cancel_reason,
            'scheduled_for' => $job->scheduled_for,
            'created_at' => $job->created_at,
            'preview_running' => in_array($job->status, ['pendente','em_progresso','pausado','cancelado'], true) && $spoolHasDataRows,
            'spool_bytes' => $job->spool_bytes,
        ]);
    }

    public function store(Request $request)
    {
        $rules = [
            'title' => ['required', 'string', 'max:191'],
            'cpfs' => ['required'],
            'variant' => ['nullable', 'in:online,offline,hybrid'],
            'run_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
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
        $timezone = $data['timezone'] ?? 'America/Sao_Paulo';
        $runAt = isset($data['run_at']) ? Carbon::parse($data['run_at'], $timezone) : null;
        $scheduledFor = $runAt && $runAt->greaterThan(Carbon::now($timezone))
            ? $runAt->clone()->setTimezone('UTC')
            : null;

        $job = CltConsultJob::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'status' => $scheduledFor ? 'agendado' : 'pendente',
            'variant' => $variant,
            'total_cpfs' => 0,
            'phase2_aprovado_count' => 0,
            'phase2_nao_aprovado_count' => 0,
            'elegivel_count' => 0,
            'inelegivel_count' => 0,
            'not_found_count' => 0,
            'fail_count' => 0,
            'scheduled_for' => $scheduledFor,
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

        if ($job->status === 'pendente') {
            $queue = CltVariant::resolvePhaseOneQueue($variant);
            ProcessCltConsultJob::dispatch($job->id, 'phase1')->onQueue($queue);
        }

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
            'scheduled_for' => $job->scheduled_for,
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
        $spoolHasDataRows = $spoolExists && CltSpool::hasDataRows($disk, $job->spool_path);

        return response()->json([
            'queued' => false,
            'preview_running' => in_array($job->status, ['pendente','em_progresso','pausado','cancelado'], true) && $spoolHasDataRows,
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

        if (!CltSpool::hasDataRows($disk, $job->spool_path)) {
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

        $withBOM = (bool) config('cltfacta.csv.embed_bom', true);
        $finalEol = strtoupper((string) config('cltfacta.csv.final_eol', 'LF')) === 'CRLF' ? "\r\n" : "\n";
        $deltaMap = $this->shouldApplyPhase2DeltaForPreview($job)
            ? $this->loadPhase2DeltaMapForPreview($disk, $job->spool_path)
            : [];
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
                $this->readCsvRowWithSharedLock($fh);

                // escreve cabeçalho normalizado
                $canWriteCsv = is_resource($out);
                if ($canWriteCsv) {
                    fwrite($out, CltSchema::headerCsvLine(';') . $finalEol);
                } else {
                    echo CltSchema::headerCsvLine(';') . $finalEol;
                }

                if (!$canWriteCsv) {
                    return;
                }

                $lineNo = 0;
                while (($csvRow = $this->readCsvRowWithSharedLock($fh)) !== false) {
                    $lineNo++;
                    if (!empty($deltaMap) && isset($deltaMap[$lineNo]) && is_array($deltaMap[$lineNo])) {
                        $csvRow = $this->applyPhase2PatchToCsvRow($csvRow, $deltaMap[$lineNo], $phase2Indexes);
                    }

                    $csvRow = CltSchema::normalizeOrderedRowForCsv($csvRow);
                    fputcsv($out, $csvRow, ';', '"', '\\', $finalEol);
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

        if (in_array($job->status, ['agendado', 'pendente', 'pausado'], true)) {
            $this->cancelIdleJob($job, $data['reason'] ?? null);

            return response()->json([
                'id' => $job->id,
                'status' => $job->status,
                'phase' => $job->phase,
                'finished_at' => $job->finished_at,
                'canceled_at' => $job->canceled_at,
                'cancel_reason' => $job->cancel_reason,
            ]);
        }

        $job->update([
            'status' => 'cancelado',
            'canceled_at' => now(),
            'cancel_reason' => $data['reason'] ?? null,
            'paused_at' => null,
            'finished_at' => null,
        ]);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
            'canceled_at' => $job->canceled_at,
            'cancel_reason' => $job->cancel_reason,
        ]);
    }

    public function pause(int $id)
    {
        $job = CltConsultJob::query()
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
        $job = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if ($job->status !== 'pausado') {
            return response()->json([
                'message' => 'Apenas jobs pausados podem ser retomados.',
                'status' => $job->status,
            ], Response::HTTP_CONFLICT);
        }

        $disk = Storage::disk((string) config('cltfacta.storage.reports_disk', 'local'));
        if (empty($job->spool_path) || !$disk->exists($job->spool_path)) {
            return response()->json([
                'message' => 'Spool indisponível para retomar o job.',
            ], Response::HTTP_CONFLICT);
        }

        $phase = $job->phase === 'fase_2' && CltVariant::supportsCreditPhaseTwo($job->variant)
            ? 'phase2'
            : 'phase1';

        if ($phase === 'phase1' && (empty($job->spool_cpfs_path) || !$disk->exists($job->spool_cpfs_path))) {
            return response()->json([
                'message' => 'Arquivo de CPFs indisponível para retomar a fase 1.',
            ], Response::HTTP_CONFLICT);
        }

        $job->update([
            'status' => 'pendente',
            'paused_at' => null,
            'finished_at' => null,
            'canceled_at' => null,
            'cancel_reason' => null,
        ]);

        $queue = $phase === 'phase2'
            ? (string) config('cltfacta.job.queue_phase2', 'clt-valida-politica-cred')
            : CltVariant::resolvePhaseOneQueue($job->variant);

        DispatchCltConsultJob::dispatch($job->id, $phase)
            ->delay(now()->addSeconds(2))
            ->onQueue($queue);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
        ], Response::HTTP_ACCEPTED);
    }

    public function rerunPhase2(int $id)
    {
        $job = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        if (!CltVariant::supportsCreditPhaseTwo($job->variant)) {
            return response()->json([
                'message' => 'Reprocessamento da fase 2 disponível apenas para jobs online ou híbridos.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $canRerunFromFinishedCancel = $job->status === 'cancelado'
            && $job->finished_at !== null
            && !empty($job->spool_path);

        if ($job->status !== 'concluido' && !$canRerunFromFinishedCancel) {
            return response()->json([
                'message' => 'A fase 2 só pode ser reprocessada quando o job estiver concluído ou cancelado com spool preservado.',
                'status' => $job->status,
            ], Response::HTTP_CONFLICT);
        }

        $reportsDiskName = (string) config('cltfacta.storage.reports_disk', 'local');
        $reportsDisk = Storage::disk($reportsDiskName);
        $dirSpool = (string) (config('cltfacta.storage.dir_spool') ?? 'clt-spool');
        if (!$reportsDisk->exists($dirSpool)) {
            $reportsDisk->makeDirectory($dirSpool);
        }

        $spoolPath = "{$dirSpool}/{$this->finalPrefix()}_{$job->id}.spool.csv";

        try {
            if ($canRerunFromFinishedCancel) {
                $spoolPath = (string) $job->spool_path;
                if (!$reportsDisk->exists($spoolPath)) {
                    return response()->json([
                        'message' => 'Spool preservado da fase 2 não encontrado.',
                    ], Response::HTTP_NOT_FOUND);
                }

                $this->deletePhaseTwoAuxiliaryArtifacts($reportsDisk, $spoolPath, $job->spool_cpfs_path);
                $spoolBytes = $this->resetPhase2ColumnsInExistingSpool(
                    $reportsDisk,
                    $spoolPath
                );
            } else {
                if (empty($job->file_disk) || empty($job->file_path)) {
                    return response()->json([
                        'message' => 'CSV final indisponível para reconstruir o spool da fase 2.',
                    ], Response::HTTP_CONFLICT);
                }

                $sourceDisk = Storage::disk($job->file_disk);
                if (!$sourceDisk->exists($job->file_path)) {
                    return response()->json([
                        'message' => 'Arquivo final não encontrado.',
                    ], Response::HTTP_NOT_FOUND);
                }

                $this->deleteSpoolArtifacts($reportsDisk, $job->spool_path, $job->spool_cpfs_path);
                $this->deleteSpoolArtifacts($reportsDisk, $spoolPath, null);
                $spoolBytes = $this->rebuildPhase2SpoolFromFinalCsv(
                    $sourceDisk,
                    (string) $job->file_path,
                    $reportsDisk,
                    $spoolPath
                );
            }
        } catch (\Throwable $e) {
            CltLog::error("[CLT] Falha ao preparar rerun da fase 2 (job {$job->id}): " . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'Não foi possível preparar o reprocessamento da fase 2.',
            ], 500);
        }

        if (Schema::hasTable('clt_job_http_counters')) {
            DB::table('clt_job_http_counters')->where('job_id', $job->id)->delete();
        }

        $job->update([
            'status' => 'pendente',
            'phase' => 'fase_2',
            'phase2_total' => 0,
            'phase2_attempt' => 0,
            'phase2_aprovado_count' => 0,
            'phase2_nao_aprovado_count' => 0,
            'spool_path' => $spoolPath,
            'spool_cpfs_path' => null,
            'spool_bytes' => $spoolBytes,
            'started_at' => now(),
            'finished_at' => null,
            'canceled_at' => null,
            'cancel_reason' => null,
        ]);

        $queue = (string) config('cltfacta.job.queue_phase2', 'clt-valida-politica-cred');
        ProcessCltConsultJob::dispatch($job->id, 'phase2')->onQueue($queue);

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
            'phase' => $job->phase,
            'phase2_total' => (int) ($job->phase2_total ?? 0),
            'phase2_attempt' => (int) ($job->phase2_attempt ?? 0),
            'phase2_aprovado_count' => (int) ($job->phase2_aprovado_count ?? 0),
            'phase2_nao_aprovado_count' => (int) ($job->phase2_nao_aprovado_count ?? 0),
        ], Response::HTTP_ACCEPTED);
    }

    public function destroy(int $id)
    {
        $job = CltConsultJob::query()
            ->where('user_id', Auth::id())
            ->findOrFail($id);

        $cancelCleanupInProgress = $job->status === 'cancelado'
            && $job->finished_at === null;

        if (in_array($job->status, ['agendado', 'pendente', 'em_progresso', 'pausado'], true) || $cancelCleanupInProgress) {
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

        if (!CltVariant::supportsCreditPhaseTwo($job->variant)) {
            return response()->json([
                'message' => 'Contadores HTTP disponíveis apenas para jobs CLT online ou híbridos.',
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
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException("Não foi possível bloquear spool em {$spoolPath}");
            }

            try {
                if (!ftruncate($fp, 0)) {
                    throw new \RuntimeException("Não foi possível truncar spool em {$spoolPath}");
                }
                $this->writeCsvRowOrFail($fp, CltSchema::TITLES, "spool inicial {$spoolPath}");
                if (!fflush($fp)) {
                    throw new \RuntimeException("Não foi possível sincronizar spool em {$spoolPath}");
                }
            } finally {
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
            if (!flock($fp2, LOCK_EX)) {
                throw new \RuntimeException("Não foi possível bloquear arquivo de CPFs em {$cpfsPath}");
            }

            try {
                if (!ftruncate($fp2, 0)) {
                    throw new \RuntimeException("Não foi possível truncar arquivo de CPFs em {$cpfsPath}");
                }
                foreach ($allCpfs as $raw) {
                    $norm = Cpf::normalize((string) $raw);
                    if ($norm === null)
                        continue;
                    $digits = preg_replace('/\D+/', '', $norm);
                    if ($digits === '' || strlen($digits) !== 11)
                        continue;
                    $this->writeAllOrFail($fp2, $digits . "\n", "arquivo de CPFs {$cpfsPath}");
                    $count++;
                }
                if (!fflush($fp2)) {
                    throw new \RuntimeException("Não foi possível sincronizar arquivo de CPFs em {$cpfsPath}");
                }
            } finally {
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
            $targets = [
                $spoolPath,
                "{$dirSpool}/{$finalPref}_{$jobId}.cpfs.txt",
                "{$spoolPath}.phase2.tmp",
                "{$spoolPath}.phase2.delta.ndjson",
                "{$spoolPath}.phase2.pending.ndjson",
                "{$spoolPath}.phase2.pending.ndjson.next",
            ];
            $maxAttempts = max(1, (int) config('cltfacta.credit_worker.phase2_max_attempts', 3));
            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $targets[] = "{$spoolPath}.phase2.delta.a{$attempt}.ndjson";
            }

            foreach ($targets as $p) {
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

    private function rebuildPhase2SpoolFromFinalCsv($sourceDisk, string $sourcePath, $targetDisk, string $targetPath): int
    {
        $in = $sourceDisk->readStream($sourcePath);
        if ($in === false) {
            throw new \RuntimeException("Falha ao abrir CSV final de origem: {$sourcePath}");
        }

        $out = @fopen($targetDisk->path($targetPath), 'c+');
        if ($out === false) {
            if (is_resource($in)) {
                @fclose($in);
            }
            throw new \RuntimeException("Falha ao abrir spool de destino para rerun: {$targetPath}");
        }

        $phase2Indexes = $this->phase2ColumnsIndexesForReset();
        $colsCount = count(CltSchema::COLS);

        try {
            if (!@flock($out, LOCK_EX)) {
                throw new \RuntimeException("Não foi possível bloquear spool de destino para rerun.");
            }

            if (!@ftruncate($out, 0)) {
                throw new \RuntimeException("Não foi possível truncar spool de destino para rerun.");
            }
            $header = @fgetcsv($in, 0, ';');
            if ($header === false) {
                throw new \RuntimeException("CSV final vazio, impossível reconstruir spool de rerun.");
            }

            $this->writeCsvRowOrFail($out, CltSchema::TITLES, "spool de rerun {$targetPath}");

            while (($row = @fgetcsv($in, 0, ';')) !== false) {
                if (count($row) < $colsCount) {
                    $row = array_pad($row, $colsCount, null);
                } elseif (count($row) > $colsCount) {
                    $row = array_slice($row, 0, $colsCount);
                }

                foreach ($phase2Indexes as $idx) {
                    $row[$idx] = null;
                }

                $row = CltSchema::normalizeOrderedRowForCsv($row);
                $this->writeCsvRowOrFail($out, $row, "spool de rerun {$targetPath}");
            }

            if (!@fflush($out)) {
                throw new \RuntimeException("Falha ao sincronizar spool de rerun em disco.");
            }
            @flock($out, LOCK_UN);
        } finally {
            if (is_resource($in)) {
                @fclose($in);
            }
            if (is_resource($out)) {
                @fclose($out);
            }
        }

        try {
            return (int) $targetDisk->size($targetPath);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<int,int>
     */
    private function phase2ColumnsIndexesForReset(): array
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
                'politicaCreditoTabelaAprovada',
                'politicaCreditoDataConsulta',
            ] as $col
        ) {
            if (array_key_exists($col, $lookup)) {
                $indexes[] = (int) $lookup[$col];
            }
        }

        return $indexes;
    }

    private function shouldApplyPhase2DeltaForPreview(CltConsultJob $job): bool
    {
        return CltVariant::supportsCreditPhaseTwo($job->variant)
            && in_array($job->status, ['pendente', 'em_progresso', 'pausado', 'cancelado', 'falhou'], true)
            && !empty($job->spool_path);
    }

    /**
     * @return array<int,array<int,mixed>>
     */
    private function loadPhase2DeltaMapForPreview($disk, string $spoolPath): array
    {
        $deltaPath = "{$spoolPath}.phase2.delta.ndjson";
        if (!$disk->exists($deltaPath)) {
            return [];
        }

        $deltaReal = $disk->path($deltaPath);
        $maxBytes = max(0, (int) config('cltfacta.preview.phase2_delta_preview_max_bytes', 8388608));
        if ($maxBytes > 0) {
            $deltaBytes = @filesize($deltaReal);
            if (is_int($deltaBytes) && $deltaBytes > $maxBytes) {
                return [];
            }
        }

        $fh = @fopen($deltaReal, 'rb');
        if ($fh === false) {
            return [];
        }

        $maxRows = max(0, (int) config('cltfacta.preview.phase2_delta_preview_max_rows', 60000));
        $map = [];
        $mapRows = 0;
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

                $isNewLinePatch = !isset($map[$lineNo]);
                $map[$lineNo] = [
                    0 => array_key_exists('ap', $decoded) ? $decoded['ap'] : null,
                    1 => array_key_exists('mg', $decoded) ? $decoded['mg'] : null,
                    2 => array_key_exists('vm', $decoded) ? $decoded['vm'] : null,
                    3 => array_key_exists('pm', $decoded) ? $decoded['pm'] : null,
                    4 => array_key_exists('ta', $decoded) ? $decoded['ta'] : null,
                    5 => array_key_exists('dc', $decoded) ? $decoded['dc'] : null,
                ];
                if ($isNewLinePatch) {
                    $mapRows++;
                }

                if ($maxRows > 0 && $mapRows > $maxRows) {
                    return [];
                }
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
                'politicaCreditoTabelaAprovada',
                'politicaCreditoDataConsulta',
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
     * @param array<int,mixed> $patch
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
            $csvRow[$indexes['politicaCreditoAprovado']] = $patch[0] ?? null;
        }
        if (isset($indexes['politicaCreditoMensagem'])) {
            $csvRow[$indexes['politicaCreditoMensagem']] = $patch[1] ?? null;
        }
        if (isset($indexes['politicaCreditoValorMaximoDisponivel'])) {
            $csvRow[$indexes['politicaCreditoValorMaximoDisponivel']] = $patch[2] ?? null;
        }
        if (isset($indexes['politicaCreditoPrazoMaximoDisponivel'])) {
            $csvRow[$indexes['politicaCreditoPrazoMaximoDisponivel']] = $patch[3] ?? null;
        }
        if (isset($indexes['politicaCreditoTabelaAprovada'])) {
            $csvRow[$indexes['politicaCreditoTabelaAprovada']] = $patch[4] ?? null;
        }
        if (isset($indexes['politicaCreditoDataConsulta'])) {
            $csvRow[$indexes['politicaCreditoDataConsulta']] = $patch[5] ?? null;
        }

        return $csvRow;
    }

    private function cancelIdleJob(CltConsultJob $job, ?string $reason): void
    {
        $disk = Storage::disk((string) config('cltfacta.storage.reports_disk', 'local'));
        $spoolPath = is_string($job->spool_path ?? null) ? $job->spool_path : null;
        $preserveSpool = CltSpool::hasDataRows($disk, $spoolPath);

        if ($preserveSpool) {
            $this->deletePhaseTwoAuxiliaryArtifacts($disk, $spoolPath, $job->spool_cpfs_path);
            try {
                $spoolBytes = (int) $disk->size($spoolPath);
            } catch (\Throwable) {
                $spoolBytes = 0;
            }

            $job->update([
                'status' => 'cancelado',
                'canceled_at' => now(),
                'cancel_reason' => $reason,
                'paused_at' => null,
                'finished_at' => now(),
                'scheduled_for' => $job->scheduled_for,
                'spool_path' => $spoolPath,
                'spool_cpfs_path' => null,
                'spool_bytes' => $spoolBytes,
                'phase' => $job->phase,
            ]);
            return;
        }

        $this->deleteSpoolArtifacts($disk, $job->spool_path, $job->spool_cpfs_path);
        $job->update([
            'status' => 'cancelado',
            'canceled_at' => now(),
            'cancel_reason' => $reason,
            'paused_at' => null,
            'finished_at' => now(),
            'scheduled_for' => $job->scheduled_for,
            'spool_path' => null,
            'spool_cpfs_path' => null,
            'spool_bytes' => 0,
            'phase' => null,
        ]);
    }

    private function deleteSpoolArtifacts($disk, ?string $spoolPath, ?string $cpfsPath): void
    {
        CltSpool::deleteArtifacts($disk, $spoolPath, $cpfsPath);
    }

    private function deletePhaseTwoAuxiliaryArtifacts($disk, ?string $spoolPath, ?string $cpfsPath): void
    {
        CltSpool::deletePhaseTwoAuxiliaryArtifacts($disk, $spoolPath, $cpfsPath);
    }

    private function resetPhase2ColumnsInExistingSpool($disk, string $spoolPath): int
    {
        $sourceReal = $disk->path($spoolPath);
        $tmpReal = "{$sourceReal}.phase2.rerun.tmp";
        $cleanupTmp = true;
        try {
            $in = @fopen($sourceReal, 'rb');
            $out = @fopen($tmpReal, 'wb');
            if (!is_resource($in) || !is_resource($out)) {
                if (is_resource($in)) {
                    @fclose($in);
                }
                if (is_resource($out)) {
                    @fclose($out);
                }
                throw new \RuntimeException("Falha ao abrir spool preservado para rerun: {$spoolPath}");
            }

            $phase2Indexes = $this->phase2ColumnsIndexesForReset();
            $colsCount = count(CltSchema::COLS);

            try {
                $header = @fgetcsv($in, 0, ';');
                if ($header === false) {
                    throw new \RuntimeException("Spool preservado vazio, impossível reprocessar a fase 2.");
                }

                $this->writeCsvRowOrFail($out, CltSchema::TITLES, "spool preservado {$spoolPath}");

                while (($row = @fgetcsv($in, 0, ';')) !== false) {
                    if (count($row) < $colsCount) {
                        $row = array_pad($row, $colsCount, null);
                    } elseif (count($row) > $colsCount) {
                        $row = array_slice($row, 0, $colsCount);
                    }

                    foreach ($phase2Indexes as $idx) {
                        $row[$idx] = null;
                    }

                    $row = CltSchema::normalizeOrderedRowForCsv($row);
                    $this->writeCsvRowOrFail($out, $row, "spool preservado {$spoolPath}");
                }

                if (!@fflush($out)) {
                    throw new \RuntimeException("Falha ao sincronizar spool preservado em disco.");
                }
            } finally {
                if (is_resource($in)) {
                    @fclose($in);
                }
                if (is_resource($out)) {
                    @fclose($out);
                }
            }

            if (!@rename($tmpReal, $sourceReal)) {
                throw new \RuntimeException("Falha ao promover spool preservado para rerun: {$spoolPath}");
            }

            $cleanupTmp = false;

            try {
                return (int) $disk->size($spoolPath);
            } catch (\Throwable) {
                return 0;
            }
        } finally {
            if ($cleanupTmp && is_file($tmpReal)) {
                @unlink($tmpReal);
            }
        }
    }

    /**
     * @param resource $fh
     * @return array<int,mixed>|false
     */
    private function readCsvRowWithSharedLock($fh)
    {
        if (!is_resource($fh)) {
            return false;
        }

        if (!@flock($fh, LOCK_SH)) {
            return fgetcsv($fh, 0, ';');
        }

        try {
            return fgetcsv($fh, 0, ';');
        } finally {
            @flock($fh, LOCK_UN);
        }
    }

    private function writeCsvRowOrFail($handle, array $row, string $context): void
    {
        $written = fputcsv($handle, $row, ';');
        if ($written === false) {
            throw new \RuntimeException("Falha ao escrever linha CSV em {$context}.");
        }
    }

    private function writeAllOrFail($handle, string $data, string $context): void
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written <= 0) {
                throw new \RuntimeException("Falha ao escrever dados em {$context}.");
            }

            $offset += $written;
        }
    }

}
