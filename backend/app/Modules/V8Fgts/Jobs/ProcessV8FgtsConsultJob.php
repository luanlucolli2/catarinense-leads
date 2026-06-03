<?php

namespace App\Modules\V8Fgts\Jobs;

use App\Modules\V8Fgts\Models\V8FgtsConsultJob;
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
use Throwable;

class ProcessV8FgtsConsultJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout;

    private string $disk;
    private string $dirSpool;
    private string $finalPrefix;
    private int $jobId;
    private int $flushEveryRows;
    private int $flushEverySecs;
    private int $flushBytesStep;
    private int $dedupeBlockSize;
    private int $startBuffer;
    private int $pollingBuffer;
    private int $startMaxAttempts;
    private int $startRetryDelaySeconds;
    private int $pollingRoundDelaySeconds;
    private int $pollingTimeoutSeconds;
    private int $pollingMaxRounds;
    private int $selectionToleranceSeconds;
    private int $phase2PageLimit;
    private int $phase2SearchFallbackLimit;
    private int $phase2SearchFallbackRoundStart;
    private int $phase2SearchFallbackPendingThreshold;
    private int $phase2PlainSearchLastResortRoundStart;
    private int $phase1MinIntervalMs;
    private int $pollingMinIntervalMs;
    private int $feesMinIntervalMs;
    private int $simulationMinIntervalMs;
    private int $phase1RateLimitCooldownMs;

    private array $pendFiles = [];
    private $spoolFp = null;
    private string $spoolReal = '';
    private float $nextFlushAt = 0.0;
    private int $rowsSinceFlush = 0;
    private int $lastFlushedBytes = 0;
    private int $accSuccess = 0;
    private int $accNaoElegivel = 0;
    private int $accFail = 0;
    private ?array $feeContext = null;
    private ?string $feeErrorMessage = null;
    private bool $phase1PacingEscalated = false;

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;
        $this->onQueue((string) config('v8_fgts.job.queue', 'v8-fgts'));

        $this->timeout = (int) config('v8_fgts.job.timeout_seconds', 21600);
        $this->disk = (string) config('v8_fgts.storage.reports_disk', 'local');
        $this->dirSpool = (string) config('v8_fgts.storage.dir_spool', 'v8-fgts-spool');
        $this->finalPrefix = (string) config('v8_fgts.storage.final_prefix', 'v8-fgts-consulta');
        $this->flushEveryRows = max(1, (int) config('v8_fgts.job.flush_rows', 4000));
        $this->flushEverySecs = max(1, (int) config('v8_fgts.job.flush_seconds', 5));
        $this->flushBytesStep = max(1024, (int) config('v8_fgts.job.flush_bytes_step', 262144));
        $this->dedupeBlockSize = max(1000, (int) config('v8_fgts.job.dedupe_block_size', 5000));
        $this->startBuffer = max(1, (int) config('v8_fgts.job.start_buffer', 12));
        $this->pollingBuffer = max(1, (int) config('v8_fgts.job.polling_buffer', 80));
        $this->startMaxAttempts = max(1, (int) config('v8_fgts.job.start_max_attempts', 3));
        $this->startRetryDelaySeconds = max(0, (int) config('v8_fgts.job.start_retry_delay_seconds', 30));
        $this->pollingRoundDelaySeconds = max(0, (int) config('v8_fgts.job.polling_round_delay_seconds', 20));
        $this->pollingTimeoutSeconds = max(60, (int) config('v8_fgts.job.polling_timeout_seconds', 900));
        $this->pollingMaxRounds = max(1, (int) config('v8_fgts.job.polling_max_rounds', 30));
        $this->selectionToleranceSeconds = max(0, (int) config('v8_fgts.job.selection_tolerance_seconds', 5));
        $this->phase2PageLimit = max(1, min(50, (int) config('v8_fgts.job.phase2_page_limit', 50)));
        $this->phase2SearchFallbackLimit = max(1, (int) config('v8_fgts.job.phase2_search_fallback_limit', 50));
        $this->phase2SearchFallbackRoundStart = max(1, (int) config('v8_fgts.job.phase2_search_fallback_round_start', 3));
        $this->phase2SearchFallbackPendingThreshold = max(1, (int) config('v8_fgts.job.phase2_search_fallback_pending_threshold', 50));
        $this->phase2PlainSearchLastResortRoundStart = max(1, (int) config('v8_fgts.job.phase2_plain_search_last_resort_round_start', 25));
        $this->phase1MinIntervalMs = max(0, (int) config('v8_fgts.http.min_interval_ms_phase1', 10000));
        $this->pollingMinIntervalMs = max(0, (int) config('v8_fgts.http.min_interval_ms_polling', 10000));
        $this->feesMinIntervalMs = max(0, (int) config('v8_fgts.http.min_interval_ms_fees', 2000));
        $this->simulationMinIntervalMs = max(0, (int) config('v8_fgts.http.min_interval_ms_simulation', 2000));
        $this->phase1RateLimitCooldownMs = max(
            1000,
            (((int) config('v8_fgts.http.rate_limit_sleep_seconds', 15)) * 1000) + 1000
        );
    }

    public function handle(V8FgtsApiService $api): void
    {
        $job = V8FgtsConsultJob::query()->whereKey($this->jobId)->first();
        if (!$job) {
            $this->deletePendFiles();
            return;
        }

        if ($job->status === 'cancelado') {
            $this->dispatchFinalize('falhou');
            return;
        }

        $disk = Storage::disk($this->disk);
        if (
            empty($job->spool_path)
            || empty($job->spool_cpfs_path)
            || !$disk->exists($job->spool_path)
            || !$disk->exists($job->spool_cpfs_path)
        ) {
            Log::error("[V8-FGTS] Job {$this->jobId} sem spool pré-criado.");
            $this->dispatchFinalize('falhou');
            return;
        }

        $job->update([
            'status' => 'em_progresso',
            'started_at' => $job->started_at ?? Carbon::now(),
            'phase' => 'iniciar_saldo',
            'spool_bytes' => $this->fileSizeSafe($this->disk, $job->spool_path),
        ]);

        $api->setJobId($job->id);
        $this->spoolReal = $disk->path($job->spool_path);
        $this->spoolFp = @fopen($this->spoolReal, 'a');
        if (!is_resource($this->spoolFp)) {
            Log::error("[V8-FGTS] Job {$this->jobId} falha ao abrir spool para append.");
            $this->dispatchFinalize('falhou');
            return;
        }

        $this->lastFlushedBytes = $this->fileSizeSafe($this->disk, $job->spool_path);
        $this->nextFlushAt = microtime(true) + $this->flushEverySecs;

        try {
            $uniqRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.cpfs.uniq.txt";
            $this->pendFiles[] = $uniqRel;
            $uniqueCount = $this->buildUniqueCpfsFile($disk->path($job->spool_cpfs_path), $uniqRel);
            if ($uniqueCount === 0) {
                $this->dispatchFinalize('falhou');
                return;
            }

            $this->updateTotalsThrottled($job, ['total_cpfs' => $uniqueCount], true);

            $pendingRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.pending.balance.txt";
            $this->pendFiles[] = $pendingRel;
            if (!$this->runPhase1($api, $job, $disk->path($uniqRel), $disk->path($pendingRel))) {
                return;
            }

            if ($this->finishIfCancelled($job)) {
                return;
            }

            $job->update(['phase' => 'polling_e_simulacao']);
            $this->loadNormalFee($api);

            if (!$this->runPhase2($api, $job, $pendingRel)) {
                return;
            }

            $this->updateTotalsThrottled($job, [], true);
            $this->dispatchFinalize('concluido');
        } catch (Throwable $e) {
            Log::error("[V8-FGTS] Job {$this->jobId} falhou: " . $e->getMessage(), ['exception' => $e]);
            $this->dispatchFinalize('falhou');
        } finally {
            $this->closeSpoolWriter();
            $this->deletePendFiles();
        }
    }

    private function runPhase1(V8FgtsApiService $api, V8FgtsConsultJob $job, string $uniqReal, string $pendingReal): bool
    {
        $reader = fopen($uniqReal, 'r');
        $pendingHandle = fopen($pendingReal, 'w');
        if ($reader === false || $pendingHandle === false) {
            if (is_resource($reader)) {
                fclose($reader);
            }
            if (is_resource($pendingHandle)) {
                fclose($pendingHandle);
            }

            $this->dispatchFinalize('falhou');
            return false;
        }

        try {
            $buffer = [];
            while (($line = fgets($reader)) !== false) {
                if ($this->finishIfCancelled($job)) {
                    return false;
                }

                $cpf = preg_replace('/\D+/', '', (string) $line);
                if ($cpf === '' || strlen($cpf) !== 11) {
                    continue;
                }

                $buffer[] = $cpf;
                if (count($buffer) >= $this->startBuffer) {
                    if (!$this->processStartBuffer($api, $job, $buffer, $pendingHandle)) {
                        return false;
                    }
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                if (!$this->processStartBuffer($api, $job, $buffer, $pendingHandle)) {
                    return false;
                }
            }
        } finally {
            fclose($reader);
            fflush($pendingHandle);
            fclose($pendingHandle);
        }

        return true;
    }

    private function processStartBuffer(V8FgtsApiService $api, V8FgtsConsultJob $job, array $cpfs, $pendingHandle): bool
    {
        $rows = [];

        foreach ($cpfs as $cpf) {
            $api->setRateLimitMs($this->phase1MinIntervalMs);

            if (!Cpf::isValid($cpf)) {
                $rows[] = $this->finalRow($cpf, 'FALHA', 'CPF inválido (dígitos verificadores).');
                $this->accFail++;
                continue;
            }

            $result = $this->startBalanceWithRetries($api, $job, $cpf);
            if ($result['type'] === 'cancelled') {
                return false;
            }

            if ($result['type'] === 'accepted') {
                fwrite($pendingHandle, $cpf . ';0;' . $result['accepted_at'] . "\n");
                continue;
            }

            $rows[] = $result['row'];
        }

        if ($rows !== []) {
            $this->spoolAppendManyPersist($job, $rows);
        }

        return true;
    }

    private function startBalanceWithRetries(V8FgtsApiService $api, V8FgtsConsultJob $job, string $cpf): array
    {
        for ($attempt = 1; $attempt <= $this->startMaxAttempts; $attempt++) {
            if ($this->finishIfCancelled($job)) {
                return [
                    'type' => 'cancelled',
                ];
            }

            $resp = $api->startBalance($cpf);
            if (($resp['status'] ?? null) === 429) {
                $this->escalatePhase1Pacing();
            }

            if ($resp['ok'] ?? false) {
                return [
                    'type' => 'accepted',
                    'accepted_at' => Carbon::now('UTC')->toIso8601String(),
                ];
            }

            $classified = V8FgtsBalanceClassifier::classifyApiFailure($resp);
            if ($classified['classification'] === V8FgtsBalanceClassifier::NAO_ELEGIVEL) {
                $this->accNaoElegivel++;

                return [
                    'type' => 'row',
                    'row' => $this->finalRow($cpf, 'NAO_ELEGIVEL', $classified['message']),
                ];
            }

            if ($classified['classification'] === V8FgtsBalanceClassifier::RETRYABLE && $attempt < $this->startMaxAttempts) {
                if (!$this->sleepWithCancel($job, $this->startRetryDelaySeconds)) {
                    return [
                        'type' => 'cancelled',
                    ];
                }

                continue;
            }

            $this->accFail++;

            return [
                'type' => 'row',
                'row' => $this->finalRow($cpf, 'FALHA', $classified['message'], [
                    'balance_start_response_body' => $this->formatResponseBodyForCsv('iniciar_saldo', $resp['raw_body'] ?? null),
                ]),
            ];
        }

        $this->accFail++;

        return [
            'type' => 'row',
            'row' => $this->finalRow($cpf, 'FALHA', 'Não foi possível iniciar a consulta de saldo.', [
                'balance_start_response_body' => $this->formatResponseBodyForCsv('iniciar_saldo', $resp['raw_body'] ?? null),
            ]),
        ];
    }

    private function escalatePhase1Pacing(): void
    {
        if ($this->phase1MinIntervalMs < $this->phase1RateLimitCooldownMs) {
            $this->phase1MinIntervalMs = $this->phase1RateLimitCooldownMs;
        }

        if ($this->phase1PacingEscalated) {
            return;
        }

        $this->phase1PacingEscalated = true;
        Log::warning("[V8-FGTS] Fase 1 entrou em pacing conservador após 429.", [
            'job_id' => $this->jobId,
            'phase1_min_interval_ms' => $this->phase1MinIntervalMs,
        ]);
    }

    private function runPhase2(V8FgtsApiService $api, V8FgtsConsultJob $job, string $initialPendingRel): bool
    {
        $currentPendingRel = $initialPendingRel;

        for ($round = 1; $round <= $this->pollingMaxRounds; $round++) {
            if ($this->finishIfCancelled($job)) {
                return false;
            }

            $currentReal = Storage::disk($this->disk)->path($currentPendingRel);
            if (!file_exists($currentReal) || filesize($currentReal) === 0) {
                return true;
            }

            $pendingEntries = $this->loadPendingEntries($currentReal);
            if ($pendingEntries === []) {
                @unlink($currentReal);
                return true;
            }

            $nextRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.pending.round{$round}.txt";
            $this->pendFiles[] = $nextRel;
            $nextReal = Storage::disk($this->disk)->path($nextRel);
            $nextHandle = fopen($nextReal, 'w');
            if ($nextHandle === false) {
                if (is_resource($nextHandle)) {
                    fclose($nextHandle);
                }
                $this->dispatchFinalize('falhou');
                return false;
            }

            try {
                $rows = [];
                $activeEntries = [];
                foreach ($pendingEntries as $cpf => $entry) {
                    if ($this->isPendingExpired($entry['accepted_at']) || ($entry['attempts'] + 1) >= $this->pollingMaxRounds) {
                        $rows[] = $this->finalRow($cpf, 'FALHA', 'Timeout aguardando retorno do saldo FGTS.');
                        $this->accFail++;
                        continue;
                    }

                    $activeEntries[$cpf] = $entry;
                }

                if ($activeEntries === []) {
                    if ($rows !== []) {
                        $this->spoolAppendManyPersist($job, $rows);
                    }
                } else {
                    [$windowStart, $windowEnd] = $this->resolvePhase2Window($job);
                    $scan = $this->fetchPhase2MatchesByWindow($api, $job, $activeEntries, $windowStart, $windowEnd);

                    if (!($scan['ok'] ?? false)) {
                        if (!empty($scan['stopped'])) {
                            return false;
                        }

                        if (!empty($scan['retriable'])) {
                            $this->writePendingEntries($nextHandle, $activeEntries, true);
                        } else {
                            $this->appendPendingEntriesAsErrorRows($job, $activeEntries, (string) ($scan['error'] ?? 'Falha ao consultar saldo FGTS.'));
                        }

                        if ($rows !== []) {
                            $this->spoolAppendManyPersist($job, $rows);
                        }
                    } else {
                        $matches = is_array($scan['matches'] ?? null) ? $scan['matches'] : [];
                        $remainingEntries = array_diff_key($activeEntries, $matches);

                        $fallbackRows = [];
                        if (
                            $remainingEntries !== []
                            && $this->shouldUseSearchFallback($round, count($remainingEntries))
                        ) {
                            $fallback = $this->resolvePhase2SearchFallbacks($api, $job, $remainingEntries, $round, $windowStart, $windowEnd);
                            if (!empty($fallback['stopped'])) {
                                return false;
                            }

                            $matches = array_replace($matches, $fallback['matches'] ?? []);
                            $fallbackRows = $fallback['rows'] ?? [];
                            $remainingEntries = $fallback['requeue'] ?? [];
                        }

                        foreach ($activeEntries as $cpf => $entry) {
                            if (isset($matches[$cpf]) && is_array($matches[$cpf])) {
                                $rows[] = $this->rowFromMatchedBalanceItem($api, $cpf, $matches[$cpf]);
                                continue;
                            }

                            if (isset($fallbackRows[$cpf]) && is_array($fallbackRows[$cpf])) {
                                $rows[] = $fallbackRows[$cpf];
                                continue;
                            }

                            if (isset($remainingEntries[$cpf])) {
                                fwrite($nextHandle, $cpf . ';' . ($entry['attempts'] + 1) . ';' . $entry['accepted_at'] . "\n");
                            }
                        }

                        if ($rows !== []) {
                            $this->spoolAppendManyPersist($job, $rows);
                        }
                    }
                }
            } finally {
                fflush($nextHandle);
                fclose($nextHandle);
            }

            @unlink($currentReal);
            $currentPendingRel = $nextRel;
            $currentReal = $nextReal;

            clearstatcache(true, $currentReal);
            if (!file_exists($currentReal) || filesize($currentReal) === 0) {
                return true;
            }

            if ($round >= $this->pollingMaxRounds) {
                $this->flushRemainingPendingAsTimeout($job, $currentReal);
                return true;
            }

            if (!$this->sleepWithCancel($job, $this->pollingRoundDelaySeconds)) {
                return false;
            }
        }

        return true;
    }

    private function fetchPhase2MatchesByWindow(
        V8FgtsApiService $api,
        V8FgtsConsultJob $job,
        array $pendingEntries,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate
    ): array
    {
        $matches = [];
        $page = 1;
        $totalPages = 1;
        $queryBase = [
            'startDate' => $this->formatApiDateTime($startDate),
            'endDate' => $this->formatApiDateTime($endDate),
            'limit' => $this->phase2PageLimit,
        ];

        while ($page <= $totalPages) {
            if ($this->finishIfCancelled($job)) {
                return [
                    'ok' => false,
                    'stopped' => true,
                ];
            }

            $api->setRateLimitMs($this->pollingMinIntervalMs);
            $resp = $api->listBalances($queryBase + ['page' => $page]);
            if (!($resp['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => (string) ($resp['error'] ?? 'Falha ao consultar saldo FGTS.'),
                    'retriable' => (bool) ($resp['retriable'] ?? false),
                ];
            }

            $pages = $resp['data']['pages'] ?? [];
            if (is_array($pages) && isset($pages['totalPages'])) {
                $totalPages = max(1, (int) $pages['totalPages']);
            }

            $items = $resp['data']['data'] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $cpf = preg_replace('/\D+/', '', (string) ($item['documentNumber'] ?? ''));
                    if ($cpf === '' || !isset($pendingEntries[$cpf])) {
                        continue;
                    }

                    $acceptedAt = $pendingEntries[$cpf]['accepted_at'];
                    $candidates = isset($matches[$cpf]) ? [$matches[$cpf], $item] : [$item];
                    $selected = V8FgtsBalanceSelector::selectLatestRelevant(
                        $candidates,
                        $cpf,
                        $api->provider(),
                        $acceptedAt,
                        $this->selectionToleranceSeconds
                    );

                    if ($selected !== null) {
                        $matches[$cpf] = $selected;
                    }
                }
            }

            $page++;
        }

        return [
            'ok' => true,
            'matches' => $matches,
        ];
    }

    private function resolvePhase2SearchFallbacks(
        V8FgtsApiService $api,
        V8FgtsConsultJob $job,
        array $entries,
        int $round,
        CarbonImmutable $startDate,
        CarbonImmutable $endDate
    ): array {
        $matches = [];
        $rows = [];
        $requeue = [];

        foreach ($entries as $cpf => $entry) {
            if ($this->finishIfCancelled($job)) {
                return ['stopped' => true];
            }

            $result = $this->findBalanceBySearch($api, $entry, $startDate, $endDate);
            if (($result['type'] ?? null) === 'not_found' && $round >= $this->phase2PlainSearchLastResortRoundStart) {
                $result = $this->findBalanceBySearch($api, $entry, null, null);
            }

            if (($result['type'] ?? null) === 'match') {
                $matches[$cpf] = $result['match'];
                continue;
            }

            if (($result['type'] ?? null) === 'row') {
                $rows[$cpf] = $result['row'];
                continue;
            }

            $requeue[$cpf] = $entry;
        }

        return [
            'matches' => $matches,
            'rows' => $rows,
            'requeue' => $requeue,
        ];
    }

    private function findBalanceBySearch(
        V8FgtsApiService $api,
        array $entry,
        ?CarbonImmutable $startDate,
        ?CarbonImmutable $endDate
    ): array {
        $cpf = $entry['cpf'];
        $acceptedAt = $entry['accepted_at'];
        $page = 1;
        $totalPages = 1;
        $bestMatch = null;

        while ($page <= $totalPages) {
            $query = [
                'search' => $cpf,
                'limit' => $this->phase2SearchFallbackLimit,
                'page' => $page,
            ];

            if ($startDate !== null && $endDate !== null) {
                $query['startDate'] = $this->formatApiDateTime($startDate);
                $query['endDate'] = $this->formatApiDateTime($endDate);
            }

            $api->setRateLimitMs($this->pollingMinIntervalMs);
            $resp = $api->listBalances($query);
            if (!($resp['ok'] ?? false)) {
                if ((bool) ($resp['retriable'] ?? false)) {
                    return ['type' => 'requeue'];
                }

                $classified = V8FgtsBalanceClassifier::classifyApiFailure($resp);
                if ($classified['classification'] === V8FgtsBalanceClassifier::NAO_ELEGIVEL) {
                    $this->accNaoElegivel++;
                    return [
                        'type' => 'row',
                        'row' => $this->finalRow($cpf, 'NAO_ELEGIVEL', $classified['message']),
                    ];
                }

                $this->accFail++;
                return [
                    'type' => 'row',
                    'row' => $this->finalRow($cpf, 'FALHA', $classified['message']),
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
                    $cpf,
                    $api->provider(),
                    $acceptedAt,
                    $this->selectionToleranceSeconds
                );
            }

            $page++;
        }

        if ($bestMatch !== null) {
            return [
                'type' => 'match',
                'match' => $bestMatch,
            ];
        }

        return ['type' => 'not_found'];
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

        if ($classification === V8FgtsBalanceClassifier::NAO_ELEGIVEL) {
            $this->accNaoElegivel++;
            return $this->finalRow($cpf, 'NAO_ELEGIVEL', $statusInfo, $extra);
        }

        $this->accFail++;
        return $this->finalRow($cpf, 'FALHA', $statusInfo, $extra);
    }

    private function shouldUseSearchFallback(int $round, int $remainingCount): bool
    {
        return $round >= $this->phase2SearchFallbackRoundStart
            || $remainingCount <= $this->phase2SearchFallbackPendingThreshold;
    }

    private function loadPendingEntries(string $realPath): array
    {
        $entries = [];
        $reader = fopen($realPath, 'r');
        if ($reader === false) {
            return $entries;
        }

        try {
            while (($line = fgets($reader)) !== false) {
                $entry = $this->parsePendingLine($line);
                if ($entry === null) {
                    continue;
                }

                $entries[$entry['cpf']] = $entry;
            }
        } finally {
            fclose($reader);
        }

        return $entries;
    }

    private function writePendingEntries($handle, array $entries, bool $incrementAttempts = true): int
    {
        $written = 0;

        foreach ($entries as $entry) {
            $attempts = max(0, (int) ($entry['attempts'] ?? 0)) + ($incrementAttempts ? 1 : 0);
            $acceptedAt = trim((string) ($entry['accepted_at'] ?? ''));
            $cpf = preg_replace('/\D+/', '', (string) ($entry['cpf'] ?? ''));
            if ($cpf === '' || strlen($cpf) !== 11 || $acceptedAt === '') {
                continue;
            }

            fwrite($handle, $cpf . ';' . $attempts . ';' . $acceptedAt . "\n");
            $written++;
        }

        return $written;
    }

    private function appendPendingEntriesAsErrorRows(V8FgtsConsultJob $job, array $entries, string $message): void
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $this->finalRow($entry['cpf'], 'FALHA', $message);
            $this->accFail++;
        }

        if ($rows !== []) {
            $this->spoolAppendManyPersist($job, $rows);
        }
    }

    private function resolvePhase2Window(V8FgtsConsultJob $job): array
    {
        $startedAt = $job->started_at instanceof Carbon
            ? CarbonImmutable::instance($job->started_at)->utc()
            : CarbonImmutable::now('UTC');

        return [
            $startedAt->subSeconds($this->selectionToleranceSeconds),
            CarbonImmutable::now('UTC'),
        ];
    }

    private function formatApiDateTime(CarbonImmutable $date): string
    {
        return $date->utc()->format('Y-m-d\TH:i:s\Z');
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
            $this->accFail++;
            return $this->finalRow($cpf, 'FALHA', 'Consulta sem balanceId ou parcelas válidas para simulação.', $rowBase);
        }

        $fee = $this->feeContext;
        if ($fee === null) {
            $this->accFail++;
            return $this->finalRow($cpf, 'FALHA', $this->feeErrorMessage ?? 'Tabela normal indisponível para simulação.', $rowBase);
        }

        $rowBase['simulation_fee_label'] = $fee['label'];
        $rowBase['simulation_fee_id'] = $fee['id'];

        $api->setRateLimitMs($this->simulationMinIntervalMs);
        $simResp = $api->createSimulation([
            'simulationFeesId' => $fee['id'],
            'balanceId' => $balanceId,
            'targetAmount' => (int) config('v8_fgts.bff.target_amount', 0),
            'documentNumber' => $cpf,
            'desiredInstallments' => $desiredInstallments,
            'provider' => $provider,
        ]);

        if (!($simResp['ok'] ?? false)) {
            $this->accFail++;
            return $this->finalRow($cpf, 'FALHA', (string) ($simResp['error'] ?? 'Falha ao criar simulação FGTS.'), $rowBase);
        }

        $data = is_array($simResp['data'] ?? null) ? $simResp['data'] : [];
        $this->accSuccess++;

        return $this->finalRow($cpf, 'SUCESSO', 'Simulação criada com sucesso.', array_merge($rowBase, [
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
        ]));
    }

    private function loadNormalFee(V8FgtsApiService $api): void
    {
        if ($this->feeContext !== null || $this->feeErrorMessage !== null) {
            return;
        }

        $cacheKey = 'v8_fgts_normal_fee:' . md5((string) config('v8_fgts.bff.base_url', '') . '|' . $api->provider());
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['id'], $cached['label'])) {
            $this->feeContext = $cached;
            return;
        }

        $api->setRateLimitMs($this->feesMinIntervalMs);
        $resp = $api->getSimulationFees();
        if (!($resp['ok'] ?? false)) {
            $this->feeErrorMessage = (string) ($resp['error'] ?? 'Falha ao consultar tabelas de taxas.');
            return;
        }

        $fees = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        $selected = V8FgtsSimulationMapper::selectNormalFee($fees, (string) config('v8_fgts.bff.fee_label', 'normal'));
        if ($selected === null) {
            $this->feeErrorMessage = 'Tabela normal não encontrada para simulação.';
            return;
        }

        $this->feeContext = $selected;
        Cache::put($cacheKey, $selected, (int) config('v8_fgts.bff.fee_cache_ttl_seconds', 300));
    }

    private function parsePendingLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $parts = explode(';', $line);
        $cpf = preg_replace('/\D+/', '', (string) ($parts[0] ?? ''));
        $attempts = (int) ($parts[1] ?? 0);
        $acceptedAt = trim((string) ($parts[2] ?? ''));

        if ($cpf === '' || strlen($cpf) !== 11 || $acceptedAt === '') {
            return null;
        }

        return [
            'cpf' => $cpf,
            'attempts' => max(0, $attempts),
            'accepted_at' => $acceptedAt,
        ];
    }

    private function isPendingExpired(string $acceptedAt): bool
    {
        try {
            $stamp = CarbonImmutable::parse($acceptedAt);
        } catch (\Throwable) {
            return true;
        }

        return $stamp->addSeconds($this->pollingTimeoutSeconds)->isPast();
    }

    private function flushRemainingPendingAsTimeout(V8FgtsConsultJob $job, string $pendingReal): void
    {
        $reader = fopen($pendingReal, 'r');
        if ($reader === false) {
            return;
        }

        try {
            $rows = [];
            while (($line = fgets($reader)) !== false) {
                $entry = $this->parsePendingLine($line);
                if ($entry === null) {
                    continue;
                }

                $rows[] = $this->finalRow($entry['cpf'], 'FALHA', 'Timeout aguardando retorno do saldo FGTS.');
                $this->accFail++;
            }

            if ($rows !== []) {
                $this->spoolAppendManyPersist($job, $rows);
            }
        } finally {
            fclose($reader);
        }
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
        if (!is_string($body)) {
            return null;
        }

        $body = trim($body);

        return $body !== '' ? ($stage . ' | ' . $body) : null;
    }

    private function isCancelled(): bool
    {
        return DB::table('v8_fgts_consult_jobs')->where('id', $this->jobId)->value('status') === 'cancelado';
    }

    private function finishIfCancelled(V8FgtsConsultJob $job): bool
    {
        if (!$this->isCancelled()) {
            return false;
        }

        $this->dispatchFinalize('falhou');
        return true;
    }

    private function sleepWithCancel(V8FgtsConsultJob $job, int $seconds): bool
    {
        $seconds = max(0, $seconds);
        for ($i = 0; $i < $seconds; $i++) {
            if ($this->finishIfCancelled($job)) {
                return false;
            }
            sleep(1);
        }

        return !$this->finishIfCancelled($job);
    }

    private function spoolAppendManyPersist(V8FgtsConsultJob $job, array $rows): void
    {
        if (!is_resource($this->spoolFp)) {
            throw new \RuntimeException('Writer do spool não inicializado.');
        }

        if (flock($this->spoolFp, LOCK_EX)) {
            foreach ($rows as $row) {
                $ordered = [];
                foreach (V8FgtsSchema::COLS as $key) {
                    $ordered[] = $row[$key] ?? null;
                }
                fputcsv($this->spoolFp, $ordered, ';');
            }
            fflush($this->spoolFp);
            flock($this->spoolFp, LOCK_UN);
        }

        $this->rowsSinceFlush += count($rows);
        $this->updateTotalsThrottled($job, []);
    }

    private function updateTotalsThrottled(V8FgtsConsultJob $job, array $extraSet = [], bool $force = false): void
    {
        $now = microtime(true);
        $needBytesCheck = $force || $this->rowsSinceFlush >= $this->flushEveryRows || $now >= $this->nextFlushAt;

        $bytes = $this->lastFlushedBytes;
        if ($needBytesCheck) {
            try {
                clearstatcache(true, $this->spoolReal);
                $bytes = file_exists($this->spoolReal) ? (int) filesize($this->spoolReal) : 0;
            } catch (Throwable) {
                $bytes = $this->lastFlushedBytes;
            }
        }

        $shouldFlush = $force
            || $this->rowsSinceFlush >= $this->flushEveryRows
            || $now >= $this->nextFlushAt
            || ($bytes - $this->lastFlushedBytes) >= $this->flushBytesStep;

        if (!$shouldFlush) {
            return;
        }

        $updates = [
            'spool_bytes' => $bytes,
            'updated_at' => Carbon::now(),
        ];

        foreach ($extraSet as $key => $value) {
            $updates[$key] = $value;
        }

        if ($this->accSuccess > 0) {
            $updates['success_count'] = DB::raw('COALESCE(success_count,0) + ' . $this->accSuccess);
        }
        if ($this->accNaoElegivel > 0) {
            $updates['nao_elegivel_count'] = DB::raw('COALESCE(nao_elegivel_count,0) + ' . $this->accNaoElegivel);
        }
        if ($this->accFail > 0) {
            $updates['fail_count'] = DB::raw('COALESCE(fail_count,0) + ' . $this->accFail);
        }

        DB::table('v8_fgts_consult_jobs')
            ->where('id', $job->id)
            ->update($updates);

        $job->spool_bytes = $bytes;
        $this->rowsSinceFlush = 0;
        $this->nextFlushAt = $now + $this->flushEverySecs;
        $this->lastFlushedBytes = $bytes;
        $this->accSuccess = 0;
        $this->accNaoElegivel = 0;
        $this->accFail = 0;
    }

    private function buildUniqueCpfsFile(string $cpfsReal, string $uniqRel): int
    {
        $disk = Storage::disk($this->disk);
        $uniqReal = $disk->path($uniqRel);
        $chunks = [];

        $reader = fopen($cpfsReal, 'r');
        if ($reader === false) {
            return 0;
        }

        try {
            $block = [];
            while (($line = fgets($reader)) !== false) {
                $cpf = preg_replace('/\D+/', '', (string) $line);
                if ($cpf === '' || strlen($cpf) !== 11) {
                    continue;
                }

                $block[$cpf] = true;
                if (count($block) >= $this->dedupeBlockSize || $this->shouldSpill(count($block))) {
                    $chunks[] = $this->writeSortedChunk($block);
                    $block = [];
                }
            }

            if ($block !== []) {
                $chunks[] = $this->writeSortedChunk($block);
            }
        } finally {
            fclose($reader);
        }

        if ($chunks === []) {
            $writer = fopen($uniqReal, 'w');
            if ($writer !== false) {
                fclose($writer);
            }
            return 0;
        }

        if (count($chunks) === 1) {
            @rename($chunks[0], $uniqReal);
            return $this->countLines($uniqReal);
        }

        $writer = fopen($uniqReal, 'w');
        if ($writer === false) {
            foreach ($chunks as $chunk) {
                @unlink($chunk);
            }
            return 0;
        }

        $handles = [];
        $heads = [];
        foreach ($chunks as $index => $path) {
            $handle = fopen($path, 'r');
            if ($handle !== false) {
                $handles[$index] = $handle;
                $heads[$index] = fgets($handle);
            }
        }

        $written = 0;
        $last = null;
        while ($handles !== []) {
            $minIndex = null;
            $minValue = null;
            foreach ($heads as $index => $value) {
                if ($value === false || $value === null) {
                    continue;
                }
                $value = trim($value);
                if ($minValue === null || strcmp($value, $minValue) < 0) {
                    $minValue = $value;
                    $minIndex = $index;
                }
            }

            if ($minIndex === null) {
                break;
            }

            if ($minValue !== '' && $minValue !== $last) {
                fwrite($writer, $minValue . "\n");
                $written++;
                $last = $minValue;
            }

            $heads[$minIndex] = fgets($handles[$minIndex]);
            if ($heads[$minIndex] === false) {
                fclose($handles[$minIndex]);
                unset($handles[$minIndex], $heads[$minIndex]);
            }
        }

        fclose($writer);

        foreach ($chunks as $chunk) {
            @unlink($chunk);
        }

        return $written;
    }

    private function writeSortedChunk(array $block): string
    {
        $disk = Storage::disk($this->disk);
        $rel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.cpfs.chunk." . uniqid('', true) . ".txt";
        $real = $disk->path($rel);
        $this->pendFiles[] = $rel;

        ksort($block, SORT_STRING);
        $writer = fopen($real, 'w');
        if ($writer !== false) {
            foreach ($block as $cpf => $_) {
                fwrite($writer, $cpf . "\n");
            }
            fclose($writer);
        }

        return $real;
    }

    private function countLines(string $real): int
    {
        $count = 0;
        $reader = fopen($real, 'r');
        if ($reader !== false) {
            while (!feof($reader)) {
                if (fgets($reader) !== false) {
                    $count++;
                }
            }
            fclose($reader);
        }

        return $count;
    }

    private function shouldSpill(int $currentCount): bool
    {
        if ($currentCount <= 0) {
            return false;
        }

        $limit = $this->memoryLimitBytes();
        if ($limit <= 0) {
            return false;
        }

        return memory_get_usage(true) > (int) ($limit * 0.70);
    }

    private function memoryLimitBytes(): int
    {
        $value = ini_get('memory_limit');
        if ($value === false || $value === '' || $value === '-1') {
            return PHP_INT_MAX;
        }

        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        switch ($last) {
            case 'g':
                $number *= 1024;
            case 'm':
                $number *= 1024;
            case 'k':
                $number *= 1024;
        }

        return $number > 0 ? $number : PHP_INT_MAX;
    }

    private function fileSizeSafe(string $diskName, string $relativePath): int
    {
        try {
            $disk = Storage::disk($diskName);
            $real = $disk->path($relativePath);
            clearstatcache(true, $real);
            return file_exists($real) ? (int) filesize($real) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function closeSpoolWriter(): void
    {
        if (!is_resource($this->spoolFp)) {
            return;
        }

        @fflush($this->spoolFp);
        @fclose($this->spoolFp);
        $this->spoolFp = null;
    }

    private function deletePendFiles(): void
    {
        try {
            $disk = Storage::disk($this->disk);
            foreach ($this->pendFiles as $rel) {
                if ($rel && $disk->exists($rel)) {
                    try {
                        $disk->delete($rel);
                    } catch (Throwable) {
                    }
                }
            }
        } catch (Throwable) {
        }
    }

    private function dispatchFinalize(string $targetStatus): void
    {
        $this->closeSpoolWriter();
        FinalizeV8FgtsConsultReportJob::dispatch($this->jobId, $targetStatus)
            ->onQueue((string) config('v8_fgts.preview.queue', 'reports'));
    }
}
