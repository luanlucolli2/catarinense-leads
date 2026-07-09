<?php

namespace App\Modules\HubCredito\Jobs;

use App\Modules\HubCredito\Models\HubCreditoConsultJob;
use App\Modules\HubCredito\Services\HubCreditoApiService;
use App\Modules\HubCredito\Support\HubCreditoFiles;
use App\Modules\HubCredito\Support\HubCreditoSchema;
use App\Modules\V8\Models\IbgeName;
use App\Support\Cpf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessHubCreditoConsultJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const PENDING_SHARD_COUNT = HubCreditoFiles::PENDING_SHARD_COUNT;
    private const ROW_BUFFER_SIZE = 100;
    private const CANCEL_CHECK_INTERVAL = 25;

    public int $uniqueFor = 21600;
    public int $timeout;
    public int $tries = 1;

    private int $jobId;
    private string $disk;
    private string $dirSpool;
    private string $finalPrefix;
    private int $phase2TimeoutSeconds;
    private int $phase2StartDelaySeconds;
    private int $phase1RequestIntervalMs;
    private int $pollDelaySeconds;
    private int $pageSize;
    private array $genderCache = [];

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;
        $this->timeout = (int) config('hubcredito.job.timeout_seconds', 10800);
        $this->disk = (string) config('hubcredito.storage.reports_disk', 'local');
        $this->dirSpool = (string) config('hubcredito.storage.dir_spool', 'hubcredito-spool');
        $this->finalPrefix = (string) config('hubcredito.storage.final_prefix', 'hubcredito-consulta');
        $this->phase2TimeoutSeconds = max(60, (int) config('hubcredito.job.phase2_timeout_seconds', 2700));
        $this->phase2StartDelaySeconds = max(0, (int) config('hubcredito.job.phase2_start_delay_seconds', 60));
        $this->phase1RequestIntervalMs = max(0, (int) config('hubcredito.job.phase1_request_interval_ms', 1500));
        $this->pollDelaySeconds = max(0, (int) config('hubcredito.job.poll_delay_seconds', 15));
        $this->pageSize = max(1, min(100, (int) config('hubcredito.job.page_size', 100)));
    }

    public function uniqueId(): string
    {
        return (string) $this->jobId;
    }

    public function handle(HubCreditoApiService $api): void
    {
        $api->setJobId($this->jobId);

        $job = HubCreditoConsultJob::query()->whereKey($this->jobId)->first();
        if ($job === null) {
            return;
        }

        if ($job->status === 'cancelado') {
            $this->finalizeJob('falhou');
            return;
        }

        $disk = Storage::disk($this->disk);
        if (
            empty($job->spool_path)
            || empty($job->spool_inputs_path)
            || !$disk->exists($job->spool_path)
            || !$disk->exists($job->spool_inputs_path)
        ) {
            $this->finalizeJob('falhou');
            return;
        }

        $startedAt = $job->started_at ?? Carbon::now();
        $claimed = DB::table('hubcredito_consult_jobs')
            ->where('id', $job->id)
            ->where('status', 'pendente')
            ->update([
                'status' => 'em_progresso',
                'phase' => 'fase_1',
                'started_at' => $startedAt,
                'updated_at' => Carbon::now(),
            ]);

        if ($claimed === 0) {
            $status = $this->currentStatus();
            if ($status === 'cancelado') {
                $this->finalizeJob('falhou');
            }
            return;
        }

        $job->refresh();
        $this->logWarning('Processamento iniciado.', [
            'status' => $job->status,
            'phase' => $job->phase,
        ]);

        try {
            $api->setRateLimitMs($this->phase1RequestIntervalMs);
            [$totalCount, $pendingCount] = $this->runPhaseOne($api, $job);
            $api->setRateLimitMs(null);
            $this->logWarning('Fase 1 concluida.', [
                'total_cpfs' => $totalCount,
                'pending_count' => $pendingCount,
            ]);

            if ($this->isCancelled()) {
                $this->finalizeJob('falhou');
                return;
            }

            if ($totalCount === 0) {
                $this->finalizeJob('falhou');
                return;
            }

            if ($pendingCount > 0) {
                DB::table('hubcredito_consult_jobs')
                    ->where('id', $job->id)
                    ->update([
                        'phase' => 'fase_2',
                        'updated_at' => Carbon::now(),
                    ]);

                if ($this->phase2StartDelaySeconds > 0) {
                    $this->logWarning('Aguardando inicio da fase 2.', [
                        'sleep_seconds' => $this->phase2StartDelaySeconds,
                    ]);
                    sleep($this->phase2StartDelaySeconds);
                }

                $this->logWarning('Fase 2 iniciada.');

                $phase2Status = $this->runPhaseTwo($api, $job, $pendingCount);
                $this->finalizeJob($phase2Status);
                return;
            }

            $this->finalizeJob('concluido');
        } catch (Throwable $e) {
            Log::error("[HUBCREDITO] Processamento falhou (job {$this->jobId}): {$e->getMessage()}", [
                'exception' => $e,
            ]);
            $api->setRateLimitMs(null);
            $this->finalizeJob('falhou');
        }
    }

    private function runPhaseOne(HubCreditoApiService $api, HubCreditoConsultJob $job): array
    {
        $seenCpfs = [];
        $rowBuffer = [];
        $pendingHandles = [];
        $totalCount = 0;
        $pendingCount = 0;
        $cancelChecks = 0;
        $disk = Storage::disk($this->disk);

        try {
            foreach ($this->tokenizeInputFile($disk->path($job->spool_inputs_path)) as $line) {
                $parsed = $this->parseRawLine($line);
                if ($parsed['error']) {
                    $totalCount++;
                    $row = $this->baseRow((string) ($parsed['cpf'] ?? ''), $parsed['nome'] ?? null, $parsed['nasc'] ?? null);
                    $row['situacao'] = $this->notApprovedStatus();
                    $row['mensagem'] = (string) $parsed['error'];
                    $rowBuffer[] = $row;
                    $this->flushRowBuffer($job, $rowBuffer);
                    continue;
                }

                $cpf = $parsed['cpf'];
                if (isset($seenCpfs[$cpf])) {
                    continue;
                }

                $seenCpfs[$cpf] = true;
                $totalCount++;
                $entry = [
                    'cpf' => $cpf,
                    'nome' => $parsed['nome'],
                    'nasc' => $parsed['nasc'],
                ];

                $cancelChecks++;
                if ($cancelChecks >= self::CANCEL_CHECK_INTERVAL) {
                    $cancelChecks = 0;
                    if ($this->isCancelled()) {
                        break;
                    }
                }

                $response = $api->createPreSimulacao($this->buildPreSimulacaoPayload($entry));
                $value = is_array($response['body']['value'] ?? null) ? $response['body']['value'] : [];
                $preSimId = $value['id'] ?? null;

                if (!is_numeric($preSimId)) {
                    $row = $this->baseRow($entry['cpf'], $entry['nome'], $entry['nasc']);
                    $row['situacao'] = $this->notApprovedStatus();
                    $row['mensagem'] = $this->formatApiMessage($response) ?: 'Falha ao criar pré-simulação.';
                    $rowBuffer[] = $row;
                    $this->flushRowBuffer($job, $rowBuffer);
                    continue;
                }

                $pendingCount++;
                $this->writePendingShardEntry($disk, $pendingHandles, (string) $preSimId, $entry);
            }
        } finally {
            $this->flushRowBuffer($job, $rowBuffer, true);
            $this->closeHandles($pendingHandles);
        }

        DB::table('hubcredito_consult_jobs')
            ->where('id', $job->id)
            ->update([
                'total_cpfs' => $totalCount,
                'spool_bytes' => $this->fileSizeSafe($disk, $job->spool_path),
                'updated_at' => Carbon::now(),
            ]);

        return [$totalCount, $pendingCount];
    }

    private function runPhaseTwo(HubCreditoApiService $api, HubCreditoConsultJob $job, int $pendingCount): string
    {
        if ($pendingCount <= 0) {
            return 'concluido';
        }

        $startedAt = $job->started_at instanceof Carbon
            ? $job->started_at->copy()
            : Carbon::parse((string) $job->started_at);
        $deadline = $startedAt->copy()->addSeconds($this->phase2TimeoutSeconds);

        while ($pendingCount > 0) {
            if ($this->isCancelled()) {
                return 'falhou';
            }

            $page = 1;
            $hasNext = false;
            $resolvedIdsByShard = [];

            do {
                if ($this->isCancelled()) {
                    return 'falhou';
                }

                $response = $api->listPreSimulacao([
                    'numeroPagina' => $page,
                    'tamanhoPagina' => $this->pageSize,
                    'lojaId' => (int) config('hubcredito.integration.loja_id', 15895),
                    'dataCriacaoInicio' => $this->formatPreSimulacaoTime($startedAt),
                ]);

                if (!$response['ok']) {
                    break;
                }

                $items = $this->extractPreSimulacaoItems((array) ($response['body'] ?? []));
                if ($items !== []) {
                    [$terminalRows, $processedCount] = $this->processPageAgainstShards($api, $items, $resolvedIdsByShard);
                    if ($terminalRows !== []) {
                        $this->appendTerminalRows($job, $terminalRows);
                    }
                    $pendingCount -= $processedCount;
                }

                $hasNext = $this->resolveHasNextPage((array) ($response['body'] ?? []), $items, $page);

                $page++;
            } while ($hasNext);

            $this->compactResolvedPendingShards($resolvedIdsByShard);

            if ($pendingCount <= 0) {
                break;
            }

            if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            if ($this->pollDelaySeconds > 0) {
                $this->logWarning('Aguardando nova varredura da fase 2.', [
                    'pending_count' => $pendingCount,
                    'sleep_seconds' => $this->pollDelaySeconds,
                ]);
                sleep($this->pollDelaySeconds);
            }
        }

        if ($pendingCount > 0) {
            $this->logWarning('Timeout na fase 2.', [
                'pending_count' => $pendingCount,
            ]);
            $this->flushPendingShardsAsTimeout($job);
        }

        return 'concluido';
    }

    private function processPreSimulacaoItem(HubCreditoApiService $api, array $item, array $entry): array
    {
        $statusId = $this->resolveStatusId($item);
        $row = $this->baseRow($entry['cpf'], $entry['nome'], $entry['nasc']);
        $row['pre_simulacao_id'] = (string) ($item['id'] ?? $entry['pre_simulacao_id']);
        $row['pre_simulacao_status'] = (string) ($item['status'] ?? '');
        $row['mensagem'] = trim((string) ($item['mensagemErro'] ?? ''));
        $row['valor_solicitado'] = $this->stringifyDecimal($item['valor'] ?? null);
        $row['parcelas_solicitadas'] = (string) ($item['numeroParcelas'] ?? '');

        if (in_array($statusId, [0, 1, 13], true)) {
            return ['terminal' => false, 'row' => null];
        }

        if (in_array($statusId, [3, 4], true)) {
            $row['situacao'] = $this->notApprovedStatus();
            $row['mensagem'] = $row['mensagem'] !== '' ? $row['mensagem'] : 'Vínculo requer ação manual.';
            $row['finalizado_em'] = Carbon::now()->toDateTimeString();

            return ['terminal' => true, 'row' => $row];
        }

        if (in_array($statusId, [6, 12], true)) {
            return [
                'terminal' => true,
                'row' => $this->simulatePreSimulacao($api, $item, $row),
            ];
        }

        if (in_array($statusId, [2, 5, 7, 8, 9, 10, 11, 14, 15], true)) {
            $row['situacao'] = $this->notApprovedStatus();
            $row['finalizado_em'] = Carbon::now()->toDateTimeString();

            return ['terminal' => true, 'row' => $row];
        }

        $row['situacao'] = $this->notApprovedStatus();
        if ($row['mensagem'] === '') {
            $row['mensagem'] = 'Status de pré-simulação não tratado.';
        }
        $row['finalizado_em'] = Carbon::now()->toDateTimeString();

        return ['terminal' => true, 'row' => $row];
    }

    private function simulatePreSimulacao(HubCreditoApiService $api, array $item, array $row): array
    {
        $response = $api->simulate([
            'cpf' => (string) ($item['cpf'] ?? $row['cpf']),
            'lojaId' => (int) config('hubcredito.integration.loja_id', 15895),
            'numeroParcelas' => (int) ($item['numeroParcelas'] ?? config('hubcredito.presimulacao.numero_parcelas', 12)),
            'valor' => (float) ($item['valor'] ?? config('hubcredito.presimulacao.valor', 5000)),
            'PreSimulacaoId' => (int) ($item['id'] ?? 0),
        ]);

        if ($this->responseRequiresVinculo($response)) {
            $row['situacao'] = $this->notApprovedStatus();
            $row['mensagem'] = $this->formatApiMessage($response) ?: 'Vínculo requer ação manual.';
            $row['finalizado_em'] = Carbon::now()->toDateTimeString();

            return $row;
        }

        $offers = is_array($response['body']['value'] ?? null) ? $response['body']['value'] : [];
        $bestOffer = $this->selectBestOffer($offers);

        if ($bestOffer !== null) {
            $proposal = is_array($bestOffer['opcaoProposta'] ?? null) ? $bestOffer['opcaoProposta'] : [];
            $row['situacao'] = $this->approvedStatus();
            $row['valor_liberado'] = $this->stringifyDecimal($proposal['valorDesembolsoTrabalhador'] ?? null);
            $row['valor_desembolso_total'] = $this->stringifyDecimal($proposal['valorDesembolsoTotal'] ?? null);
            $row['valor_parcela'] = $this->stringifyDecimal($proposal['valorParcela'] ?? null);
            $row['parcelas_oferta'] = (string) ($proposal['numeroParcelas'] ?? '');
            $row['taxa_juros'] = $this->stringifyDecimal($proposal['taxaJuros'] ?? null);
            $row['valor_seguro'] = $this->stringifyDecimal($proposal['valorSeguro'] ?? null);
            $row['com_seguro'] = array_key_exists('comSeguro', $proposal)
                ? ((bool) $proposal['comSeguro'] ? '1' : '0')
                : '';
            $row['mensagem'] = '';
            $row['finalizado_em'] = Carbon::now()->toDateTimeString();

            return $row;
        }

        $row['situacao'] = $this->notApprovedStatus();
        $row['mensagem'] = $this->formatApiMessage($response) ?: 'Simulação sem ofertas.';
        $row['finalizado_em'] = Carbon::now()->toDateTimeString();

        return $row;
    }

    private function appendTerminalRows(HubCreditoConsultJob $job, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $disk = Storage::disk($this->disk);
        $realPath = $disk->path((string) $job->spool_path);
        $handle = @fopen($realPath, 'ab');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Falha ao abrir spool para append.');
        }

        $aprovado = 0;
        $naoAprovado = 0;

        try {
            flock($handle, LOCK_EX);
            foreach ($rows as $row) {
                $csvRow = [];
                foreach (HubCreditoSchema::COLS as $col) {
                    $csvRow[] = $row[$col] ?? '';
                }
                fputcsv($handle, $csvRow, ';');

                $situacao = $row['situacao'] ?? '';
                if ($situacao === $this->approvedStatus()) {
                    $aprovado++;
                } else {
                    $naoAprovado++;
                }
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        DB::table('hubcredito_consult_jobs')
            ->where('id', $job->id)
            ->update([
                'aprovado_count' => DB::raw("aprovado_count + {$aprovado}"),
                'nao_aprovado_count' => DB::raw("nao_aprovado_count + {$naoAprovado}"),
                'pendencia_count' => 0,
                'spool_bytes' => $this->fileSizeSafe($disk, $job->spool_path),
                'updated_at' => Carbon::now(),
            ]);
    }

    private function flushRowBuffer(HubCreditoConsultJob $job, array &$rows, bool $force = false): void
    {
        if ($rows === []) {
            return;
        }

        if (!$force && count($rows) < self::ROW_BUFFER_SIZE) {
            return;
        }

        $this->appendTerminalRows($job, $rows);
        $rows = [];
    }

    private function processPageAgainstShards(HubCreditoApiService $api, array $items, array &$resolvedIdsByShard): array
    {
        $itemsByShard = [];
        foreach ($items as $item) {
            $preSimId = isset($item['id']) ? (string) $item['id'] : '';
            if ($preSimId === '') {
                continue;
            }

            $itemsByShard[$this->pendingShardIndex($preSimId)][$preSimId] = $item;
        }

        $rows = [];
        $processedCount = 0;

        foreach ($itemsByShard as $shard => $itemsById) {
            $resolvedIdsByShard[$shard] ??= [];
            [$shardRows, $shardProcessedCount] = $this->processPendingShardItems(
                $api,
                (int) $shard,
                $itemsById,
                $resolvedIdsByShard[$shard]
            );
            if ($shardRows !== []) {
                $rows = array_merge($rows, $shardRows);
            }
            $processedCount += $shardProcessedCount;
        }

        return [$rows, $processedCount];
    }

    private function processPendingShardItems(HubCreditoApiService $api, int $shard, array $itemsById, array &$resolvedIds): array
    {
        $disk = Storage::disk($this->disk);
        $path = $this->pendingShardPath($shard);
        if (!$disk->exists($path)) {
            return [[], 0];
        }

        $handle = @fopen($disk->path($path), 'rb');
        if (!is_resource($handle)) {
            return [[], 0];
        }

        $rows = [];
        $processedCount = 0;

        try {
            flock($handle, LOCK_SH);
            while (($parts = fgetcsv($handle, 0, ';')) !== false) {
                $entry = $this->pendingEntryFromParts($parts);
                if ($entry === null) {
                    continue;
                }

                $preSimId = $entry['pre_simulacao_id'];
                if (isset($resolvedIds[$preSimId]) || !isset($itemsById[$preSimId])) {
                    continue;
                }

                $outcome = $this->processPreSimulacaoItem($api, $itemsById[$preSimId], $entry);
                if (!$outcome['terminal']) {
                    continue;
                }

                $rows[] = $outcome['row'];
                $resolvedIds[$preSimId] = true;
                $processedCount++;
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return [$rows, $processedCount];
    }

    private function flushPendingShardsAsTimeout(HubCreditoConsultJob $job): void
    {
        $rowBuffer = [];
        $disk = Storage::disk($this->disk);

        foreach ($this->pendingShardIndexes() as $shard) {
            $path = $this->pendingShardPath($shard);
            if (!$disk->exists($path)) {
                continue;
            }

            foreach ($this->readPendingEntries($path) as $entry) {
                $row = $this->baseRow($entry['cpf'], $entry['nome'], $entry['nasc']);
                $row['situacao'] = $this->notApprovedStatus();
                $row['pre_simulacao_id'] = $entry['pre_simulacao_id'];
                $row['mensagem'] = 'Timeout aguardando processamento da pré-simulação.';
                $rowBuffer[] = $row;
                $this->flushRowBuffer($job, $rowBuffer);
            }

            $disk->delete($path);
        }

        $this->flushRowBuffer($job, $rowBuffer, true);
    }

    private function writePendingShardEntry($disk, array &$handles, string $preSimId, array $entry): void
    {
        $shard = $this->pendingShardIndex($preSimId);
        if (!isset($handles[$shard])) {
            $path = $this->pendingShardPath($shard);
            $handle = @fopen($disk->path($path), 'ab');
            if (!is_resource($handle)) {
                throw new \RuntimeException("Falha ao abrir shard de pendências: {$path}");
            }
            $handles[$shard] = $handle;
        }

        fputcsv($handles[$shard], [
            $preSimId,
            $entry['cpf'],
            $entry['nome'],
            $entry['nasc'],
        ], ';');
    }

    private function closeHandles(array &$handles): void
    {
        foreach ($handles as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $handles = [];
    }

    private function pendingShardIndexes(): array
    {
        return range(0, self::PENDING_SHARD_COUNT - 1);
    }

    private function pendingShardIndex(string $preSimId): int
    {
        return abs(crc32($preSimId)) % self::PENDING_SHARD_COUNT;
    }

    private function pendingShardPath(int $shard): string
    {
        return HubCreditoFiles::pendingShardPath($this->dirSpool, $this->finalPrefix, $this->jobId, $shard);
    }

    private function buildPreSimulacaoPayload(array $entry): array
    {
        return [
            'cpf' => $entry['cpf'],
            'lojaId' => (int) config('hubcredito.integration.loja_id', 15895),
            'numeroParcelas' => (int) config('hubcredito.presimulacao.numero_parcelas', 12),
            'valor' => (float) config('hubcredito.presimulacao.valor', 5000),
            'tipoOperacao' => (string) config('hubcredito.integration.tipo_operacao', '27'),
            'nome' => $entry['nome'],
            'email' => $this->buildEmail($entry['cpf'], $entry['nome']),
            'telefone' => $this->buildPhone($entry['cpf']),
            'dataNascimento' => $entry['nasc'] . 'T00:00:00.000Z',
            'sexo' => $this->genderFromName($entry['cpf'], $entry['nome']),
        ];
    }

    private function buildEmail(string $cpf, string $nome): string
    {
        $firstName = $this->extractFirstName($nome) ?? 'cliente';
        $firstName = $this->toAsciiLower($firstName);
        $firstName = preg_replace('/[^a-z0-9]+/', '', $firstName) ?: 'cliente';
        $domain = trim((string) config('hubcredito.integration.email_domain', 'hubcredito.local'));

        return "{$firstName}." . substr($cpf, -4) . "@{$domain}";
    }

    private function buildPhone(string $cpf): string
    {
        $ddds = [11, 21, 27, 31, 41, 47, 48, 51, 61, 62, 71, 81, 85, 91, 92, 98];
        $seed = abs(crc32($cpf));
        $ddd = $ddds[$seed % count($ddds)];
        $number = 10000000 + ($seed % 90000000);

        return sprintf('%02d9%08d', $ddd, $number);
    }

    private function genderFromName(string $cpf, string $nome): string
    {
        $first = $this->extractFirstName($nome);
        if ($first !== null) {
            $cacheKey = $this->upper($first);
            if (array_key_exists($cacheKey, $this->genderCache)) {
                return $this->genderCache[$cacheKey];
            }

            $gender = IbgeName::query()->where('name', $cacheKey)->value('gender');
            if (is_string($gender)) {
                $gender = strtoupper($gender);
                if ($gender === 'M') {
                    return $this->genderCache[$cacheKey] = 'Masculino';
                }
                if ($gender === 'F') {
                    return $this->genderCache[$cacheKey] = 'Feminino';
                }
            }
        }

        return ((int) substr($cpf, -1)) % 2 === 0 ? 'Masculino' : 'Feminino';
    }

    private function selectBestOffer(array $offers): ?array
    {
        $best = null;
        $bestValue = null;

        foreach ($offers as $offer) {
            if (!is_array($offer)) {
                continue;
            }

            $proposal = is_array($offer['opcaoProposta'] ?? null) ? $offer['opcaoProposta'] : [];
            $value = $proposal['valorDesembolsoTrabalhador'] ?? $proposal['valorDesembolsoTotal'] ?? null;
            $numeric = is_numeric($value) ? (float) $value : null;
            if ($numeric === null) {
                continue;
            }

            if ($best === null || $bestValue === null || $numeric > $bestValue) {
                $best = $offer;
                $bestValue = $numeric;
            }
        }

        return $best;
    }

    private function responseRequiresVinculo(array $response): bool
    {
        $text = strtolower($this->formatApiMessage($response));
        if ($text === '') {
            return false;
        }

        foreach ([
            'idcotacao',
            'matricula',
            'codigoinscricaoempregador',
            'numeroinscricaoempregador',
            'vinculo',
        ] as $needle) {
            if (str_contains($text, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    private function formatApiMessage(array $response): string
    {
        $errors = is_array($response['errors'] ?? null) ? $response['errors'] : [];
        $message = trim((string) ($response['message'] ?? ''));
        if ($message !== '') {
            $errors[] = $message;
        }

        $errors = array_values(array_filter(array_map(static fn ($value) => trim((string) $value), $errors), static fn ($value) => $value !== ''));

        return implode(' | ', array_values(array_unique($errors)));
    }

    private function resolveStatusId(array $item): ?int
    {
        if (isset($item['idStatus']) && is_numeric($item['idStatus'])) {
            return (int) $item['idStatus'];
        }

        if (isset($item['status']) && is_numeric($item['status'])) {
            return (int) $item['status'];
        }

        $status = strtoupper(trim((string) ($item['status'] ?? '')));
        return match ($status) {
            'PENDENTE' => 0,
            'EMPROCESSAMENTO' => 1,
            'NAOELEGIVEL' => 2,
            'ESCOLHERVINCULO' => 3,
            'SELECIONANDOVINCULO' => 4,
            'SEMOPCOES' => 5,
            'SIMULACOESDISPONIVEIS' => 6,
            'ERRO' => 7,
            'CANCELADA' => 8,
            'NAOENCONTRADODATAPREV' => 9,
            'TIPOOPERACAOINATIVO' => 10,
            'AGUARDANDOASSINATURATERMO' => 11,
            'CONCLUIDA' => 12,
            'AGUARDANDORETORNOBANCARIZADOR' => 13,
            'EMPRESAEMSITUACAOIRREGULAR' => 14,
            'DADOSCLIENTEINVALIDOS' => 15,
            default => null,
        };
    }

    private function extractPreSimulacaoItems(array $body): array
    {
        $items = $body['itens'] ?? null;
        if (is_array($items)) {
            return $items;
        }

        $value = $body['value'] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $nestedItems = $value['itens'] ?? null;
        if (is_array($nestedItems)) {
            return $nestedItems;
        }

        return array_is_list($value) ? $value : [];
    }

    private function resolveHasNextPage(array $body, array $items, int $page): bool
    {
        $hasNext = $body['temProximaPagina'] ?? ($body['value']['temProximaPagina'] ?? null);
        if (is_bool($hasNext)) {
            return $hasNext;
        }

        $totalPages = $body['totalPaginas'] ?? ($body['value']['totalPaginas'] ?? null);
        if (is_numeric($totalPages)) {
            return $page < (int) $totalPages;
        }

        return $items !== [] && count($items) >= $this->pageSize;
    }

    private function formatPreSimulacaoTime(Carbon $value): string
    {
        return $value->copy()->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i:s');
    }

    private function baseRow(string $cpf, ?string $nome, ?string $nasc): array
    {
        $row = array_fill_keys(HubCreditoSchema::COLS, '');
        $row['cpf'] = Cpf::normalize($cpf) ?? '';
        $row['nome'] = $this->cleanName($nome) ?? '';
        $row['data_nascimento'] = $nasc ?? '';

        return $row;
    }

    private function tokenizeInputFile(string $realPath): \Generator
    {
        $reader = @fopen($realPath, 'rb');
        if (!is_resource($reader)) {
            return;
        }

        try {
            while (($line = fgets($reader)) !== false) {
                $line = trim($line);
                if ($line !== '') {
                    yield $line;
                }
            }
        } finally {
            fclose($reader);
        }
    }

    private function parseRawLine(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return ['error' => 'Linha vazia.'];
        }

        $line = str_replace([',', ';', "\t"], ' ', $line);
        $parts = preg_split('/\s+/', $line);
        if (!$parts || count($parts) < 3) {
            return ['error' => 'Linha inválida.'];
        }

        $cpfRaw = $parts[0] ?? '';
        $cpf = Cpf::normalize($cpfRaw);
        if (!$cpf) {
            return ['error' => 'CPF inválido.', 'cpf' => null];
        }

        if (!Cpf::isValid($cpf)) {
            return ['error' => 'CPF inválido (dígitos verificadores).', 'cpf' => $cpf];
        }

        $dateIdx = null;
        for ($i = 1; $i < count($parts); $i++) {
            if (preg_match('/\d/', $parts[$i])) {
                $dateIdx = $i;
                break;
            }
        }

        if ($dateIdx === null || $dateIdx === 1) {
            return ['error' => 'Nome ou data de nascimento ausentes.', 'cpf' => $cpf];
        }

        $nome = $this->cleanName(implode(' ', array_slice($parts, 1, $dateIdx - 1)));
        $rawDate = $parts[$dateIdx] ?? '';
        $nasc = $this->normalizeBirthDate($rawDate);

        if ($nome === '' || !$nasc) {
            return ['error' => 'Nome ou data de nascimento inválidos.', 'cpf' => $cpf, 'nome' => $nome, 'nasc' => $nasc];
        }

        return [
            'cpf' => $cpf,
            'nome' => $nome,
            'nasc' => $nasc,
            'error' => null,
        ];
    }

    private function normalizeBirthDate(string $raw): ?string
    {
        $raw = trim($raw);
        $raw = rtrim($raw, ',;');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $matches)) {
            return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
        }

        return null;
    }

    private function cleanName(?string $nome): ?string
    {
        if ($nome === null) {
            return null;
        }

        $nome = trim($nome);
        if ($nome === '') {
            return $nome;
        }

        $nome = trim($nome, "\"'");
        $nome = str_replace(['"', "'"], '', $nome);
        $nome = str_replace('.', '', $nome);
        $nome = preg_replace('/\s+/', ' ', $nome) ?? $nome;

        return $nome;
    }

    private function extractFirstName(string $nome): ?string
    {
        $nome = trim($nome);
        if ($nome === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $nome);

        return $parts[0] ?? null;
    }

    private function upper(string $value): string
    {
        return function_exists('mb_strtoupper')
            ? mb_strtoupper($value, 'UTF-8')
            : strtoupper($value);
    }

    private function toAsciiLower(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $ascii = $ascii !== false ? $ascii : $value;

        return strtolower($ascii);
    }

    private function stringifyDecimal($value): string
    {
        if (!is_numeric($value)) {
            return '';
        }

        $formatted = number_format((float) $value, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    private function approvedStatus(): string
    {
        return 'Aprovado';
    }

    private function notApprovedStatus(): string
    {
        return 'Não Aprovado';
    }

    private function finalizeJob(string $targetStatus): void
    {
        $this->logWarning('Finalizando job.', [
            'target_status' => $targetStatus,
        ]);
        FinalizeHubCreditoConsultReportJob::dispatch($this->jobId, $targetStatus);
    }

    private function logWarning(string $message, array $context = []): void
    {
        if (!(bool) config('hubcredito.logging.enabled', false)) {
            return;
        }

        try {
            Log::warning("[HUBCREDITO] {$message}", array_merge([
                'job_id' => $this->jobId,
            ], $context));
        } catch (Throwable) {
        }
    }

    private function currentStatus(): ?string
    {
        $status = DB::table('hubcredito_consult_jobs')
            ->where('id', $this->jobId)
            ->value('status');

        return is_string($status) ? $status : null;
    }

    private function isCancelled(): bool
    {
        return $this->currentStatus() === 'cancelado';
    }

    private function fileSizeSafe($disk, ?string $path): int
    {
        if (!is_string($path) || $path === '' || !$disk->exists($path)) {
            return 0;
        }

        try {
            return (int) $disk->size($path);
        } catch (Throwable) {
            return 0;
        }
    }

    private function compactResolvedPendingShards(array $resolvedIdsByShard): void
    {
        foreach ($resolvedIdsByShard as $shard => $resolvedIds) {
            if ($resolvedIds === []) {
                continue;
            }

            $this->compactResolvedPendingShard((int) $shard, $resolvedIds);
        }
    }

    private function compactResolvedPendingShard(int $shard, array $resolvedIds): void
    {
        $disk = Storage::disk($this->disk);
        $path = $this->pendingShardPath($shard);
        if (!$disk->exists($path)) {
            return;
        }

        $source = @fopen($disk->path($path), 'rb');
        $tmpPath = $path . '.tmp';
        $target = @fopen($disk->path($tmpPath), 'wb');
        if (!is_resource($source) || !is_resource($target)) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($target)) {
                fclose($target);
            }
            throw new \RuntimeException('Falha ao compactar shard de pendências.');
        }

        $resolvedMap = array_fill_keys(array_keys($resolvedIds), true);
        $remaining = 0;

        try {
            flock($source, LOCK_SH);
            while (($parts = fgetcsv($source, 0, ';')) !== false) {
                $entry = $this->pendingEntryFromParts($parts);
                if ($entry === null || isset($resolvedMap[$entry['pre_simulacao_id']])) {
                    continue;
                }

                fputcsv($target, [
                    $entry['pre_simulacao_id'],
                    $entry['cpf'],
                    $entry['nome'],
                    $entry['nasc'],
                ], ';');
                $remaining++;
            }
        } finally {
            flock($source, LOCK_UN);
            fclose($source);
            fflush($target);
            fclose($target);
        }

        if ($remaining === 0) {
            $disk->delete($path);
            $disk->delete($tmpPath);
            return;
        }

        if (!@rename($disk->path($tmpPath), $disk->path($path))) {
            $disk->delete($tmpPath);
            throw new \RuntimeException('Falha ao promover shard compactada.');
        }
    }

    private function readPendingEntries(string $path): \Generator
    {
        $disk = Storage::disk($this->disk);
        if (!$disk->exists($path)) {
            return;
        }

        $handle = @fopen($disk->path($path), 'rb');
        if (!is_resource($handle)) {
            return;
        }

        try {
            flock($handle, LOCK_SH);
            while (($parts = fgetcsv($handle, 0, ';')) !== false) {
                $entry = $this->pendingEntryFromParts($parts);
                if ($entry !== null) {
                    yield $entry;
                }
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function pendingEntryFromParts($parts): ?array
    {
        if (!is_array($parts) || count($parts) < 4) {
            return null;
        }

        $preSimId = trim((string) ($parts[0] ?? ''));
        if ($preSimId === '') {
            return null;
        }

        return [
            'pre_simulacao_id' => $preSimId,
            'cpf' => trim((string) ($parts[1] ?? '')),
            'nome' => trim((string) ($parts[2] ?? '')),
            'nasc' => trim((string) ($parts[3] ?? '')),
        ];
    }
}
