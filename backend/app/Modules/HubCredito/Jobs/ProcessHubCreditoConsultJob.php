<?php

namespace App\Modules\HubCredito\Jobs;

use App\Modules\HubCredito\Models\HubCreditoConsultJob;
use App\Modules\HubCredito\Services\HubCreditoApiService;
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
            [$totalCount, $pendingPath] = $this->runPhaseOne($api, $job);
            $api->setRateLimitMs(null);
            $this->logWarning('Fase 1 concluida.', [
                'total_cpfs' => $totalCount,
                'has_pending_phase2' => $pendingPath !== null,
            ]);

            if ($this->isCancelled()) {
                $this->finalizeJob('falhou');
                return;
            }

            if ($totalCount === 0) {
                $this->finalizeJob('falhou');
                return;
            }

            if ($pendingPath !== null) {
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

                $phase2Status = $this->runPhaseTwo($api, $job, $pendingPath);
                $this->finalizeJob($phase2Status);
                return;
            }

            $this->finalizeJob('concluido');
        } catch (Throwable) {
            $this->finalizeJob('falhou');
        }
    }

    private function runPhaseOne(HubCreditoApiService $api, HubCreditoConsultJob $job): array
    {
        $disk = Storage::disk($this->disk);
        $uniqPath = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.inputs.uniq.csv";
        $pendingPath = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.phase2.pending.csv";

        $uniqHandle = @fopen($disk->path($uniqPath), 'wb');
        $pendingHandle = @fopen($disk->path($pendingPath), 'wb');
        if (!is_resource($uniqHandle) || !is_resource($pendingHandle)) {
            if (is_resource($uniqHandle)) {
                fclose($uniqHandle);
            }
            if (is_resource($pendingHandle)) {
                fclose($pendingHandle);
            }
            throw new \RuntimeException('Falha ao criar arquivos auxiliares da fase 1.');
        }

        $seenCpfs = [];
        $uniqueEntries = [];
        $invalidCount = 0;

        try {
            foreach ($this->tokenizeInputFile($disk->path($job->spool_inputs_path)) as $line) {
                $parsed = $this->parseRawLine($line);
                if ($parsed['error']) {
                    $invalidCount++;
                    $row = $this->baseRow((string) ($parsed['cpf'] ?? ''), $parsed['nome'] ?? null, $parsed['nasc'] ?? null);
                    $row['situacao'] = 'nao_aprovado';
                    $row['mensagem'] = (string) $parsed['error'];
                    $this->appendTerminalRows($job, [$row]);
                    continue;
                }

                $cpf = $parsed['cpf'];
                if (isset($seenCpfs[$cpf])) {
                    continue;
                }

                $seenCpfs[$cpf] = true;
                $entry = [
                    'cpf' => $cpf,
                    'nome' => $parsed['nome'],
                    'nasc' => $parsed['nasc'],
                ];
                $uniqueEntries[] = $entry;
                fputcsv($uniqHandle, [$entry['cpf'], $entry['nome'], $entry['nasc']], ';');
            }
        } finally {
            fclose($uniqHandle);
        }

        $totalCount = count($uniqueEntries) + $invalidCount;
        DB::table('hubcredito_consult_jobs')
            ->where('id', $job->id)
            ->update([
                'total_cpfs' => $totalCount,
                'spool_bytes' => $this->fileSizeSafe($disk, $job->spool_path),
                'updated_at' => Carbon::now(),
            ]);

        if ($uniqueEntries === []) {
            fclose($pendingHandle);
            if ($disk->exists($pendingPath)) {
                $disk->delete($pendingPath);
            }

            return [$totalCount, null];
        }

        $pendingCount = 0;

        try {
            foreach ($uniqueEntries as $entry) {
                if ($this->isCancelled()) {
                    break;
                }

                $response = $api->createPreSimulacao($this->buildPreSimulacaoPayload($entry));
                $value = is_array($response['body']['value'] ?? null) ? $response['body']['value'] : [];
                $preSimId = $value['id'] ?? null;

                if (!is_numeric($preSimId)) {
                    $row = $this->baseRow($entry['cpf'], $entry['nome'], $entry['nasc']);
                    $row['situacao'] = 'nao_aprovado';
                    $row['mensagem'] = $this->formatApiMessage($response) ?: 'Falha ao criar pré-simulação.';
                    $this->appendTerminalRows($job, [$row]);
                    continue;
                }

                $pendingCount++;
                fputcsv($pendingHandle, [(int) $preSimId, $entry['cpf'], $entry['nome'], $entry['nasc']], ';');
            }
        } finally {
            fclose($pendingHandle);
        }

        if ($pendingCount === 0) {
            if ($disk->exists($pendingPath)) {
                $disk->delete($pendingPath);
            }

            return [$totalCount, null];
        }

        return [$totalCount, $pendingPath];
    }

    private function runPhaseTwo(HubCreditoApiService $api, HubCreditoConsultJob $job, string $pendingPath): string
    {
        $pendingEntries = $this->loadPendingEntries($pendingPath);
        if ($pendingEntries === []) {
            return 'concluido';
        }

        $startedAt = $job->started_at instanceof Carbon
            ? $job->started_at->copy()
            : Carbon::parse((string) $job->started_at);
        $deadline = $startedAt->copy()->addSeconds($this->phase2TimeoutSeconds);

        while ($pendingEntries !== []) {
            if ($this->isCancelled()) {
                return 'falhou';
            }

            $page = 1;
            $hasNext = false;

            do {
                if ($this->isCancelled()) {
                    return 'falhou';
                }

                $response = $api->listPreSimulacao([
                    'numeroPagina' => $page,
                    'tamanhoPagina' => $this->pageSize,
                    'lojaId' => (int) config('hubcredito.integration.loja_id', 15895),
                    'dataCriacaoInicio' => $this->formatPreSimulacaoStart($startedAt),
                ]);

                if (!$response['ok']) {
                    break;
                }

                $items = $this->extractPreSimulacaoItems((array) ($response['body'] ?? []));
                $terminalRows = [];

                foreach ($items as $item) {
                    $preSimId = isset($item['id']) ? (string) $item['id'] : '';
                    if ($preSimId === '' || !isset($pendingEntries[$preSimId])) {
                        continue;
                    }

                    $outcome = $this->processPreSimulacaoItem($api, $item, $pendingEntries[$preSimId]);
                    if (!$outcome['terminal']) {
                        continue;
                    }

                    $terminalRows[] = $outcome['row'];
                    unset($pendingEntries[$preSimId]);
                }

                if ($terminalRows !== []) {
                    $this->appendTerminalRows($job, $terminalRows);
                    $this->rewritePendingEntries($pendingPath, $pendingEntries);
                }

                $hasNext = $this->resolveHasNextPage((array) ($response['body'] ?? []), $items, $page);

                $page++;
            } while ($hasNext);

            if ($pendingEntries === []) {
                break;
            }

            if (Carbon::now()->greaterThanOrEqualTo($deadline)) {
                break;
            }

            if ($this->pollDelaySeconds > 0) {
                $this->logWarning('Aguardando nova varredura da fase 2.', [
                    'pending_count' => count($pendingEntries),
                    'sleep_seconds' => $this->pollDelaySeconds,
                ]);
                sleep($this->pollDelaySeconds);
            }
        }

        if ($pendingEntries !== []) {
            $this->logWarning('Timeout na fase 2.', [
                'pending_count' => count($pendingEntries),
            ]);
            $timeoutRows = [];
            foreach ($pendingEntries as $entry) {
                $row = $this->baseRow($entry['cpf'], $entry['nome'], $entry['nasc']);
                $row['situacao'] = 'pendencia';
                $row['pre_simulacao_id'] = $entry['pre_simulacao_id'];
                $row['mensagem'] = 'Timeout aguardando processamento da pré-simulação.';
                $timeoutRows[] = $row;
            }

            $this->appendTerminalRows($job, $timeoutRows);
            $this->rewritePendingEntries($pendingPath, []);
        }

        return 'concluido';
    }

    private function processPreSimulacaoItem(HubCreditoApiService $api, array $item, array $entry): array
    {
        $statusId = $this->resolveStatusId($item);
        $row = $this->baseRow($entry['cpf'], $entry['nome'], $entry['nasc']);
        $row['pre_simulacao_id'] = (string) ($item['id'] ?? $entry['pre_simulacao_id']);
        $row['pre_simulacao_status_id'] = $statusId !== null ? (string) $statusId : '';
        $row['pre_simulacao_status'] = (string) ($item['status'] ?? '');
        $row['status_descricao'] = trim((string) ($item['statusDescricao'] ?? ''));
        $row['mensagem'] = trim((string) ($item['mensagemErro'] ?? ''));
        $row['valor_solicitado'] = $this->stringifyDecimal($item['valor'] ?? null);
        $row['parcelas_solicitadas'] = (string) ($item['numeroParcelas'] ?? '');

        if (in_array($statusId, [0, 1, 13], true)) {
            return ['terminal' => false, 'row' => null];
        }

        if (in_array($statusId, [3, 4], true)) {
            $row['situacao'] = 'pendencia';
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
            $row['situacao'] = 'nao_aprovado';
            $row['finalizado_em'] = Carbon::now()->toDateTimeString();

            return ['terminal' => true, 'row' => $row];
        }

        $row['situacao'] = 'nao_aprovado';
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
            $row['situacao'] = 'pendencia';
            $row['mensagem'] = $this->formatApiMessage($response) ?: 'Vínculo requer ação manual.';
            $row['finalizado_em'] = Carbon::now()->toDateTimeString();

            return $row;
        }

        $offers = is_array($response['body']['value'] ?? null) ? $response['body']['value'] : [];
        $bestOffer = $this->selectBestOffer($offers);

        if ($bestOffer !== null) {
            $proposal = is_array($bestOffer['opcaoProposta'] ?? null) ? $bestOffer['opcaoProposta'] : [];
            $row['situacao'] = 'aprovado';
            $row['simulacao_id'] = (string) ($bestOffer['simulacaoId'] ?? '');
            $row['id_proposta'] = (string) ($proposal['idProposta'] ?? '');
            $row['id_cotacao'] = (string) ($bestOffer['idCotacao'] ?? '');
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

        $row['situacao'] = 'nao_aprovado';
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
        $pendencia = 0;

        try {
            flock($handle, LOCK_EX);
            foreach ($rows as $row) {
                $csvRow = [];
                foreach (HubCreditoSchema::COLS as $col) {
                    $csvRow[] = $row[$col] ?? '';
                }
                fputcsv($handle, $csvRow, ';');

                $situacao = $row['situacao'] ?? '';
                if ($situacao === 'aprovado') {
                    $aprovado++;
                } elseif ($situacao === 'pendencia') {
                    $pendencia++;
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
                'pendencia_count' => DB::raw("pendencia_count + {$pendencia}"),
                'spool_bytes' => $this->fileSizeSafe($disk, $job->spool_path),
                'updated_at' => Carbon::now(),
            ]);
    }

    private function loadPendingEntries(string $pendingPath): array
    {
        $disk = Storage::disk($this->disk);
        if (!$disk->exists($pendingPath)) {
            return [];
        }

        $handle = @fopen($disk->path($pendingPath), 'rb');
        if (!is_resource($handle)) {
            return [];
        }

        $entries = [];

        try {
            flock($handle, LOCK_SH);
            while (($parts = fgetcsv($handle, 0, ';')) !== false) {
                if (!is_array($parts) || count($parts) < 4) {
                    continue;
                }

                $preSimId = trim((string) ($parts[0] ?? ''));
                if ($preSimId === '') {
                    continue;
                }

                $entries[$preSimId] = [
                    'pre_simulacao_id' => $preSimId,
                    'cpf' => trim((string) ($parts[1] ?? '')),
                    'nome' => trim((string) ($parts[2] ?? '')),
                    'nasc' => trim((string) ($parts[3] ?? '')),
                ];
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $entries;
    }

    private function rewritePendingEntries(string $pendingPath, array $entries): void
    {
        $disk = Storage::disk($this->disk);
        $handle = @fopen($disk->path($pendingPath), 'wb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Falha ao regravar arquivo de pendências.');
        }

        try {
            flock($handle, LOCK_EX);
            foreach ($entries as $entry) {
                fputcsv($handle, [
                    $entry['pre_simulacao_id'],
                    $entry['cpf'],
                    $entry['nome'],
                    $entry['nasc'],
                ], ';');
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
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
            $gender = IbgeName::query()->where('name', $this->upper($first))->value('gender');
            if (is_string($gender)) {
                $gender = strtoupper($gender);
                if ($gender === 'M') {
                    return 'Masculino';
                }
                if ($gender === 'F') {
                    return 'Feminino';
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

    private function formatPreSimulacaoStart(Carbon $startedAt): string
    {
        return $startedAt->copy()->setTimezone('America/Sao_Paulo')->format('Y-m-d\TH:i:s');
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

    private function finalizeJob(string $targetStatus): void
    {
        $this->logWarning('Finalizando job.', [
            'target_status' => $targetStatus,
        ]);
        FinalizeHubCreditoConsultReportJob::dispatchSync($this->jobId, $targetStatus);
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
}
