<?php

namespace App\Modules\V8Fgts\Jobs;

use App\Modules\V8Fgts\Models\V8FgtsConsultJob;
use App\Modules\V8Fgts\Models\V8FgtsConsultJobItem;
use App\Modules\V8Fgts\Services\V8FgtsApiService;
use App\Modules\V8Fgts\Support\V8FgtsBalanceClassifier;
use App\Modules\V8Fgts\Support\V8FgtsBalanceSelector;
use App\Modules\V8Fgts\Support\V8FgtsSchema;
use App\Modules\V8Fgts\Support\V8FgtsSimulationMapper;
use App\Support\Cpf;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessV8FgtsConsultBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    private string $disk;
    private int $jobId;
    private int $startMaxAttempts;
    private int $startRetryDelaySeconds;
    private int $pollingRoundDelaySeconds;
    private int $pollingTimeoutSeconds;
    private int $pollingMaxRounds;
    private int $selectionToleranceSeconds;
    private int $phase2SearchLimit;
    private int $phase1MinIntervalMs;
    private int $feesMinIntervalMs;
    private int $simulationMinIntervalMs;
    private int $maxRequestsPerRun;
    private int $maxRuntimeSeconds;
    private int $batchLockSeconds;
    private int $scheduleMinDelaySeconds;

    private ?array $feeContext = null;
    private ?string $feeErrorMessage = null;
    private mixed $feeErrorResponseBody = null;
    private float $startedAt = 0.0;
    private int $requestsUsed = 0;
    private int $processedItems = 0;
    private int $flushedRows = 0;

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;
        $this->onQueue((string) config('v8_fgts.job.queue', 'fgts'));

        $this->timeout = max(120, (int) config('v8_fgts.job.max_runtime_seconds', 90) + 120);
        $this->disk = (string) config('v8_fgts.storage.reports_disk', 'local');
        $this->startMaxAttempts = max(1, (int) config('v8_fgts.job.start_max_attempts', 3));
        $this->startRetryDelaySeconds = max(0, (int) config('v8_fgts.job.start_retry_delay_seconds', 30));
        $this->pollingRoundDelaySeconds = max(0, (int) config('v8_fgts.job.polling_round_delay_seconds', 20));
        $this->pollingTimeoutSeconds = max(60, (int) config('v8_fgts.job.polling_timeout_seconds', 900));
        $this->pollingMaxRounds = max(1, (int) config('v8_fgts.job.polling_max_rounds', 30));
        $this->selectionToleranceSeconds = max(0, (int) config('v8_fgts.job.selection_tolerance_seconds', 5));
        $this->phase2SearchLimit = max(1, (int) config('v8_fgts.job.phase2_search_limit', 50));
        $this->phase1MinIntervalMs = max(0, (int) config('v8_fgts.http.min_interval_ms_phase1', 10000));
        $this->feesMinIntervalMs = max(0, (int) config('v8_fgts.http.min_interval_ms_fees', 5000));
        $this->simulationMinIntervalMs = max(0, (int) config('v8_fgts.http.min_interval_ms_simulation', 5000));
        $this->maxRequestsPerRun = max(1, (int) config('v8_fgts.job.max_requests_per_run', 8));
        $this->maxRuntimeSeconds = max(10, (int) config('v8_fgts.job.max_runtime_seconds', 90));
        $this->batchLockSeconds = max(30, (int) config('v8_fgts.job.batch_lock_seconds', 180));
        $this->scheduleMinDelaySeconds = max(1, (int) config('v8_fgts.job.schedule_min_delay_seconds', 1));
    }

    public function handle(V8FgtsApiService $api): void
    {
        $job = V8FgtsConsultJob::query()->find($this->jobId);
        if (!$job || in_array($job->status, ['concluido', 'falhou'], true)) {
            return;
        }

        $lock = Cache::lock("v8_fgts_batch_job:{$this->jobId}", $this->batchLockSeconds);
        if (!$lock->get()) {
            return;
        }

        $nextDelayMs = null;
        $shouldFinalize = false;
        $finalStatus = 'concluido';

        try {
            $job->refresh();
            if ($job->status === 'cancelado') {
                $shouldFinalize = true;
                $finalStatus = 'falhou';
                return;
            }

            $this->startedAt = microtime(true);
            $api->setJobId($job->id);
            $api->setNonBlockingRateLimit(true);
            $job->update([
                'status' => 'em_progresso',
                'started_at' => $job->started_at ?? Carbon::now(),
            ]);

            while ($this->withinBudget()) {
                $item = $this->nextDueItem($job->id);
                if ($item === null) {
                    break;
                }

                $job->update(['phase' => $item->state === V8FgtsConsultJobItem::STATE_AWAITING_BALANCE ? 'polling_e_simulacao' : 'iniciar_saldo']);

                $delayMs = $item->state === V8FgtsConsultJobItem::STATE_QUEUED_START
                    ? $this->processStartItem($api, $job, $item)
                    : $this->processAwaitingBalanceItem($api, $job, $item);

                if ($delayMs !== null) {
                    $nextDelayMs = max($nextDelayMs ?? 0, $delayMs);
                    break;
                }
            }

            $this->flushTerminalRowsToSpool($job);

            if ($job->fresh()->status === 'cancelado') {
                $shouldFinalize = true;
                $finalStatus = 'falhou';
            } elseif (!$this->hasPendingItems($job->id)) {
                $shouldFinalize = true;
            } else {
                $nextDelayMs = max($nextDelayMs ?? 0, $this->nextDueDelayMs($job->id));
            }

            $this->logBatchSummary($job, $shouldFinalize, $nextDelayMs);
        } finally {
            optional($lock)->release();
        }

        if ($shouldFinalize) {
            $this->dispatchFinalize($finalStatus);
            return;
        }

        $this->scheduleSelf($nextDelayMs);
    }

    private function processStartItem(V8FgtsApiService $api, V8FgtsConsultJob $job, V8FgtsConsultJobItem $item): ?int
    {
        $cpf = $item->cpf;
        if (!Cpf::isValid($cpf)) {
            $this->markItemAsTerminal($job, $item, $this->finalRow($cpf, 'FALHA', 'CPF inválido (dígitos verificadores).'));
            return null;
        }

        $api->setRateLimitMs($this->phase1MinIntervalMs);
        $resp = $api->startBalance($cpf);
        $this->requestsUsed++;
        $this->processedItems++;

        if ($resp['ok'] ?? false) {
            $item->forceFill([
                'state' => V8FgtsConsultJobItem::STATE_AWAITING_BALANCE,
                'accepted_at' => Carbon::now('UTC'),
                'first_poll_at' => null,
                'start_attempts' => $item->start_attempts + 1,
                'poll_attempts' => 0,
                'last_message' => null,
                'api_error_context' => null,
                'next_run_at' => Carbon::now()->addSeconds($this->pollingRoundDelaySeconds),
                'last_phase2_snapshot' => null,
            ])->save();

            return null;
        }

        $classified = V8FgtsBalanceClassifier::classifyApiFailure($resp);
        $attempts = $item->start_attempts + 1;

        if (
            $classified['classification'] === V8FgtsBalanceClassifier::RETRYABLE
            && $attempts < $this->startMaxAttempts
        ) {
            $delaySeconds = max(
                $this->startRetryDelaySeconds,
                (int) ceil((int) ($api->lastSuggestedDelayMs() ?? 0) / 1000)
            );

            $item->forceFill([
                'start_attempts' => $attempts,
                'last_message' => $classified['message'],
                'next_run_at' => Carbon::now()->addSeconds($delaySeconds),
            ])->save();

            return $api->lastSuggestedDelayMs();
        }

        $extra = [];
        if ($classified['classification'] === V8FgtsBalanceClassifier::FALHA) {
            $extra['balance_start_response_body'] = $this->formatResponseBodyForCsv('iniciar_saldo', $resp['raw_body'] ?? null);
        }

        $row = $this->finalRow(
            $cpf,
            $classified['classification'] === V8FgtsBalanceClassifier::NAO_ELEGIVEL ? 'NAO_ELEGIVEL' : 'FALHA',
            $classified['message'],
            $extra
        );

        $this->markItemAsTerminal($job, $item, $row, $extra['balance_start_response_body'] ?? null);

        return $api->lastSuggestedDelayMs();
    }

    private function processAwaitingBalanceItem(V8FgtsApiService $api, V8FgtsConsultJob $job, V8FgtsConsultJobItem $item): ?int
    {
        $firstPollAt = $item->first_poll_at instanceof Carbon ? $item->first_poll_at : ($item->first_poll_at ? Carbon::parse($item->first_poll_at) : null);
        if ($firstPollAt !== null && $firstPollAt->copy()->addSeconds($this->pollingTimeoutSeconds)->isPast()) {
            $this->logPhase2Timeout($item->cpf, $item, null);
            $this->markItemAsTerminal($job, $item, $this->finalRow($item->cpf, 'FALHA', 'Timeout aguardando retorno do saldo FGTS.'));
            return null;
        }

        $result = $this->findBalanceBySearch($api, $item);
        if (($result['type'] ?? null) === 'deferred') {
            return (int) ($result['delay_ms'] ?? 0);
        }

        if (($result['type'] ?? null) === 'row') {
            $this->markItemAsTerminal(
                $job,
                $item,
                $result['row'],
                $result['api_error_context'] ?? null,
                $result['snapshot'] ?? null
            );
            return null;
        }

        if (($result['type'] ?? null) === 'match') {
            $matchResult = $this->rowFromMatchedBalanceItem($api, $item->cpf, $result['match']);
            if (($matchResult['type'] ?? null) === 'deferred') {
                $item->forceFill([
                    'first_poll_at' => $firstPollAt ?? Carbon::now('UTC'),
                    'last_message' => $matchResult['message'] ?? 'Aguardando nova tentativa.',
                    'next_run_at' => Carbon::now()->addSeconds(max(
                        $this->pollingRoundDelaySeconds,
                        (int) ceil(((int) ($matchResult['delay_ms'] ?? 0)) / 1000)
                    )),
                    'last_phase2_snapshot' => $this->shouldStorePhase2Snapshots() ? ($result['snapshot'] ?? null) : null,
                ])->save();

                return (int) ($matchResult['delay_ms'] ?? 0);
            }

            $row = $matchResult['row'];
            $apiContext = $row['balance_start_response_body'] ?? null;
            $this->markItemAsTerminal($job, $item, $row, is_string($apiContext) ? $apiContext : null, $result['snapshot'] ?? null);
            return null;
        }

        $firstPollAt ??= Carbon::now('UTC');
        $pollAttempts = $item->poll_attempts + 1;
        if (
            $pollAttempts >= $this->pollingMaxRounds
            || $firstPollAt->copy()->addSeconds($this->pollingTimeoutSeconds)->isPast()
        ) {
            $this->logPhase2Timeout($item->cpf, $item, $result['snapshot'] ?? null);
            $this->markItemAsTerminal($job, $item, $this->finalRow($item->cpf, 'FALHA', 'Timeout aguardando retorno do saldo FGTS.'), null, $result['snapshot'] ?? null);
            return null;
        }

        $item->forceFill([
            'first_poll_at' => $firstPollAt,
            'poll_attempts' => $pollAttempts,
            'last_message' => 'Saldo FGTS ainda não localizado.',
            'next_run_at' => Carbon::now()->addSeconds(max(
                $this->pollingRoundDelaySeconds,
                (int) ceil((int) ($api->lastSuggestedDelayMs() ?? 0) / 1000)
            )),
            'last_phase2_snapshot' => $this->shouldStorePhase2Snapshots() ? ($result['snapshot'] ?? null) : null,
        ])->save();

        $this->logPhase2Requeue($item->cpf, $item, 'saldo_nao_localizado');

        return $api->lastSuggestedDelayMs();
    }

    private function findBalanceBySearch(V8FgtsApiService $api, V8FgtsConsultJobItem $item): array
    {
        $page = 1;
        $totalPages = 1;
        $bestMatch = null;
        $snapshot = null;

        while ($page <= $totalPages) {
            $query = [
                'search' => $item->cpf,
                'limit' => $this->phase2SearchLimit,
                'page' => $page,
            ];

            $this->logPhase2ApiRequest($query, $item->cpf);
            $api->setRateLimitMs($this->phase1MinIntervalMs);
            $resp = $api->listBalances($query);
            $this->requestsUsed++;
            $this->logPhase2ApiResponse($query, $resp, $item->cpf);
            $snapshot = $this->buildPhase2Snapshot($query, $resp, $item->cpf);

            if (!($resp['ok'] ?? false)) {
                if ((bool) ($resp['retriable'] ?? false)) {
                    $item->forceFill([
                        'first_poll_at' => $item->first_poll_at ?? Carbon::now('UTC'),
                        'last_message' => $resp['error'] ?? 'Erro retentável ao consultar saldo.',
                        'next_run_at' => Carbon::now()->addSeconds(max(
                            $this->pollingRoundDelaySeconds,
                            (int) ceil((int) ($api->lastSuggestedDelayMs() ?? 0) / 1000)
                        )),
                        'last_phase2_snapshot' => $this->shouldStorePhase2Snapshots() ? $snapshot : null,
                    ])->save();

                    $this->logPhase2Requeue($item->cpf, $item, 'erro_retentavel_busca');

                    return [
                        'type' => 'deferred',
                        'delay_ms' => $api->lastSuggestedDelayMs() ?? 0,
                    ];
                }

                $classified = V8FgtsBalanceClassifier::classifyApiFailure($resp);
                $row = $this->finalRow(
                    $item->cpf,
                    $classified['classification'] === V8FgtsBalanceClassifier::NAO_ELEGIVEL ? 'NAO_ELEGIVEL' : 'FALHA',
                    $classified['message']
                );

                return [
                    'type' => 'row',
                    'row' => $row,
                    'snapshot' => $snapshot,
                ];
            }

            $pages = $resp['data']['pages'] ?? [];
            if (is_array($pages) && isset($pages['totalPages'])) {
                $totalPages = max(1, (int) $pages['totalPages']);
            }

            $items = $resp['data']['data'] ?? [];
            if (is_array($items)) {
                $candidates = $bestMatch !== null ? array_merge([$bestMatch], $items) : $items;
                $bestMatch = V8FgtsBalanceSelector::selectLatestRelevant(
                    $candidates,
                    $item->cpf,
                    $api->provider(),
                    (string) optional($item->accepted_at)->toIso8601String(),
                    $this->selectionToleranceSeconds
                );
            }

            $page++;
        }

        if ($bestMatch !== null) {
            return [
                'type' => 'match',
                'match' => $bestMatch,
                'snapshot' => $snapshot,
            ];
        }

        return [
            'type' => 'not_found',
            'snapshot' => $snapshot,
        ];
    }

    private function rowFromMatchedBalanceItem(V8FgtsApiService $api, string $cpf, array $match): array
    {
        $status = strtolower(trim((string) ($match['status'] ?? '')));
        if ($status === 'success') {
            return $this->simulateFromBalanceItem($api, $cpf, $match);
        }

        $statusInfo = is_string($match['statusInfo'] ?? null) ? trim((string) $match['statusInfo']) : 'Saldo FGTS não retornou sucesso.';
        $classification = V8FgtsBalanceClassifier::classifyPollingStatus($status, $statusInfo);
        $extra = [
            'provider' => strtolower(trim((string) ($match['provider'] ?? $api->provider()))),
        ];

        return [
            'type' => 'terminal',
            'row' => $this->finalRow(
                $cpf,
                $classification === V8FgtsBalanceClassifier::NAO_ELEGIVEL ? 'NAO_ELEGIVEL' : 'FALHA',
                $statusInfo,
                $extra
            ),
        ];
    }

    private function simulateFromBalanceItem(V8FgtsApiService $api, string $cpf, array $match): array
    {
        $provider = strtolower(trim((string) ($match['provider'] ?? $api->provider())));
        $balanceId = (string) ($match['id'] ?? '');
        $balanceAmount = is_numeric($match['amount'] ?? null) ? (float) $match['amount'] : null;
        $periods = is_array($match['periods'] ?? null) ? $match['periods'] : [];
        $desiredInstallments = V8FgtsSimulationMapper::mapDesiredInstallments($periods);
        $rowBase = [
            'provider' => $provider,
            'balance_id' => $balanceId,
            'balance_amount' => $balanceAmount,
            'periods_summary' => V8FgtsSimulationMapper::summarizePeriods($periods),
        ];

        if ($balanceId === '' || $desiredInstallments === []) {
            return [
                'type' => 'terminal',
                'row' => $this->finalRow($cpf, 'FALHA', 'Consulta sem balanceId ou parcelas válidas para simulação.', array_merge($rowBase, [
                    'balance_start_response_body' => $this->formatResponseBodyForCsv('simulacao_preparacao', null),
                ])),
            ];
        }

        $feeResult = $this->loadNormalFee($api);
        if (($feeResult['type'] ?? null) === 'deferred') {
            return [
                'type' => 'deferred',
                'delay_ms' => $feeResult['delay_ms'] ?? 0,
                'message' => 'Aguardando nova tentativa para carregar taxas.',
            ];
        }
        if (($feeResult['type'] ?? null) !== 'fee') {
            return [
                'type' => 'terminal',
                'row' => $this->finalRow($cpf, 'FALHA', $this->feeErrorMessage ?? 'Tabela normal indisponível para simulação.', array_merge($rowBase, [
                    'balance_start_response_body' => $this->formatResponseBodyForCsv('simulacao_taxas', $this->feeErrorResponseBody),
                ])),
            ];
        }
        $fee = $feeResult['fee'];

        $api->setRateLimitMs($this->simulationMinIntervalMs);
        $simResp = $api->createSimulation([
            'simulationFeesId' => $fee['id'],
            'balanceId' => $balanceId,
            'targetAmount' => (int) config('v8_fgts.bff.target_amount', 0),
            'documentNumber' => $cpf,
            'desiredInstallments' => $desiredInstallments,
            'provider' => $provider,
        ]);
        $this->requestsUsed++;

        $rowBase['simulation_fee_label'] = $fee['label'];
        $rowBase['simulation_fee_id'] = $fee['id'];

        if (!($simResp['ok'] ?? false)) {
            if ((bool) ($simResp['retriable'] ?? false)) {
                return [
                    'type' => 'deferred',
                    'delay_ms' => max(
                        (int) ($api->lastSuggestedDelayMs() ?? 0),
                        $this->pollingRoundDelaySeconds * 1000
                    ),
                    'message' => $simResp['error'] ?? 'Aguardando nova tentativa para simulação.',
                ];
            }

            $classified = V8FgtsBalanceClassifier::classifyApiFailure($simResp);
            if ($classified['classification'] === V8FgtsBalanceClassifier::NAO_ELEGIVEL) {
                return [
                    'type' => 'terminal',
                    'row' => $this->finalRow($cpf, 'NAO_ELEGIVEL', $classified['message'], $rowBase),
                ];
            }

            return [
                'type' => 'terminal',
                'row' => $this->finalRow($cpf, 'FALHA', (string) ($simResp['error'] ?? 'Falha ao criar simulação FGTS.'), array_merge($rowBase, [
                    'balance_start_response_body' => $this->formatResponseBodyForCsv('simulacao', $simResp['raw_body'] ?? null),
                ])),
            ];
        }

        $data = is_array($simResp['data'] ?? null) ? $simResp['data'] : [];

        return [
            'type' => 'terminal',
            'row' => $this->finalRow($cpf, 'SUCESSO', 'Simulação criada com sucesso.', array_merge($rowBase, [
                'simulation_id' => $data['id'] ?? null,
                'available_balance' => $data['availableBalance'] ?? null,
                'emission_amount' => $data['emissionAmount'] ?? null,
                'total_balance' => $data['totalBalance'] ?? null,
                'total_installments' => $data['totalInstallments'] ?? null,
                'tax' => $data['tax'] ?? null,
                'cet' => $data['cet'] ?? null,
                'annual_cet' => $data['annualCet'] ?? null,
                'iof' => $data['iof'] ?? null,
                'tc' => $data['tc'] ?? null,
            ])),
        ];
    }

    private function loadNormalFee(V8FgtsApiService $api): array
    {
        if ($this->feeContext !== null || $this->feeErrorMessage !== null) {
            return $this->feeContext !== null
                ? ['type' => 'fee', 'fee' => $this->feeContext]
                : ['type' => 'error'];
        }

        $cacheKey = 'v8_fgts_normal_fee:' . md5((string) config('v8_fgts.bff.base_url', '') . '|' . $api->provider());
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['id'], $cached['label'])) {
            $this->feeContext = $cached;
            return ['type' => 'fee', 'fee' => $this->feeContext];
        }

        $api->setRateLimitMs($this->feesMinIntervalMs);
        $resp = $api->getSimulationFees();
        $this->requestsUsed++;
        if (!($resp['ok'] ?? false)) {
            if ((bool) ($resp['retriable'] ?? false)) {
                return [
                    'type' => 'deferred',
                    'delay_ms' => max(
                        (int) ($api->lastSuggestedDelayMs() ?? 0),
                        $this->pollingRoundDelaySeconds * 1000
                    ),
                ];
            }

            $this->feeErrorMessage = (string) ($resp['error'] ?? 'Falha ao consultar tabelas de taxas.');
            $this->feeErrorResponseBody = $resp['raw_body'] ?? $resp['data'] ?? null;
            return ['type' => 'error'];
        }

        $fees = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        $selected = V8FgtsSimulationMapper::selectNormalFee($fees, (string) config('v8_fgts.bff.fee_label', 'normal'));
        if ($selected === null) {
            $this->feeErrorMessage = 'Tabela normal não encontrada para simulação.';
            $this->feeErrorResponseBody = $fees;
            return ['type' => 'error'];
        }

        $this->feeContext = $selected;
        Cache::put($cacheKey, $selected, (int) config('v8_fgts.bff.fee_cache_ttl_seconds', 300));

        return ['type' => 'fee', 'fee' => $this->feeContext];
    }

    private function markItemAsTerminal(
        V8FgtsConsultJob $job,
        V8FgtsConsultJobItem $item,
        array $row,
        ?string $apiErrorContext = null,
        ?array $snapshot = null
    ): void {
        $item->forceFill([
            'state' => V8FgtsConsultJobItem::STATE_TERMINAL,
            'next_run_at' => null,
            'last_message' => (string) ($row['mensagem'] ?? ''),
            'api_error_context' => $apiErrorContext,
            'last_phase2_snapshot' => $this->shouldStorePhase2Snapshots() ? $snapshot : null,
            'result_row' => $row,
        ])->save();
    }

    private function flushTerminalRowsToSpool(V8FgtsConsultJob $job): void
    {
        $items = V8FgtsConsultJobItem::query()
            ->where('job_id', $job->id)
            ->where('state', V8FgtsConsultJobItem::STATE_TERMINAL)
            ->whereNull('spool_written_at')
            ->orderBy('id')
            ->limit(500)
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $disk = Storage::disk($this->disk);
        $real = $disk->path((string) $job->spool_path);
        $fp = @fopen($real, 'a');
        if (!is_resource($fp)) {
            throw new \RuntimeException('Falha ao abrir spool para append.');
        }

        $statusCounts = [
            'SUCESSO' => 0,
            'NAO_ELEGIVEL' => 0,
            'FALHA' => 0,
        ];

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new \RuntimeException('Falha ao travar spool para append.');
            }

            foreach ($items as $item) {
                $row = is_array($item->result_row) ? $item->result_row : [];
                $ordered = [];
                foreach (V8FgtsSchema::COLS as $key) {
                    $ordered[] = $row[$key] ?? null;
                }
                fputcsv($fp, $ordered, ';');
                $status = (string) ($row['status'] ?? 'FALHA');
                if (isset($statusCounts[$status])) {
                    $statusCounts[$status]++;
                }
            }

            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        $ids = $items->pluck('id')->all();
        V8FgtsConsultJobItem::query()
            ->whereIn('id', $ids)
            ->whereNull('spool_written_at')
            ->update(['spool_written_at' => Carbon::now()]);

        clearstatcache(true, $real);
        $bytes = file_exists($real) ? (int) filesize($real) : 0;

        DB::table('v8_fgts_consult_jobs')
            ->where('id', $job->id)
            ->update([
                'success_count' => DB::raw('COALESCE(success_count,0) + ' . $statusCounts['SUCESSO']),
                'nao_elegivel_count' => DB::raw('COALESCE(nao_elegivel_count,0) + ' . $statusCounts['NAO_ELEGIVEL']),
                'fail_count' => DB::raw('COALESCE(fail_count,0) + ' . $statusCounts['FALHA']),
                'spool_bytes' => $bytes,
                'updated_at' => Carbon::now(),
            ]);

        $this->flushedRows += count($ids);
    }

    private function nextDueItem(int $jobId): ?V8FgtsConsultJobItem
    {
        return V8FgtsConsultJobItem::query()
            ->where('job_id', $jobId)
            ->whereIn('state', [
                V8FgtsConsultJobItem::STATE_AWAITING_BALANCE,
                V8FgtsConsultJobItem::STATE_QUEUED_START,
            ])
            ->where(function ($query) {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', Carbon::now());
            })
            ->orderByRaw("CASE WHEN state = '" . V8FgtsConsultJobItem::STATE_AWAITING_BALANCE . "' THEN 0 ELSE 1 END")
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->first();
    }

    private function hasPendingItems(int $jobId): bool
    {
        return V8FgtsConsultJobItem::query()
            ->where('job_id', $jobId)
            ->whereIn('state', [
                V8FgtsConsultJobItem::STATE_QUEUED_START,
                V8FgtsConsultJobItem::STATE_AWAITING_BALANCE,
            ])
            ->exists();
    }

    private function nextDueDelayMs(int $jobId): int
    {
        $nextRunAt = V8FgtsConsultJobItem::query()
            ->where('job_id', $jobId)
            ->whereIn('state', [
                V8FgtsConsultJobItem::STATE_QUEUED_START,
                V8FgtsConsultJobItem::STATE_AWAITING_BALANCE,
            ])
            ->orderByRaw('CASE WHEN next_run_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('next_run_at')
            ->value('next_run_at');

        if ($nextRunAt === null) {
            return 0;
        }

        $next = Carbon::parse($nextRunAt);

        return max(0, Carbon::now()->diffInMilliseconds($next, false));
    }

    private function withinBudget(): bool
    {
        if ($this->requestsUsed >= $this->maxRequestsPerRun) {
            return false;
        }

        return (microtime(true) - $this->startedAt) < $this->maxRuntimeSeconds;
    }

    private function scheduleSelf(?int $delayMs = null): void
    {
        $delaySeconds = max(
            $this->scheduleMinDelaySeconds,
            (int) ceil(max(0, (int) ($delayMs ?? 0)) / 1000)
        );

        if ((string) config('queue.default') === 'sync' && $delaySeconds > 0) {
            return;
        }

        $dispatch = self::dispatch($this->jobId)->onQueue((string) config('v8_fgts.job.queue', 'fgts'));
        if ($delaySeconds > 0) {
            $dispatch->delay(now()->addSeconds($delaySeconds));
        }
    }

    private function dispatchFinalize(string $targetStatus): void
    {
        FinalizeV8FgtsConsultReportJob::dispatch($this->jobId, $targetStatus)
            ->onQueue((string) config('v8_fgts.preview.queue', 'reports'));
    }

    private function finalRow(string $cpf, string $status, string $message, array $extra = []): array
    {
        $row = array_fill_keys(V8FgtsSchema::COLS, null);
        $row['cpf'] = $cpf;
        $row['status'] = $status;
        $row['mensagem'] = $message;
        $row['finished_at'] = Carbon::now('America/Sao_Paulo')->toDateTimeString();

        foreach ($extra as $key => $value) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $value;
            }
        }

        return $row;
    }

    private function formatResponseBodyForCsv(string $stage, mixed $body): ?string
    {
        if (is_array($body) || is_object($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (!is_string($body)) {
            return $stage;
        }

        $body = trim($body);

        return $body !== '' ? ($stage . ' | ' . $body) : $stage;
    }

    private function buildPhase2Snapshot(array $query, array $resp, string $cpf): array
    {
        $snapshot = [
            'query' => $query,
            'status' => $resp['status'] ?? null,
            'ok' => (bool) ($resp['ok'] ?? false),
            'retriable' => (bool) ($resp['retriable'] ?? false),
        ];

        if (!($resp['ok'] ?? false)) {
            $snapshot['error'] = $resp['error'] ?? null;
            return $snapshot;
        }

        $data = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        $items = is_array($data['data'] ?? null) ? $data['data'] : [];
        $snapshot['pages'] = $data['pages'] ?? null;
        $snapshot['items_count'] = count($items);
        $snapshot['relevant_items'] = array_values(array_filter(array_map(function ($item) use ($cpf) {
            if (!is_array($item)) {
                return null;
            }

            $documentNumber = preg_replace('/\D+/', '', (string) ($item['documentNumber'] ?? ''));
            if ($documentNumber !== $cpf) {
                return null;
            }

            return [
                'id' => $item['id'] ?? null,
                'status' => $item['status'] ?? null,
                'statusInfo' => $item['statusInfo'] ?? null,
                'createdAt' => $item['createdAt'] ?? null,
                'updatedAt' => $item['updatedAt'] ?? null,
                'amount' => $item['amount'] ?? null,
            ];
        }, $items)));

        return $snapshot;
    }

    private function logPhase2ApiRequest(array $query, string $cpf): void
    {
        if (!(bool) config('v8_fgts.logging.phase2_requests', false)) {
            return;
        }

        Log::warning('[V8-FGTS] Fase 2 requisicao da consulta de saldo', [
            'job_id' => $this->jobId,
            'method' => 'GET',
            'path' => '/fgts/balance',
            'query' => $query,
            'focus_cpfs_count' => 1,
            'focus_cpfs' => [$cpf],
        ]);
    }

    private function logPhase2ApiResponse(array $query, array $resp, string $cpf): void
    {
        $isError = !($resp['ok'] ?? false);
        if (
            ($isError && !(bool) config('v8_fgts.logging.phase2_error_responses', true))
            || (!$isError && !(bool) config('v8_fgts.logging.phase2_success_responses', false))
        ) {
            return;
        }

        Log::warning('[V8-FGTS] Fase 2 resposta da consulta de saldo', [
            'job_id' => $this->jobId,
            'query' => $query,
            'status' => $resp['status'] ?? null,
            'ok' => (bool) ($resp['ok'] ?? false),
            'retriable' => (bool) ($resp['retriable'] ?? false),
            'focus_cpfs' => [$cpf],
            'payload' => $this->buildPhase2Snapshot($query, $resp, $cpf),
        ]);
    }

    private function logPhase2Requeue(string $cpf, V8FgtsConsultJobItem $item, string $reason): void
    {
        if (!(bool) config('v8_fgts.logging.phase2_pending_requeues', false)) {
            return;
        }

        Log::info('[V8-FGTS] Fase 2 CPF mantido em pending', [
            'job_id' => $this->jobId,
            'cpf' => $cpf,
            'reason' => $reason,
            'accepted_at' => optional($item->accepted_at)->toIso8601String(),
            'first_poll_at' => optional($item->first_poll_at)->toIso8601String(),
            'poll_attempts' => $item->poll_attempts,
        ]);
    }

    private function logPhase2Timeout(string $cpf, V8FgtsConsultJobItem $item, ?array $snapshot): void
    {
        Log::warning('[V8-FGTS] Fase 2 timeout aguardando saldo', [
            'job_id' => $this->jobId,
            'cpf' => $cpf,
            'accepted_at' => optional($item->accepted_at)->toIso8601String(),
            'first_poll_at' => optional($item->first_poll_at)->toIso8601String(),
            'poll_attempts' => $item->poll_attempts,
            'polling_timeout_seconds' => $this->pollingTimeoutSeconds,
            'polling_max_rounds' => $this->pollingMaxRounds,
            'last_phase2_response' => $snapshot ?? $item->last_phase2_snapshot,
        ]);
    }

    private function shouldStorePhase2Snapshots(): bool
    {
        return (bool) config('v8_fgts.logging.store_phase2_snapshots', false);
    }

    private function logBatchSummary(V8FgtsConsultJob $job, bool $shouldFinalize, ?int $nextDelayMs): void
    {
        if (!(bool) config('v8_fgts.logging.batch_summary', true)) {
            return;
        }

        Log::info('[V8-FGTS] Resumo do batch', [
            'job_id' => $job->id,
            'phase' => $job->phase,
            'requests_used' => $this->requestsUsed,
            'processed_items' => $this->processedItems,
            'flushed_rows' => $this->flushedRows,
            'should_finalize' => $shouldFinalize,
            'next_delay_ms' => $nextDelayMs,
        ]);
    }
}
