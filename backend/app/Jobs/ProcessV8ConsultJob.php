<?php

namespace App\Jobs;

use App\Models\IbgeName;
use App\Models\V8ConsultJob;
use App\Services\V8ApiService;
use App\Support\Cpf;
use App\Support\V8Schema;
use App\Jobs\FinalizeV8ConsultReportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessV8ConsultJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 115260;
    public int $timeout;

    private int $jobId;
    private string $disk;
    private string $dirReports;
    private string $dirSpool;
    private string $finalPrefix;

    private array $pendFiles = [];
    private $spoolFp = null;
    private string $spoolReal = '';

    private int $flushEverySecs = 10;
    private float $lastFlushAt = 0.0;

    private int $accSuccess = 0;
    private int $accNaoElegivel = 0;
    private int $accFail = 0;

    private int $statusMaxAttempts;
    private int $statusRetryDelay;
    private int $statusRoundDelay;
    private int $statusBatchLimit;
    private int $statusBatchLimitMin;
    private int $statusBatchLimitMax;
    private int $statusBatchLimitDivisor;
    private int $statusBatchLimitRoundStart;
    private int $statusBatchLimitRoundStep;
    private int $statusLookbackHours;
    private int $statusLookbackExistingHours;
    private int $statusMaxAttemptsExisting = 5;
    private int $httpMinIntervalPhase1;
    private int $httpMinIntervalPhase2Status;
    private int $httpMinIntervalPhase2Simulation;
    private int $phase1PoolSize;
    private int $phase1BatchDelaySeconds;
    private int $httpRateLimitSleepSeconds;
    private int $pendingLowThreshold;
    private int $pendingLowSeconds;
    private ?int $pendingLowSince = null;
    private array $pendingCounts = ['regular' => 0, 'existing' => 0];
    private bool $forceFinish = false;
    private string $currentPhase = 'FASE 1';
    private int $reconsentBlockedMax;
    private int $reconsentBlockedDelaySeconds;
    private bool $pauseEnabled;
    private string $pauseStart;
    private string $pauseEnd;
    private string $pauseTimezone;
    private int $pauseCheckIntervalSeconds;
    private float $lastPauseCheckAt = 0.0;
    private bool $isPaused = false;

    public function __construct(int $jobId)
    {
        $this->jobId = $jobId;

        $this->timeout = (int) config('v8.job.timeout_seconds', 115200);
        $this->disk = (string) config('v8.storage.reports_disk', 'local');
        $this->dirReports = (string) config('v8.storage.dir_reports', 'v8-reports');
        $this->dirSpool = (string) (config('v8.storage.dir_spool') ?? 'v8-spool');
        $this->finalPrefix = (string) config('v8.storage.final_prefix', 'v8-consulta');

        $this->statusMaxAttempts = (int) config('v8.job.status_max_attempts', 10);
        $this->statusRetryDelay = (int) config('v8.job.status_retry_delay_seconds', 30);
        $this->statusRoundDelay = (int) config('v8.job.status_round_delay_seconds', 4);
        $this->statusBatchLimit = (int) config('v8.job.status_batch_limit', 50);
        $this->statusBatchLimitMin = (int) config('v8.job.status_batch_limit_min', 50);
        $this->statusBatchLimitMax = (int) config('v8.job.status_batch_limit_max', 300);
        $this->statusBatchLimitDivisor = max(1, (int) config('v8.job.status_batch_limit_divisor', 50));
        $this->statusBatchLimitRoundStart = max(1, (int) config('v8.job.status_batch_limit_round_start', 3));
        $this->statusBatchLimitRoundStep = max(0, (int) config('v8.job.status_batch_limit_round_step', 50));
        $this->statusLookbackHours = (int) config('v8.job.status_lookback_hours', 48);
        $this->statusLookbackExistingHours = (int) config('v8.job.status_lookback_existing_hours', 168);
        $this->httpMinIntervalPhase1 = (int) (config('v8.http.min_interval_ms_phase1') ?? config('v8.http.min_interval_ms', 2000));
        $this->httpMinIntervalPhase2Status = (int) (config('v8.http.min_interval_ms_phase2_status')
            ?? config('v8.http.min_interval_ms_phase2')
            ?? config('v8.http.min_interval_ms', 2000));
        $this->httpMinIntervalPhase2Simulation = (int) (config('v8.http.min_interval_ms_phase2_simulation')
            ?? config('v8.http.min_interval_ms_phase2')
            ?? config('v8.http.min_interval_ms', 2000));
        $this->phase1PoolSize = max(1, (int) config('v8.job.phase1_pool_size', 3));
        $this->phase1BatchDelaySeconds = max(0, (int) config('v8.job.phase1_batch_delay_seconds', 2));
        $this->httpRateLimitSleepSeconds = max(0, (int) config('v8.http.rate_limit_sleep_seconds', 15));
        $this->pendingLowThreshold = max(0, (int) config('v8.job.pending_low_threshold', 50));
        $this->pendingLowSeconds = max(0, (int) config('v8.job.pending_low_seconds', 3600));
        $this->reconsentBlockedMax = max(0, (int) config('v8.job.reconsent_blocked_max', 1));
        $this->reconsentBlockedDelaySeconds = max(0, (int) config('v8.job.reconsent_blocked_delay_seconds', 0));
        $this->pauseEnabled = (bool) config('v8.job.pause_enabled', true);
        $this->pauseStart = (string) config('v8.job.pause_start', '20:00');
        $this->pauseEnd = (string) config('v8.job.pause_end', '07:00');
        $this->pauseTimezone = (string) config('v8.job.pause_timezone', 'America/Sao_Paulo');
        $this->pauseCheckIntervalSeconds = max(1, (int) config('v8.job.pause_check_interval_seconds', 15));
    }

    public function uniqueId(): string
    {
        return (string) $this->jobId;
    }

    public function handle(V8ApiService $api): void
    {
        $api->setJobId($this->jobId);
        $api->setRateLimitMs($this->httpMinIntervalPhase1);
        /** @var V8ConsultJob|null $job */
        $job = V8ConsultJob::query()->whereKey($this->jobId)->first();
        if (!$job) {
            $this->deletePendFiles();
            return;
        }

        if ($this->isCancelled($job)) {
            $this->cleanupSpool($job);
            return;
        }

        $disk = Storage::disk($this->disk);
        if (empty($job->spool_path) || empty($job->spool_inputs_path) || !$disk->exists($job->spool_path) || !$disk->exists($job->spool_inputs_path)) {
            Log::error("[V8] Job {$this->jobId} sem spool pré-criado.");
            $this->failFinalize($job);
            $this->deletePendFiles();
            return;
        }

        if ($this->pauseIfNeeded($job)) {
            return;
        }

        $job->update([
            'status' => 'em_progresso',
            'phase' => 'fase_1',
            'started_at' => $job->started_at ?? Carbon::now(),
            'spool_bytes' => $this->fileSizeSafe($this->disk, $job->spool_path),
        ]);
        $this->currentPhase = 'FASE 1';

        $this->spoolReal = $disk->path($job->spool_path);
        $this->spoolFp = @fopen($this->spoolReal, 'a');
        if (!is_resource($this->spoolFp)) {
            $this->failFinalize($job);
            $this->deletePendFiles();
            return;
        }

        $this->lastFlushAt = microtime(true);

        try {
            $inputsReal = $disk->path($job->spool_inputs_path);
            $uniqRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.inputs.uniq.txt";
            $this->pendFiles[] = $uniqRel;

            [$uniqueCount, $invalidCount] = $this->buildUniqueEntriesFile($inputsReal, $uniqRel, $job);
            $totalCount = $uniqueCount + $invalidCount;
            if ($totalCount === 0) {
                $this->logCpfFailure('inputs', null, null, 'Nenhuma linha válida encontrada.', [
                    'inputs_path' => $job->spool_inputs_path,
                    'inputs_size' => $this->fileSizeSafe($this->disk, $job->spool_inputs_path ?? ''),
                ]);
                $this->updateTotalsThrottled($job, [], true);
                $this->failFinalize($job);
                return;
            }

            $this->updateTotalsThrottled($job, ['total_cpfs' => $totalCount], true);

            if ($uniqueCount === 0) {
                $this->logCpfFailure('inputs', null, null, 'Nenhuma linha válida encontrada.', [
                    'inputs_path' => $job->spool_inputs_path,
                    'inputs_size' => $this->fileSizeSafe($this->disk, $job->spool_inputs_path ?? ''),
                ]);
                $this->updateTotalsThrottled($job, [], true);
                $this->failFinalize($job);
                return;
            }

            // ===== FASE 1: criar + autorizar consentimento para todos =====
            $consentsRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.consents.txt";
            $this->pendFiles[] = $consentsRel;
            $consentsReal = $disk->path($consentsRel);
            $consentsFp = fopen($consentsReal, 'c+');
            if ($consentsFp === false) {
                $this->failFinalize($job);
                return;
            }

            $consentCount = 0;
            $reader = fopen($disk->path($uniqRel), 'r');
            if ($reader === false) {
                fclose($consentsFp);
                $this->failFinalize($job);
                return;
            }

            try {
                $api->setRateLimitMs($this->httpMinIntervalPhase1);
                $phase1Batch = [];
                while (($line = fgets($reader)) !== false) {
                    if ($this->finishIfStopped($job)) {
                        return;
                    }

                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }

                    [$cpf, $nome, $nasc] = $this->splitEntryLine($line);
                    if (!$cpf || !$nome || !$nasc) {
                        $this->appendErrorRow($job, $cpf, $nome, $nasc, 'Linha inválida após normalização.');
                        $this->logCpfFailure('parse', $cpf, null, 'Linha inválida após normalização.', [
                            'raw' => $this->truncate($line),
                        ]);
                        continue;
                    }

                    $phase1Batch[] = [$cpf, $nome, $nasc];
                    if (count($phase1Batch) >= $this->phase1PoolSize) {
                        $consentCount += $this->processPhase1Batch($api, $job, $phase1Batch, $consentsFp);
                        $phase1Batch = [];
                        if ($this->phase1BatchDelaySeconds > 0) {
                            sleep($this->phase1BatchDelaySeconds);
                        }
                    }
                }

                if (!empty($phase1Batch)) {
                    $consentCount += $this->processPhase1Batch($api, $job, $phase1Batch, $consentsFp);
                }
            } finally {
                fclose($reader);
                fflush($consentsFp);
                fclose($consentsFp);
            }

            // ===== FASE 2: polling + simulação =====
            if ($consentCount > 0) {
                $api->setRateLimitMs($this->httpMinIntervalPhase2Status);
                $job->update(['phase' => 'fase_2']);
                $this->currentPhase = 'FASE 2';
                $prePhase2Delay = max(0, (int) config('v8.job.phase2_start_delay_seconds', 30));
                if ($prePhase2Delay > 0) {
                    if (!$this->sleepWithCancel($job, $prePhase2Delay)) {
                        return;
                    }
                }
                $reader2 = fopen($consentsReal, 'r');
                if ($reader2 === false) {
                    $this->failFinalize($job);
                    return;
                }

                try {
                    $pendingRegularRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.pending.regular.csv";
                    $pendingExistingRel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.pending.existing.csv";
                    $this->pendFiles[] = $pendingRegularRel;
                    $this->pendFiles[] = $pendingExistingRel;

                    $pendingRegularCount = 0;
                    $pendingExistingCount = 0;

                    $pendingRegularFp = fopen($disk->path($pendingRegularRel), 'c+');
                    $pendingExistingFp = fopen($disk->path($pendingExistingRel), 'c+');
                    if ($pendingRegularFp === false || $pendingExistingFp === false) {
                        if (is_resource($pendingRegularFp)) {
                            fclose($pendingRegularFp);
                        }
                        if (is_resource($pendingExistingFp)) {
                            fclose($pendingExistingFp);
                        }
                        $this->failFinalize($job);
                        return;
                    }

                    ftruncate($pendingRegularFp, 0);
                    ftruncate($pendingExistingFp, 0);

                    while (($line = fgets($reader2)) !== false) {
                        if ($this->finishIfStopped($job)) {
                            fclose($pendingRegularFp);
                            fclose($pendingExistingFp);
                            return;
                        }

                        $line = trim($line);
                        if ($line === '') {
                            continue;
                        }

                        [$cpf, $nome, $nasc, $consultId, $mode] = $this->splitConsentLine($line);
                        if (!$cpf || !$nome || !$nasc) {
                            $this->appendErrorRow($job, $cpf, $nome, $nasc, 'Linha inválida após normalização.');
                            $this->logCpfFailure('parse', $cpf, null, 'Linha inválida após normalização.', [
                                'raw' => $this->truncate($line),
                            ]);
                            continue;
                        }

                        if ($consultId) {
                            fputcsv($pendingRegularFp, [$cpf, $nome, $nasc, $consultId, 0], ';');
                            $pendingRegularCount++;
                            continue;
                        }

                        if ($mode === 'existing') {
                            fputcsv($pendingExistingFp, [$cpf, $nome, $nasc, '', 0], ';');
                            $pendingExistingCount++;
                            continue;
                        }

                        $this->appendErrorRow($job, $cpf, $nome, $nasc, 'Linha inválida após normalização.');
                        $this->logCpfFailure('parse', $cpf, null, 'Linha inválida após normalização.', [
                            'raw' => $this->truncate($line),
                        ]);
                    }

                    fclose($pendingRegularFp);
                    fclose($pendingExistingFp);

                    $this->pendingCounts['regular'] = $pendingRegularCount;
                    $this->pendingCounts['existing'] = $pendingExistingCount;
                    $this->touchPendingLowTimer();

                    $stopEarly = false;
                    if ($pendingRegularCount > 0) {
                        $startDate = ($job->started_at ?? $job->created_at ?? Carbon::now('UTC'))->copy()->setTimezone('UTC')->startOfDay();
                        $endDate = Carbon::now('UTC')->endOfDay();
                        $this->runBatchStatusFile($api, $job, $pendingRegularRel, 'regular', $startDate, $endDate, $this->statusMaxAttempts);
                    } else {
                        $disk->delete($pendingRegularRel);
                    }

                    if ($this->forceFinish) {
                        $stopEarly = true;
                    }

                    if (!$stopEarly) {
                        if ($this->finishIfStopped($job)) {
                            return;
                        }

                        if ($pendingExistingCount > 0) {
                            $startDate = Carbon::now('UTC')->subHours($this->statusLookbackExistingHours)->startOfDay();
                            $endDate = Carbon::now('UTC')->endOfDay();
                            $this->runBatchStatusFile($api, $job, $pendingExistingRel, 'existing', $startDate, $endDate, $this->statusMaxAttemptsExisting);
                        } else {
                            $disk->delete($pendingExistingRel);
                        }
                    }
                } finally {
                    fclose($reader2);
                }
            }
        } finally {
            if (is_resource($this->spoolFp)) {
                @fflush($this->spoolFp);
                @fclose($this->spoolFp);
            }
        }

        $this->updateTotalsThrottled($job, [], true);
        dispatch(new FinalizeV8ConsultReportJob($this->jobId, 'concluido'))
            ->onQueue((string) config('v8.preview.queue', 'reports'));

        $this->deletePendFiles();
    }

    private function processEntry(V8ApiService $api, V8ConsultJob $job, string $cpf, string $nome, string $nasc): void
    {
        $row = $this->baseRow($cpf, $nome, $nasc);

        $gender = $this->genderFromName($nome);
        if (!$gender) {
            $row['status'] = 'ERROR';
            $this->markErro($row, 'Genero nao encontrado no IBGE.');
            $this->logCpfFailure('gender', $cpf, null, $row['mensagem'], ['nome' => $nome]);
            $row['status'] = 'FALHOU';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $provider = (string) config('v8.bff.provider', 'QI');
        $configId = (string) config('v8.bff.config_id', '');
        $disbursedAmount = (int) config('v8.simulation.disbursed_amount', 500);
        $installments = (int) config('v8.simulation.installments', 24);

        $consultResp = $api->createConsult([
            'borrowerDocumentNumber' => $cpf,
            'gender' => $gender,
            'birthDate' => $nasc,
            'signerName' => $nome,
            'signerEmail' => (string) config('v8.signer.email', 'luangstl@gmail.com'),
            'signerPhone' => [
                'phoneNumber' => (string) config('v8.signer.phone_number', '997664631'),
                'countryCode' => (string) config('v8.signer.phone_country', '55'),
                'areaCode' => (string) config('v8.signer.phone_area', '47'),
            ],
            'provider' => $provider,
        ]);

        if (!$consultResp['ok']) {
            $row['status'] = 'ERROR';
            $this->markNaoElegivel($row, $this->formatApiError($consultResp));
            $this->logCpfFailure('consult', $cpf, null, $row['mensagem'], $this->logContextFromApi($consultResp));
            $row['status'] = 'NAO_ELEGIVEL';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $consultId = $consultResp['data']['id'] ?? null;
        if (!is_string($consultId) || $consultId === '') {
            $row['status'] = 'ERROR';
            $this->markNaoElegivel($row, 'ID de consulta ausente.');
            $this->logCpfFailure('consult', $cpf, null, $row['mensagem']);
            $row['status'] = 'NAO_ELEGIVEL';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }
        $row['consult_id'] = $consultId;

        $authResp = $api->authorizeConsult($consultId);
        if (!$authResp['ok']) {
            if (!$this->isAuthorizeAlreadyApproved($authResp, $consultId)) {
                $row['status'] = 'ERROR';
                $this->markNaoElegivel($row, $this->formatApiError($authResp));
                $this->logCpfFailure('authorize', $cpf, $consultId, $row['mensagem'], $this->logContextFromApi($authResp));
                $row['status'] = 'NAO_ELEGIVEL';
                $this->spoolAppendManyPersist($job, [$row]);
                return;
            }

            $this->logCpfFailure('authorize', $cpf, $consultId, 'Consentimento já aprovado (confirmado).', $this->logContextFromApi($authResp));
        }

        $statusResp = $this->pollStatus($api, $cpf, $consultId);
        if (!$statusResp['ok']) {
            $row['status'] = 'ERROR';
            $this->markNaoElegivel($row, $statusResp['error'] ?? 'Falha ao obter status.');
            $this->logCpfFailure('status', $cpf, $consultId, $row['mensagem']);
            $row['status'] = 'NAO_ELEGIVEL';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $row['status'] = $statusResp['status'] ?? null;
        $row['available_margin_value'] = $statusResp['available_margin_value'] ?? null;

        $status = $statusResp['status'] ?? null;
        if ($status !== 'SUCCESS') {
            $this->markNaoElegivel($row, $statusResp['error'] ?? ($status ? "Status {$status}" : 'Status inválido.'));
            $this->logCpfFailure('status', $cpf, $consultId, $row['mensagem'], ['status' => $status]);
            $row['status'] = 'NAO_ELEGIVEL';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $api->setRateLimitMs($this->httpMinIntervalPhase2Simulation);
        $simResult = $this->simulateWithInstallmentsFallback($api, [
            'consult_id' => $consultId,
            'config_id' => $configId,
            'disbursed_amount' => $disbursedAmount,
            'number_of_installments' => $installments,
            'provider' => $provider,
        ], $cpf, $consultId);
        $simResp = $simResult['resp'];

        if (!$simResp['ok']) {
            $this->markNaoElegivel($row, $this->simulationErrorMessage($simResp));
            $this->logCpfFailure('simulation', $cpf, $consultId, $row['mensagem'], $this->logContextFromApi($simResp));
            $row['status'] = 'NAO_ELEGIVEL';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $this->applySimulation($row, $simResp['data'] ?? []);
        $row['status'] = 'SUCESSO';
        $this->accSuccess++;
        $this->spoolAppendManyPersist($job, [$row]);
    }

    private function prepareConsent(
        V8ApiService $api,
        V8ConsultJob $job,
        string $cpf,
        string $nome,
        string $nasc,
        $consentsFp
    ): bool {
        $row = $this->baseRow($cpf, $nome, $nasc);

        $gender = $this->genderFromName($nome);
        if (!$gender) {
            $row['status'] = 'FALHOU';
            $this->markErro($row, 'Genero nao encontrado no IBGE.');
            $this->logCpfFailure('gender', $cpf, null, $row['mensagem'], ['nome' => $nome]);
            $this->spoolAppendManyPersist($job, [$row]);
            return false;
        }

        $provider = (string) config('v8.bff.provider', 'QI');
        $consultResp = $api->createConsult([
            'borrowerDocumentNumber' => $cpf,
            'gender' => $gender,
            'birthDate' => $nasc,
            'signerName' => $nome,
            'signerEmail' => (string) config('v8.signer.email', 'luangstl@gmail.com'),
            'signerPhone' => [
                'phoneNumber' => (string) config('v8.signer.phone_number', '997664631'),
                'countryCode' => (string) config('v8.signer.phone_country', '55'),
                'areaCode' => (string) config('v8.signer.phone_area', '47'),
            ],
            'provider' => $provider,
        ]);

        if (!$consultResp['ok']) {
            if (($consultResp['type'] ?? null) === 'consult_already_exists_by_user_and_document_number') {
                if (is_resource($consentsFp)) {
                    fputcsv($consentsFp, [$cpf, $nome, $nasc, '', 'existing'], ';');
                }
                $this->logCpfFailure('consult', $cpf, null, 'Consentimento já existe; seguirá por CPF.', $this->logContextFromApi($consultResp));
                return true;
            }

            $row['status'] = 'NAO_ELEGIVEL';
            $this->markNaoElegivel($row, $this->formatApiError($consultResp));
            $this->logCpfFailure('consult', $cpf, null, $row['mensagem'], $this->logContextFromApi($consultResp));
            $this->spoolAppendManyPersist($job, [$row]);
            return false;
        }

        $consultId = $consultResp['data']['id'] ?? null;
        if (!is_string($consultId) || $consultId === '') {
            $row['status'] = 'NAO_ELEGIVEL';
            $this->markNaoElegivel($row, 'ID de consulta ausente.');
            $this->logCpfFailure('consult', $cpf, null, $row['mensagem']);
            $this->spoolAppendManyPersist($job, [$row]);
            return false;
        }

        $authResp = $api->authorizeConsult($consultId);
        if (!$authResp['ok']) {
            if (!$this->isAuthorizeAlreadyApproved($authResp, $consultId)) {
                $row['status'] = 'NAO_ELEGIVEL';
                $this->markNaoElegivel($row, $this->formatApiError($authResp));
                $this->logCpfFailure('authorize', $cpf, $consultId, $row['mensagem'], $this->logContextFromApi($authResp));
                $this->spoolAppendManyPersist($job, [$row]);
                return false;
            }

            $this->logCpfFailure('authorize', $cpf, $consultId, 'Consentimento já aprovado (confirmado).', $this->logContextFromApi($authResp));
        }

        if (is_resource($consentsFp)) {
            fputcsv($consentsFp, [$cpf, $nome, $nasc, $consultId], ';');
        }

        return true;
    }

    private function processPhase1Batch(V8ApiService $api, V8ConsultJob $job, array $entries, $consentsFp): int
    {
        if (empty($entries)) {
            return 0;
        }

        $provider = (string) config('v8.bff.provider', 'QI');
        $baseUrl = rtrim((string) config('v8.bff.base_url', ''), '/');
        $token = $api->getToken();
        if (!$token || $baseUrl === '') {
            foreach ($entries as $entry) {
                [$cpf, $nome, $nasc] = $entry;
                $row = $this->baseRow($cpf, $nome, $nasc);
                $row['status'] = 'NAO_ELEGIVEL';
                $this->markNaoElegivel($row, 'V8 OAuth: token ausente.');
                $this->logCpfFailure('consult', $cpf, null, $row['mensagem']);
                $this->spoolAppendManyPersist($job, [$row]);
            }
            return 0;
        }

        $payloads = [];
        $entryByKey = [];
        foreach ($entries as $idx => $entry) {
            [$cpf, $nome, $nasc] = $entry;
            $gender = $this->genderFromName($nome);
            if (!$gender) {
                $row = $this->baseRow($cpf, $nome, $nasc);
                $row['status'] = 'FALHOU';
                $this->markErro($row, 'Genero nao encontrado no IBGE.');
                $this->logCpfFailure('gender', $cpf, null, $row['mensagem'], ['nome' => $nome]);
                $this->spoolAppendManyPersist($job, [$row]);
                continue;
            }

            $key = (string) $idx;
            $payloads[$key] = [
                'borrowerDocumentNumber' => $cpf,
                'gender' => $gender,
                'birthDate' => $nasc,
                'signerName' => $nome,
                'signerEmail' => (string) config('v8.signer.email', 'luangstl@gmail.com'),
                'signerPhone' => [
                    'phoneNumber' => (string) config('v8.signer.phone_number', '997664631'),
                    'countryCode' => (string) config('v8.signer.phone_country', '55'),
                    'areaCode' => (string) config('v8.signer.phone_area', '47'),
                ],
                'provider' => $provider,
            ];
            $entryByKey[$key] = [$cpf, $nome, $nasc];
        }

        if (empty($payloads)) {
            return 0;
        }

        $authHeaders = [
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ];

        $responses = Http::timeout(max(1, (int) config('v8.http.timeout', 15)))
            ->connectTimeout(max(1, (int) config('v8.http.connect_timeout', 10)))
            ->pool(function ($pool) use ($payloads, $baseUrl, $authHeaders) {
                $reqs = [];
                foreach ($payloads as $key => $payload) {
                    $reqs[] = $pool->as($key)->withHeaders($authHeaders)->post("{$baseUrl}/private-consignment/consult", $payload);
                }
                return $reqs;
            });

        $consultIds = [];
        $createdCount = 0;
        $saw429 = false;

        foreach ($payloads as $key => $payload) {
            [$cpf, $nome, $nasc] = $entryByKey[$key];
            $resp = $responses[$key] ?? null;

            if (!$resp instanceof HttpResponse) {
                $resp = $api->createConsult($payload);
                if (!$resp['ok']) {
                    $row = $this->baseRow($cpf, $nome, $nasc);
                    if (($resp['type'] ?? null) === 'consult_already_exists_by_user_and_document_number') {
                        if (is_resource($consentsFp)) {
                            fputcsv($consentsFp, [$cpf, $nome, $nasc, '', 'existing'], ';');
                        }
                        $createdCount++;
                        continue;
                    }
                    $row['status'] = 'NAO_ELEGIVEL';
                    $this->markNaoElegivel($row, $this->formatApiError($resp));
                    $this->logCpfFailure('consult', $cpf, null, $row['mensagem'], $this->logContextFromApi($resp));
                    $this->spoolAppendManyPersist($job, [$row]);
                    continue;
                }
                $consultId = $resp['data']['id'] ?? null;
                if (is_string($consultId) && $consultId !== '') {
                    $consultIds[$key] = $consultId;
                    $createdCount++;
                }
                continue;
            }

            if ($resp->status() === 429) {
                $saw429 = true;
            }

            if ($resp->ok()) {
                $json = $resp->json();
                $consultId = is_array($json) ? ($json['id'] ?? null) : null;
                if (is_string($consultId) && $consultId !== '') {
                    $consultIds[$key] = $consultId;
                    $createdCount++;
                    continue;
                }
                $row = $this->baseRow($cpf, $nome, $nasc);
                $row['status'] = 'NAO_ELEGIVEL';
                $this->markNaoElegivel($row, 'ID de consulta ausente.');
                $this->logCpfFailure('consult', $cpf, null, $row['mensagem']);
                $this->spoolAppendManyPersist($job, [$row]);
                continue;
            }

            if ($resp->status() === 429 || $resp->status() >= 500) {
                $retryResp = $api->createConsult($payload);
                if ($retryResp['ok']) {
                    $consultId = $retryResp['data']['id'] ?? null;
                    if (is_string($consultId) && $consultId !== '') {
                        $consultIds[$key] = $consultId;
                        $createdCount++;
                        continue;
                    }
                    $row = $this->baseRow($cpf, $nome, $nasc);
                    $row['status'] = 'NAO_ELEGIVEL';
                    $this->markNaoElegivel($row, 'ID de consulta ausente.');
                    $this->logCpfFailure('consult', $cpf, null, $row['mensagem']);
                    $this->spoolAppendManyPersist($job, [$row]);
                    continue;
                }

                if (($retryResp['type'] ?? null) === 'consult_already_exists_by_user_and_document_number') {
                    if (is_resource($consentsFp)) {
                        fputcsv($consentsFp, [$cpf, $nome, $nasc, '', 'existing'], ';');
                    }
                    $createdCount++;
                    continue;
                }

                $row = $this->baseRow($cpf, $nome, $nasc);
                $row['status'] = 'NAO_ELEGIVEL';
                $this->markNaoElegivel($row, $this->formatApiError($retryResp));
                $this->logCpfFailure('consult', $cpf, null, $row['mensagem'], $this->logContextFromApi($retryResp));
                $this->spoolAppendManyPersist($job, [$row]);
                continue;
            }

            $err = $this->extractHttpError($resp);
            if ($err['type'] === 'consult_already_exists_by_user_and_document_number') {
                if (is_resource($consentsFp)) {
                    fputcsv($consentsFp, [$cpf, $nome, $nasc, '', 'existing'], ';');
                }
                $createdCount++;
                continue;
            }

            $row = $this->baseRow($cpf, $nome, $nasc);
            $row['status'] = 'NAO_ELEGIVEL';
            $this->markNaoElegivel($row, $this->formatApiError($err));
            $this->logCpfFailure('consult', $cpf, null, $row['mensagem'], $this->logContextFromHttpError($err));
            $this->spoolAppendManyPersist($job, [$row]);
        }

        if ($saw429 && $this->httpRateLimitSleepSeconds > 0) {
            $this->logCpfFailure('consult', null, null, 'Pausa após 429 (fase 1).', ['seconds' => $this->httpRateLimitSleepSeconds]);
            sleep($this->httpRateLimitSleepSeconds);
        }

        if (empty($consultIds)) {
            return $createdCount;
        }

        $authResponses = Http::timeout(max(1, (int) config('v8.http.timeout', 15)))
            ->connectTimeout(max(1, (int) config('v8.http.connect_timeout', 10)))
            ->pool(function ($pool) use ($consultIds, $baseUrl, $authHeaders) {
                $reqs = [];
                foreach ($consultIds as $key => $consultId) {
                    $reqs[] = $pool->as($key)->withHeaders($authHeaders)->post("{$baseUrl}/private-consignment/consult/" . urlencode($consultId) . "/authorize", []);
                }
                return $reqs;
            });

        $authorizedCount = 0;
        $saw429 = false;

        foreach ($consultIds as $key => $consultId) {
            [$cpf, $nome, $nasc] = $entryByKey[$key];
            $resp = $authResponses[$key] ?? null;

            if (!$resp instanceof HttpResponse) {
                $authResp = $api->authorizeConsult($consultId);
                if (!$authResp['ok']) {
                    if (!$this->isAuthorizeAlreadyApproved($authResp, $consultId)) {
                        $row = $this->baseRow($cpf, $nome, $nasc);
                        $row['status'] = 'NAO_ELEGIVEL';
                        $this->markNaoElegivel($row, $this->formatApiError($authResp));
                        $this->logCpfFailure('authorize', $cpf, $consultId, $row['mensagem'], $this->logContextFromApi($authResp));
                        $this->spoolAppendManyPersist($job, [$row]);
                        continue;
                    }
                    $this->logCpfFailure('authorize', $cpf, $consultId, 'Consentimento já aprovado (confirmado).', $this->logContextFromApi($authResp));
                }
                if (is_resource($consentsFp)) {
                    fputcsv($consentsFp, [$cpf, $nome, $nasc, $consultId], ';');
                }
                $authorizedCount++;
                continue;
            }

            if ($resp->status() === 429) {
                $saw429 = true;
            }

            if ($resp->ok()) {
                if (is_resource($consentsFp)) {
                    fputcsv($consentsFp, [$cpf, $nome, $nasc, $consultId], ';');
                }
                $authorizedCount++;
                continue;
            }

            $err = $this->extractHttpError($resp);
            if ($this->isAuthorizeAlreadyApproved($err, $consultId)) {
                if (is_resource($consentsFp)) {
                    fputcsv($consentsFp, [$cpf, $nome, $nasc, $consultId], ';');
                }
                $authorizedCount++;
                continue;
            }

            if ($resp->status() === 429 || $resp->status() >= 500) {
                $authResp = $api->authorizeConsult($consultId);
                if ($authResp['ok']) {
                    if (is_resource($consentsFp)) {
                        fputcsv($consentsFp, [$cpf, $nome, $nasc, $consultId], ';');
                    }
                    $authorizedCount++;
                    continue;
                }

                if ($this->isAuthorizeAlreadyApproved($authResp, $consultId)) {
                    if (is_resource($consentsFp)) {
                        fputcsv($consentsFp, [$cpf, $nome, $nasc, $consultId], ';');
                    }
                    $authorizedCount++;
                    continue;
                }

                $row = $this->baseRow($cpf, $nome, $nasc);
                $row['status'] = 'NAO_ELEGIVEL';
                $this->markNaoElegivel($row, $this->formatApiError($authResp));
                $this->logCpfFailure('authorize', $cpf, $consultId, $row['mensagem'], $this->logContextFromApi($authResp));
                $this->spoolAppendManyPersist($job, [$row]);
                continue;
            }

            $row = $this->baseRow($cpf, $nome, $nasc);
            $row['status'] = 'NAO_ELEGIVEL';
            $this->markNaoElegivel($row, $this->formatApiError($err));
            $this->logCpfFailure('authorize', $cpf, $consultId, $row['mensagem'], $this->logContextFromHttpError($err));
            $this->spoolAppendManyPersist($job, [$row]);
        }

        if ($saw429 && $this->httpRateLimitSleepSeconds > 0) {
            $this->logCpfFailure('authorize', null, null, 'Pausa após 429 (fase 1).', ['seconds' => $this->httpRateLimitSleepSeconds]);
            sleep($this->httpRateLimitSleepSeconds);
        }

        return $authorizedCount;
    }

    private function processConsent(
        V8ApiService $api,
        V8ConsultJob $job,
        string $cpf,
        string $nome,
        string $nasc,
        string $consultId
    ): void {
        $row = $this->baseRow($cpf, $nome, $nasc);
        $row['consult_id'] = $consultId;
        $row['status'] = 'CONSENT_APPROVED';

        $statusResp = $this->pollStatus($api, $cpf, $consultId);
        if (!$statusResp['ok']) {
            $this->markNaoElegivel($row, $statusResp['error'] ?? 'Falha ao obter status.');
            $this->logCpfFailure('status', $cpf, $consultId, $row['mensagem']);
            $row['status'] = 'NAO_ELEGIVEL';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $row['available_margin_value'] = $statusResp['available_margin_value'] ?? null;

        $status = $statusResp['status'] ?? null;
        if ($status !== 'SUCCESS') {
            $this->markNaoElegivel($row, $statusResp['error'] ?? ($status ? "Status {$status}" : 'Status inválido.'));
            $this->logCpfFailure('status', $cpf, $consultId, $row['mensagem'], ['status' => $status]);
            $row['status'] = 'NAO_ELEGIVEL';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $provider = (string) config('v8.bff.provider', 'QI');
        $configId = (string) config('v8.bff.config_id', '');
        $disbursedAmount = (int) config('v8.simulation.disbursed_amount', 500);
        $installments = (int) config('v8.simulation.installments', 24);

        $api->setRateLimitMs($this->httpMinIntervalPhase2Simulation);
        $simResult = $this->simulateWithInstallmentsFallback($api, [
            'consult_id' => $consultId,
            'config_id' => $configId,
            'disbursed_amount' => $disbursedAmount,
            'number_of_installments' => $installments,
            'provider' => $provider,
        ], $cpf, $consultId);
        $simResp = $simResult['resp'];

        if (!$simResp['ok']) {
            $this->markNaoElegivel($row, $this->simulationErrorMessage($simResp));
            $this->logCpfFailure('simulation', $cpf, $consultId, $row['mensagem'], $this->logContextFromApi($simResp));
            $row['status'] = 'NAO_ELEGIVEL';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $this->applySimulation($row, $simResp['data'] ?? []);
        $row['status'] = 'SUCESSO';
        $this->accSuccess++;
        $this->spoolAppendManyPersist($job, [$row]);
    }

    private function finalizeFromStatus(
        V8ApiService $api,
        V8ConsultJob $job,
        string $cpf,
        string $nome,
        string $nasc,
        ?string $consultId,
        array $statusResp,
        bool $existing
    ): void {
        $row = $this->baseRow($cpf, $nome, $nasc);
        $row['consult_id'] = $consultId;
        $row['available_margin_value'] = $statusResp['available_margin_value'] ?? null;

        $status = $statusResp['status'] ?? null;
        if ($status !== 'SUCCESS') {
            $this->markNaoElegivel($row, $statusResp['error'] ?? ($status ? "Status {$status}" : 'Status inválido.'));
            $this->logCpfFailure('status', $cpf, $consultId, $row['mensagem'], ['status' => $status]);
            $row['status'] = 'NAO_ELEGIVEL';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        if (!$consultId) {
            $row['status'] = 'FALHOU';
            $this->markErro($row, 'ID de consulta ausente.');
            $this->logCpfFailure('status', $cpf, null, $row['mensagem']);
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $provider = (string) config('v8.bff.provider', 'QI');
        $configId = (string) config('v8.bff.config_id', '');
        $disbursedAmount = (int) config('v8.simulation.disbursed_amount', 500);
        $installments = (int) config('v8.simulation.installments', 24);

        $api->setRateLimitMs($this->httpMinIntervalPhase2Simulation);
        $simResult = $this->simulateWithInstallmentsFallback($api, [
            'consult_id' => $consultId,
            'config_id' => $configId,
            'disbursed_amount' => $disbursedAmount,
            'number_of_installments' => $installments,
            'provider' => $provider,
        ], $cpf, $consultId);
        $simResp = $simResult['resp'];

        if (!$simResp['ok']) {
            $this->markNaoElegivel($row, $this->simulationErrorMessage($simResp));
            $this->logCpfFailure('simulation', $cpf, $consultId, $row['mensagem'], $this->logContextFromApi($simResp));
            $row['status'] = 'NAO_ELEGIVEL';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $this->applySimulation($row, $simResp['data'] ?? []);
        $row['status'] = 'SUCESSO';
        $this->accSuccess++;
        $this->spoolAppendManyPersist($job, [$row]);
    }

    private function runBatchStatusFile(
        V8ApiService $api,
        V8ConsultJob $job,
        string $pendingRel,
        string $mode,
        Carbon $startDate,
        Carbon $endDate,
        int $maxAttempts
    ): void {
        $disk = Storage::disk($this->disk);
        $baseRel = $pendingRel;
        $currentRel = $baseRel;
        $round = 0;

        while ($disk->exists($currentRel)) {
            if ($this->finishIfStopped($job)) {
                return;
            }

            $api->setRateLimitMs($this->httpMinIntervalPhase2Status);
            $pendingKeys = $this->collectPendingKeys($disk->path($currentRel), $mode);
            if (empty($pendingKeys)) {
                $disk->delete($currentRel);
                return;
            }

            $batch = $this->fetchBatchStatusesByKeys($api, $pendingKeys, $mode, $startDate, $endDate, $job, $round + 1);
            unset($pendingKeys);

            if (!$batch['ok']) {
                if (!empty($batch['cancelled'])) {
                    $this->cleanupSpool($job);
                    return;
                }
                if (!empty($batch['retriable'])) {
                    if ($this->statusRoundDelay > 0) {
                        if (!$this->sleepWithCancel($job, $this->statusRoundDelay)) {
                            return;
                        }
                    }
                    continue;
                }

                $this->finalizePendingFileError($job, $currentRel, $mode, $batch['error'] ?? 'Falha ao obter status.');
                $disk->delete($currentRel);
                return;
            }

            $round++;
            $matches = $batch['matches'] ?? [];
            $nextRel = "{$baseRel}.next";
            $this->pendFiles[] = $nextRel;

            $written = $this->processPendingFileRound($api, $job, $disk->path($currentRel), $disk->path($nextRel), $mode, $matches, $maxAttempts);

            $disk->delete($currentRel);
            unset($matches);

            $this->pendingCounts[$mode] = $written;
            if ($this->maybeFinalizeOnLowPending($job)) {
                return;
            }

            if ($written === 0) {
                if ($disk->exists($nextRel)) {
                    $disk->delete($nextRel);
                }
                return;
            }

            if ($round >= $maxAttempts) {
                $this->finalizePendingFileTimeout($job, $nextRel, $mode);
                $disk->delete($nextRel);
                return;
            }

            // promove next -> current
            $currentReal = $disk->path($currentRel);
            $nextReal = $disk->path($nextRel);
            if (!@rename($nextReal, $currentReal)) {
                try {
                    $disk->move($nextRel, $currentRel);
                } catch (Throwable) {
                    // se falhar, encerra para evitar loop com arquivos inconsistentes
                    return;
                }
            }

            if ($this->statusRoundDelay > 0) {
                if (!$this->sleepWithCancel($job, $this->statusRoundDelay)) {
                    return;
                }
            }
        }
    }

    private function collectPendingKeys(string $realPath, string $mode): array
    {
        $keys = [];
        $fh = fopen($realPath, 'r');
        if ($fh === false) {
            return $keys;
        }

        try {
            while (($line = fgets($fh)) !== false) {
                $parsed = $this->parsePendingLine($line);
                if (!$parsed) {
                    continue;
                }

                [$cpf, , , $consultId] = $parsed;
                $key = $mode === 'existing'
                    ? $this->normalizeCpfKey($cpf)
                    : (string) $consultId;
                if ($key === '') {
                    continue;
                }
                $keys[$key] = true;
            }
        } finally {
            fclose($fh);
        }

        return $keys;
    }

    private function fetchBatchStatusesByKeys(
        V8ApiService $api,
        array &$pendingKeys,
        string $mode,
        Carbon $startDate,
        Carbon $endDate,
        ?V8ConsultJob $job = null,
        int $roundIndex = 1
    ): array {
        $matches = [];
        $limit = $this->resolveStatusBatchLimit($job, $roundIndex);
        $page = 1;
        $totalPages = 1;

        while ($page <= $totalPages && !empty($pendingKeys)) {
            if ($job && $this->isCancelled($job)) {
                return [
                    'ok' => false,
                    'cancelled' => true,
                ];
            }
            if ($job && $this->pauseIfNeeded($job)) {
                return [
                    'ok' => false,
                    'cancelled' => true,
                ];
            }

            $resp = $api->listConsults([
                'startDate' => $startDate->format('Y-m-d\\TH:i:s\\Z'),
                'endDate' => $endDate->format('Y-m-d\\TH:i:s\\Z'),
                'limit' => $limit,
                'page' => $page,
                'provider' => (string) config('v8.bff.provider', 'QI'),
            ]);

            if (!$resp['ok']) {
                return [
                    'ok' => false,
                    'error' => $this->formatApiError($resp),
                    'retriable' => (bool) ($resp['retriable'] ?? false),
                ];
            }

            $pages = $resp['data']['pages'] ?? [];
            if (is_array($pages) && isset($pages['totalPages'])) {
                $totalPages = max(1, (int) $pages['totalPages']);
            }

            $data = $resp['data']['data'] ?? [];
            if (is_array($data)) {
                foreach ($data as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $consultId = (string) ($item['id'] ?? '');
                    $cpf = $this->normalizeCpfKey((string) ($item['documentNumber'] ?? ''));
                    $key = $mode === 'existing' ? $cpf : $consultId;
                    if ($key === '' || !isset($pendingKeys[$key])) {
                        continue;
                    }

                    $statusResp = $this->statusFromItem($item);
                    if ($mode === 'existing') {
                        $statusResp['consult_id'] = $consultId !== '' ? $consultId : null;
                    }
                    $matches[$key] = $statusResp;
                    unset($pendingKeys[$key]);
                }
            }

            $page++;
        }

        return [
            'ok' => true,
            'matches' => $matches,
        ];
    }

    private function resolveStatusBatchLimit(?V8ConsultJob $job, int $roundIndex = 1): int
    {
        $min = max(1, $this->statusBatchLimitMin);
        $max = max($min, $this->statusBatchLimitMax);
        $base = min($max, max($min, $this->statusBatchLimit));

        $limit = $base;
        if ($job) {
            $total = (int) ($job->total_cpfs ?? 0);
            if ($total > 0) {
                $scaled = (int) ceil($total / $this->statusBatchLimitDivisor);
                $limit = max($limit, $scaled);
            }
        }

        if ($this->statusBatchLimitRoundStep > 0 && $roundIndex >= $this->statusBatchLimitRoundStart) {
            $boostRounds = $roundIndex - $this->statusBatchLimitRoundStart + 1;
            $limit += $boostRounds * $this->statusBatchLimitRoundStep;
        }

        if ($limit > $max) {
            return $max;
        }

        return max($min, $limit);
    }

    private function processPendingFileRound(
        V8ApiService $api,
        V8ConsultJob $job,
        string $currentReal,
        string $nextReal,
        string $mode,
        array $matches,
        int $maxAttempts
    ): int {
        $reader = fopen($currentReal, 'r');
        $writer = fopen($nextReal, 'w');
        if ($reader === false || $writer === false) {
            if (is_resource($reader)) {
                fclose($reader);
            }
            if (is_resource($writer)) {
                fclose($writer);
            }
            return 0;
        }

        $written = 0;
        $checked = 0;
        $cancelled = false;
        try {
            while (($line = fgets($reader)) !== false) {
                $checked++;
                if ($checked % 200 === 0 && $this->isCancelled($job)) {
                    $this->cleanupSpool($job);
                    $cancelled = true;
                    break;
                }
                $parsed = $this->parsePendingLine($line);
                if (!$parsed) {
                    continue;
                }

                [$cpf, $nome, $nasc, $consultId, $attempts, $reconsentAttempts] = $parsed;
                $key = $mode === 'existing'
                    ? $this->normalizeCpfKey($cpf)
                    : (string) $consultId;

                $statusResp = $matches[$key] ?? null;
                if (!$statusResp) {
                    fputcsv($writer, [$cpf, $nome, $nasc, $consultId, $attempts, $reconsentAttempts], ';');
                    $written++;
                    continue;
                }

                $status = $statusResp['status'] ?? null;
                if ($this->shouldReconsentBlocked($statusResp)) {
                    if ($this->reconsentBlockedMax > 0 && $reconsentAttempts >= $this->reconsentBlockedMax) {
                        $this->finalizePendingError($job, $this->entryFromParsed($cpf, $nome, $nasc, $consultId, $attempts, $reconsentAttempts), $mode, $statusResp['error'] ?? 'Consulta de margem bloqueada pelo trabalhador');
                        continue;
                    }

                    $reconsentAttempts++;
                    if ($this->reconsentBlockedDelaySeconds > 0 && !$this->sleepWithCancel($job, $this->reconsentBlockedDelaySeconds)) {
                        $cancelled = true;
                        break;
                    }

                    $reconsentResult = $this->attemptReconsentBlocked($api, $job, $cpf, $nome, $nasc, $consultId);
                    if ($reconsentResult['status'] === 'ok') {
                        $newConsultId = $reconsentResult['consult_id'] ?? '';
                        fputcsv($writer, [$cpf, $nome, $nasc, $newConsultId, 0, $reconsentAttempts], ';');
                        $written++;
                        continue;
                    }

                    if ($reconsentResult['status'] === 'existing') {
                        if ($mode === 'existing') {
                            fputcsv($writer, [$cpf, $nome, $nasc, '', 0, $reconsentAttempts], ';');
                            $written++;
                            continue;
                        }

                        $fallbackConsultId = $consultId ?? '';
                        if ($fallbackConsultId === '') {
                            $this->finalizePendingError(
                                $job,
                                $this->entryFromParsed($cpf, $nome, $nasc, $consultId, $attempts, $reconsentAttempts),
                                $mode,
                                'Consulta existente sem consult_id disponível.'
                            );
                            continue;
                        }

                        fputcsv($writer, [$cpf, $nome, $nasc, $fallbackConsultId, 0, $reconsentAttempts], ';');
                        $written++;
                        continue;
                    }

                    $this->finalizePendingError(
                        $job,
                        $this->entryFromParsed($cpf, $nome, $nasc, $consultId, $attempts, $reconsentAttempts),
                        $mode,
                        $reconsentResult['error'] ?? 'Falha ao reprocessar consentimento.'
                    );
                    continue;
                }
                if ($this->isWaitingStatus($status)) {
                    $useConsultId = $statusResp['consult_id'] ?? $consultId;
                    if ($status === 'WAITING_CONSENT' && $useConsultId) {
                        $authResp = $api->authorizeConsult($useConsultId);
                        if (
                            !$authResp['ok']
                            && !($authResp['retriable'] ?? false)
                            && !$this->isAuthorizeAlreadyApproved($authResp, $useConsultId)
                        ) {
                        $this->finalizePendingError($job, $this->entryFromParsed($cpf, $nome, $nasc, $consultId, $attempts, $reconsentAttempts), $mode, $this->formatApiError($authResp));
                            continue;
                        }
                    }

                    $attempts++;
                    if ($attempts >= $maxAttempts) {
                        $this->finalizePendingTimeout($job, $this->entryFromParsed($cpf, $nome, $nasc, $consultId, $attempts, $reconsentAttempts), $mode);
                        continue;
                    }

                    $consultId = $statusResp['consult_id'] ?? $consultId;
                    fputcsv($writer, [$cpf, $nome, $nasc, $consultId, $attempts, $reconsentAttempts], ';');
                    $written++;
                    continue;
                }

                $finalConsultId = $mode === 'existing'
                    ? ($statusResp['consult_id'] ?? $consultId)
                    : ($consultId ?? ($statusResp['consult_id'] ?? null));

                $this->finalizeFromStatus(
                    $api,
                    $job,
                    $cpf,
                    $nome,
                    $nasc,
                    $finalConsultId,
                    $statusResp,
                    $mode === 'existing'
                );
            }
        } finally {
            fclose($reader);
            fflush($writer);
            fclose($writer);
        }

        if ($cancelled) {
            return 0;
        }

        return $written;
    }

    private function touchPendingLowTimer(): void
    {
        if ($this->pendingLowThreshold <= 0 || $this->pendingLowSeconds <= 0) {
            return;
        }

        $total = ($this->pendingCounts['regular'] ?? 0) + ($this->pendingCounts['existing'] ?? 0);
        if ($total < $this->pendingLowThreshold) {
            if ($this->pendingLowSince === null) {
                $this->pendingLowSince = time();
            }
            return;
        }

        $this->pendingLowSince = null;
    }

    private function maybeFinalizeOnLowPending(V8ConsultJob $job): bool
    {
        $this->touchPendingLowTimer();
        if ($this->pendingLowSince === null) {
            return false;
        }

        if ((time() - $this->pendingLowSince) < $this->pendingLowSeconds) {
            return false;
        }

        $this->finalizePendingFilesLowPending($job);
        $this->forceFinish = true;
        return true;
    }

    private function finalizePendingFilesLowPending(V8ConsultJob $job): void
    {
        $disk = Storage::disk($this->disk);
        $message = sprintf(
            'Encerrado: pendências abaixo de %d por mais de %d segundos.',
            $this->pendingLowThreshold,
            $this->pendingLowSeconds
        );

        $prefix = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.pending.";
        foreach ($disk->files($this->dirSpool) as $file) {
            if (strpos($file, $prefix) !== 0) {
                continue;
            }

            $mode = str_contains($file, '.pending.existing') ? 'existing' : 'regular';
            $this->finalizePendingFileError($job, $file, $mode, $message);
            $disk->delete($file);
        }

        $this->pendingCounts['regular'] = 0;
        $this->pendingCounts['existing'] = 0;
    }

    private function parsePendingLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        $parts = str_getcsv($line, ';');
        $cpf = Cpf::normalize($parts[0] ?? null);
        if (!$cpf) {
            return null;
        }
        $nome = $parts[1] ?? '';
        $nasc = $parts[2] ?? '';
        $consultId = $parts[3] ?? '';
        $attempts = isset($parts[4]) ? (int) $parts[4] : 0;
        $reconsentAttempts = isset($parts[5]) ? (int) $parts[5] : 0;

        return [$cpf, $nome, $nasc, $consultId !== '' ? $consultId : null, $attempts, $reconsentAttempts];
    }

    private function entryFromParsed(
        string $cpf,
        string $nome,
        string $nasc,
        ?string $consultId,
        int $attempts,
        int $reconsentAttempts = 0
    ): array
    {
        return [
            'cpf' => $cpf,
            'nome' => $nome,
            'nasc' => $nasc,
            'consult_id' => $consultId,
            'attempts' => $attempts,
            'reconsent_attempts' => $reconsentAttempts,
        ];
    }

    private function finalizePendingFileTimeout(V8ConsultJob $job, string $pendingRel, string $mode): void
    {
        $disk = Storage::disk($this->disk);
        if (!$disk->exists($pendingRel)) {
            return;
        }

        $fh = fopen($disk->path($pendingRel), 'r');
        if ($fh === false) {
            return;
        }

        try {
            while (($line = fgets($fh)) !== false) {
                $parsed = $this->parsePendingLine($line);
                if (!$parsed) {
                    continue;
                }
                [$cpf, $nome, $nasc, $consultId, $attempts, $reconsentAttempts] = $parsed;
                $this->finalizePendingTimeout($job, $this->entryFromParsed($cpf, $nome, $nasc, $consultId, $attempts, $reconsentAttempts), $mode);
            }
        } finally {
            fclose($fh);
        }
    }

    private function finalizePendingFileError(V8ConsultJob $job, string $pendingRel, string $mode, string $message): void
    {
        $disk = Storage::disk($this->disk);
        if (!$disk->exists($pendingRel)) {
            return;
        }

        $fh = fopen($disk->path($pendingRel), 'r');
        if ($fh === false) {
            return;
        }

        try {
            while (($line = fgets($fh)) !== false) {
                $parsed = $this->parsePendingLine($line);
                if (!$parsed) {
                    continue;
                }
                [$cpf, $nome, $nasc, $consultId, $attempts, $reconsentAttempts] = $parsed;
                $this->finalizePendingError($job, $this->entryFromParsed($cpf, $nome, $nasc, $consultId, $attempts, $reconsentAttempts), $mode, $message);
            }
        } finally {
            fclose($fh);
        }
    }

    private function statusFromItem(array $item): array
    {
        $status = $item['status'] ?? null;
        $resp = [
            'ok' => true,
            'status' => $status,
            'available_margin_value' => $item['availableMarginValue'] ?? null,
        ];

        if ($status === 'REJECTED') {
            $resp['error'] = $item['description'] ?? 'Contrato não elegível.';
            return $resp;
        }

        if ($status === 'SUCCESS') {
            return $resp;
        }

        $resp['error'] = $item['description'] ?? 'Status não suportado.';
        return $resp;
    }

    private function normalizeCpfKey(string $value): string
    {
        $cpf = Cpf::normalize($value);
        return $cpf ? $cpf : '';
    }

    private function isAuthorizeAlreadyApproved(array $resp, ?string $consultId): bool
    {
        if (($resp['type'] ?? null) !== 'consult_already_approved') {
            return false;
        }

        $approvedId = $this->extractConsultIdFromMessage((string) ($resp['error'] ?? ''));
        if (!$approvedId) {
            return $consultId !== null && $consultId !== '';
        }

        return $consultId !== null && strcasecmp($approvedId, $consultId) === 0;
    }

    private function extractConsultIdFromMessage(string $message): ?string
    {
        if ($message === '') {
            return null;
        }

        if (preg_match('/ID\\s+([0-9a-fA-F-]{36})/i', $message, $m)) {
            return $m[1] ?? null;
        }

        return null;
    }

    private function extractHttpError(HttpResponse $resp): array
    {
        $json = $resp->json();
        $type = null;
        $message = null;

        if (is_array($json)) {
            $type = $json['type'] ?? null;
            $message = $json['detail'] ?? $json['message'] ?? $json['mensagem'] ?? $json['title'] ?? null;
        }

        if (!$message) {
            $body = trim(strip_tags((string) $resp->body()));
            $message = $body !== '' ? $body : 'Erro na API V8';
        }

        return [
            'type' => $type,
            'error' => $message,
            'status' => $resp->status(),
            'retriable' => $resp->status() === 429 || $resp->status() >= 500,
        ];
    }

    private function logContextFromHttpError(array $err): array
    {
        return [
            'status' => $err['status'] ?? null,
            'type' => $err['type'] ?? null,
            'retriable' => $err['retriable'] ?? null,
        ];
    }

    private function finalizePendingTimeout(V8ConsultJob $job, array $entry, string $mode): void
    {
        $cpf = (string) ($entry['cpf'] ?? '');
        $nome = (string) ($entry['nome'] ?? '');
        $nasc = (string) ($entry['nasc'] ?? '');
        $consultId = $entry['consult_id'] ?? null;

        $row = $this->baseRow($cpf, $nome, $nasc);
        $row['consult_id'] = $consultId;

        if ($mode === 'existing') {
            $row['status'] = 'FALHOU';
            $this->markErro($row, 'cliente bugado na api');
            $this->logCpfFailure('status', $cpf, $consultId, $row['mensagem']);
        } else {
            $row['status'] = 'NAO_ELEGIVEL';
            $this->markNaoElegivel($row, 'Timeout ao aguardar status de consentimento.');
            $this->logCpfFailure('status', $cpf, $consultId, $row['mensagem']);
        }

        $this->spoolAppendManyPersist($job, [$row]);
    }

    private function finalizePendingError(V8ConsultJob $job, array $entry, string $mode, string $message): void
    {
        $cpf = (string) ($entry['cpf'] ?? '');
        $nome = (string) ($entry['nome'] ?? '');
        $nasc = (string) ($entry['nasc'] ?? '');
        $consultId = $entry['consult_id'] ?? null;

        $row = $this->baseRow($cpf, $nome, $nasc);
        $row['consult_id'] = $consultId;

        if ($mode === 'existing') {
            $row['status'] = 'FALHOU';
            $this->markErro($row, $message);
            $this->logCpfFailure('status', $cpf, $consultId, $row['mensagem']);
        } else {
            $row['status'] = 'NAO_ELEGIVEL';
            $this->markNaoElegivel($row, $message);
            $this->logCpfFailure('status', $cpf, $consultId, $row['mensagem']);
        }

        $this->spoolAppendManyPersist($job, [$row]);
    }

    private function processExistingConsent(
        V8ApiService $api,
        V8ConsultJob $job,
        string $cpf,
        string $nome,
        string $nasc
    ): void {
        $row = $this->baseRow($cpf, $nome, $nasc);
        $row['status'] = 'CONSENT_APPROVED';

        $statusResp = $this->pollStatusByCpfFirst($api, $cpf, $this->statusMaxAttemptsExisting);
        if (!$statusResp['ok']) {
            $this->markErro($row, $statusResp['error'] ?? 'cliente bugado na api');
            $this->logCpfFailure('status', $cpf, null, $row['mensagem']);
            $row['status'] = 'FALHOU';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $row['consult_id'] = $statusResp['consult_id'] ?? null;
        $row['available_margin_value'] = $statusResp['available_margin_value'] ?? null;

        $status = $statusResp['status'] ?? null;
        if ($status !== 'SUCCESS') {
            $this->markNaoElegivel($row, $statusResp['error'] ?? ($status ? "Status {$status}" : 'Status inválido.'));
            $this->logCpfFailure('status', $cpf, $row['consult_id'], $row['mensagem'], ['status' => $status]);
            $row['status'] = 'NAO_ELEGIVEL';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $provider = (string) config('v8.bff.provider', 'QI');
        $configId = (string) config('v8.bff.config_id', '');
        $disbursedAmount = (int) config('v8.simulation.disbursed_amount', 500);
        $installments = (int) config('v8.simulation.installments', 24);

        $consultId = $row['consult_id'];
        if (!$consultId) {
            $this->markErro($row, 'ID de consulta ausente.');
            $this->logCpfFailure('status', $cpf, null, $row['mensagem']);
            $row['status'] = 'FALHOU';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $api->setRateLimitMs($this->httpMinIntervalPhase2Simulation);
        $simResult = $this->simulateWithInstallmentsFallback($api, [
            'consult_id' => $consultId,
            'config_id' => $configId,
            'disbursed_amount' => $disbursedAmount,
            'number_of_installments' => $installments,
            'provider' => $provider,
        ], $cpf, $consultId);
        $simResp = $simResult['resp'];

        if (!$simResp['ok']) {
            $this->markNaoElegivel($row, $this->simulationErrorMessage($simResp));
            $this->logCpfFailure('simulation', $cpf, $consultId, $row['mensagem'], $this->logContextFromApi($simResp));
            $row['status'] = 'NAO_ELEGIVEL';
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $this->applySimulation($row, $simResp['data'] ?? []);
        $row['status'] = 'SUCESSO';
        $this->accSuccess++;
        $this->spoolAppendManyPersist($job, [$row]);
    }

    private function pollStatus(V8ApiService $api, string $cpf, string $consultId): array
    {
        $api->setRateLimitMs($this->httpMinIntervalPhase2Status);
        $start = Carbon::now('UTC')->subHours($this->statusLookbackHours)->startOfDay();
        $end = Carbon::now('UTC')->endOfDay();

        for ($attempt = 1; $attempt <= $this->statusMaxAttempts; $attempt++) {
            $resp = $api->listConsults([
                'startDate' => $start->format('Y-m-d\\TH:i:s\\Z'),
                'endDate' => $end->format('Y-m-d\\TH:i:s\\Z'),
                'limit' => 50,
                'page' => 1,
                'provider' => (string) config('v8.bff.provider', 'QI'),
                'search' => $cpf,
            ]);

            if (!$resp['ok']) {
                if (!($resp['retriable'] ?? false)) {
                    return [
                        'ok' => false,
                        'error' => $this->formatApiError($resp),
                    ];
                }
                sleep($this->statusRetryDelay);
                continue;
            }

            $data = $resp['data']['data'] ?? [];
            if (!is_array($data)) {
                sleep($this->statusRetryDelay);
                continue;
            }

            $match = null;
            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (($item['id'] ?? null) === $consultId) {
                    $match = $item;
                    break;
                }
            }

            if (!$match) {
                sleep($this->statusRetryDelay);
                continue;
            }

            $status = $match['status'] ?? null;
            if ($status === 'WAITING_CONSULT' || $status === 'CONSENT_APPROVED' || $status === 'WAITING_CREDIT_ANALYSIS' || $status === 'WAITING_CONSENT') {
                if ($status === 'WAITING_CONSENT') {
                    $authResp = $api->authorizeConsult($consultId);
                    if (!$authResp['ok'] && !($authResp['retriable'] ?? false)) {
                        return [
                            'ok' => false,
                            'error' => $this->formatApiError($authResp),
                        ];
                    }
                }
                sleep($this->statusRetryDelay);
                continue;
            }

            if ($status === 'REJECTED') {
                return [
                    'ok' => true,
                    'status' => $status,
                    'available_margin_value' => $match['availableMarginValue'] ?? null,
                    'error' => $match['description'] ?? 'Contrato não elegível.',
                ];
            }

            if ($status === 'SUCCESS') {
                return [
                    'ok' => true,
                    'status' => $status,
                    'available_margin_value' => $match['availableMarginValue'] ?? null,
                ];
            }

            return [
                'ok' => true,
                'status' => $status,
                'available_margin_value' => $match['availableMarginValue'] ?? null,
                'error' => $match['description'] ?? 'Status não suportado.',
            ];
        }

        return [
            'ok' => false,
            'error' => 'Timeout ao aguardar status de consentimento.',
        ];
    }

    private function checkStatusOnceByConsultId(V8ApiService $api, string $cpf, string $consultId): array
    {
        $start = Carbon::now('UTC')->subHours($this->statusLookbackHours)->startOfDay();
        $end = Carbon::now('UTC')->endOfDay();

        $resp = $api->listConsults([
            'startDate' => $start->format('Y-m-d\\TH:i:s\\Z'),
            'endDate' => $end->format('Y-m-d\\TH:i:s\\Z'),
            'limit' => 50,
            'page' => 1,
            'provider' => (string) config('v8.bff.provider', 'QI'),
            'search' => $cpf,
        ]);

        if (!$resp['ok']) {
            if (!($resp['retriable'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => $this->formatApiError($resp),
                ];
            }
            return [
                'ok' => true,
                'status' => 'WAITING_CONSULT',
                'retriable' => true,
            ];
        }

        $data = $resp['data']['data'] ?? [];
        if (!is_array($data)) {
            return [
                'ok' => true,
                'status' => 'WAITING_CONSULT',
            ];
        }

        $match = null;
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['id'] ?? null) === $consultId) {
                $match = $item;
                break;
            }
        }

        if (!$match) {
            return [
                'ok' => true,
                'status' => 'WAITING_CONSULT',
            ];
        }

        $status = $match['status'] ?? null;
        if ($status === 'WAITING_CONSENT') {
            $authResp = $api->authorizeConsult($consultId);
            if (
                !$authResp['ok']
                && !($authResp['retriable'] ?? false)
                && !$this->isAuthorizeAlreadyApproved($authResp, $consultId)
            ) {
                return [
                    'ok' => false,
                    'error' => $this->formatApiError($authResp),
                ];
            }
        }

        if ($status === 'REJECTED') {
            return [
                'ok' => true,
                'status' => $status,
                'available_margin_value' => $match['availableMarginValue'] ?? null,
                'error' => $match['description'] ?? 'Contrato não elegível.',
            ];
        }

        if ($status === 'SUCCESS') {
            return [
                'ok' => true,
                'status' => $status,
                'available_margin_value' => $match['availableMarginValue'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'available_margin_value' => $match['availableMarginValue'] ?? null,
            'error' => $match['description'] ?? 'Status não suportado.',
        ];
    }

    private function checkStatusOnceByCpfFirst(V8ApiService $api, string $cpf): array
    {
        $start = Carbon::now('UTC')->subHours($this->statusLookbackHours)->startOfDay();
        $end = Carbon::now('UTC')->endOfDay();

        $resp = $api->listConsults([
            'startDate' => $start->format('Y-m-d\\TH:i:s\\Z'),
            'endDate' => $end->format('Y-m-d\\TH:i:s\\Z'),
            'limit' => 50,
            'page' => 1,
            'provider' => (string) config('v8.bff.provider', 'QI'),
            'search' => $cpf,
        ]);

        if (!$resp['ok']) {
            if (!($resp['retriable'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => $this->formatApiError($resp),
                ];
            }
            return [
                'ok' => true,
                'status' => 'WAITING_CONSULT',
                'retriable' => true,
            ];
        }

        $data = $resp['data']['data'] ?? [];
        if (!is_array($data) || empty($data)) {
            return [
                'ok' => true,
                'status' => 'WAITING_CONSULT',
            ];
        }

        $match = $data[0] ?? null;
        if (!is_array($match)) {
            return [
                'ok' => true,
                'status' => 'WAITING_CONSULT',
            ];
        }

        $consultId = $match['id'] ?? null;
        $status = $match['status'] ?? null;

        if ($status === 'WAITING_CONSENT' && $consultId) {
            $authResp = $api->authorizeConsult($consultId);
            if (
                !$authResp['ok']
                && !($authResp['retriable'] ?? false)
                && !$this->isAuthorizeAlreadyApproved($authResp, $consultId)
            ) {
                return [
                    'ok' => false,
                    'error' => $this->formatApiError($authResp),
                ];
            }
        }

        if ($status === 'REJECTED') {
            return [
                'ok' => true,
                'status' => $status,
                'consult_id' => $consultId,
                'available_margin_value' => $match['availableMarginValue'] ?? null,
                'error' => $match['description'] ?? 'Contrato não elegível.',
            ];
        }

        if ($status === 'SUCCESS') {
            return [
                'ok' => true,
                'status' => $status,
                'consult_id' => $consultId,
                'available_margin_value' => $match['availableMarginValue'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'consult_id' => $consultId,
            'available_margin_value' => $match['availableMarginValue'] ?? null,
            'error' => $match['description'] ?? 'Status não suportado.',
        ];
    }

    private function isWaitingStatus(?string $status): bool
    {
        return $status === 'WAITING_CONSULT'
            || $status === 'CONSENT_APPROVED'
            || $status === 'WAITING_CREDIT_ANALYSIS'
            || $status === 'WAITING_CONSENT';
    }

    private function pollStatusByCpfFirst(V8ApiService $api, string $cpf, int $maxAttempts): array
    {
        $api->setRateLimitMs($this->httpMinIntervalPhase2Status);
        $start = Carbon::now('UTC')->subHours($this->statusLookbackHours)->startOfDay();
        $end = Carbon::now('UTC')->endOfDay();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $resp = $api->listConsults([
                'startDate' => $start->format('Y-m-d\\TH:i:s\\Z'),
                'endDate' => $end->format('Y-m-d\\TH:i:s\\Z'),
                'limit' => 50,
                'page' => 1,
                'provider' => (string) config('v8.bff.provider', 'QI'),
                'search' => $cpf,
            ]);

            if (!$resp['ok']) {
                if (!($resp['retriable'] ?? false)) {
                    return [
                        'ok' => false,
                        'error' => $this->formatApiError($resp),
                    ];
                }
                sleep($this->statusRetryDelay);
                continue;
            }

            $data = $resp['data']['data'] ?? [];
            if (!is_array($data) || empty($data)) {
                sleep($this->statusRetryDelay);
                continue;
            }

            $match = $data[0] ?? null;
            if (!is_array($match)) {
                sleep($this->statusRetryDelay);
                continue;
            }

            $status = $match['status'] ?? null;
            if ($status === 'WAITING_CONSULT' || $status === 'CONSENT_APPROVED' || $status === 'WAITING_CREDIT_ANALYSIS' || $status === 'WAITING_CONSENT') {
                if ($status === 'WAITING_CONSENT') {
                    $matchId = (string) ($match['id'] ?? '');
                    $authResp = $api->authorizeConsult($matchId);
                    if (
                        !$authResp['ok']
                        && !($authResp['retriable'] ?? false)
                        && !$this->isAuthorizeAlreadyApproved($authResp, $matchId)
                    ) {
                        return [
                            'ok' => false,
                            'error' => $this->formatApiError($authResp),
                        ];
                    }
                }
                sleep($this->statusRetryDelay);
                continue;
            }

            if ($status === 'REJECTED') {
                return [
                    'ok' => true,
                    'status' => $status,
                    'consult_id' => $match['id'] ?? null,
                    'available_margin_value' => $match['availableMarginValue'] ?? null,
                    'error' => $match['description'] ?? 'Contrato não elegível.',
                ];
            }

            if ($status === 'SUCCESS') {
                return [
                    'ok' => true,
                    'status' => $status,
                    'consult_id' => $match['id'] ?? null,
                    'available_margin_value' => $match['availableMarginValue'] ?? null,
                ];
            }

            return [
                'ok' => true,
                'status' => $status,
                'consult_id' => $match['id'] ?? null,
                'available_margin_value' => $match['availableMarginValue'] ?? null,
                'error' => $match['description'] ?? 'Status não suportado.',
            ];
        }

        return [
            'ok' => false,
            'error' => 'cliente bugado na api',
        ];
    }

    private function applySimulation(array &$row, array $data): void
    {
        $row['id_simulation'] = $data['id_simulation'] ?? null;
        $row['installment_value'] = $data['installment_value'] ?? null;
        $row['number_of_installments'] = $data['number_of_installments'] ?? null;
        $row['operation_amount'] = $data['operation_amount'] ?? null;
        $row['issue_amount'] = $data['issue_amount'] ?? null;
        $row['disbursement_option_iof_amount'] = $data['disbursement_option']['iof_amount'] ?? null;
        $row['iof_amount'] = $data['iof_amount'] ?? null;
        $row['monthly_interest_rate'] = $data['monthly_interest_rate'] ?? null;
        $row['disbursed_issue_amount'] = $data['disbursed_issue_amount'] ?? null;
        $row['disbursement_amount'] = $data['disbursement_amount'] ?? null;
        $row['first_installment_date'] = $data['first_installment_date'] ?? null;
        $row['is_insured'] = $data['is_insured'] ?? null;
        $row['insurance_amount'] = $data['insurance_amount'] ?? null;
    }

    private function simulateWithInstallmentsFallback(V8ApiService $api, array $payload, string $cpf, ?string $consultId): array
    {
        $resp = $api->simulate($payload);
        if ($resp['ok']) {
            return ['resp' => $resp];
        }

        if (($resp['type'] ?? null) !== 'simulation_installments_above_maximum') {
            return ['resp' => $resp];
        }

        $maxInstallments = $this->extractInstallmentsMax($resp['error'] ?? '');
        if (!$maxInstallments) {
            return ['resp' => $resp];
        }

        $current = (int) ($payload['number_of_installments'] ?? 0);
        if ($current === $maxInstallments) {
            return ['resp' => $resp];
        }

        $payload['number_of_installments'] = $maxInstallments;
        $resp2 = $api->simulate($payload);
        if (!$resp2['ok']) {
            $this->logCpfFailure('simulation', $cpf, $consultId, 'Fallback de parcelas falhou.', array_merge(
                $this->logContextFromApi($resp2),
                ['installments' => $maxInstallments]
            ));
            return ['resp' => $resp2];
        }

        return ['resp' => $resp2];
    }

    private function extractInstallmentsMax(string $message): ?int
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        if (preg_match('/maior\\s+que\\s*(\\d+)/i', $message, $m)) {
            $value = (int) $m[1];
            return $value > 0 ? $value : null;
        }

        if (preg_match('/maximum\\D*(\\d+)/i', $message, $m)) {
            $value = (int) $m[1];
            return $value > 0 ? $value : null;
        }

        if (preg_match('/(\\d+)/', $message, $m)) {
            $value = (int) $m[1];
            return $value > 0 ? $value : null;
        }

        return null;
    }

    private function formatApiError(array $resp): string
    {
        $type = $resp['type'] ?? null;
        $error = $resp['error'] ?? null;
        if ($type === 'age_validation_minimum_age_not_reached' && $error) {
            return (string) $error;
        }
        if ($type && $error) {
            return "{$type}: {$error}";
        }
        if ($error) {
            return (string) $error;
        }
        return 'Erro na API V8.';
    }

    private function simulationErrorMessage(array $resp): string
    {
        $type = $resp['type'] ?? null;

        if ($type === 'simulation_installment_value_above_margin' || $type === 'simulation_not_eligible') {
            return 'Margem insuficiente.';
        }

        return $this->formatApiError($resp);
    }


    private function splitEntryLine(string $line): array
    {
        $parts = explode(';', $line);
        $cpf = Cpf::normalize($parts[0] ?? null);
        $nome = $this->cleanName($parts[1] ?? '');
        $nasc = trim($parts[2] ?? '');
        return [$cpf, $nome, $nasc];
    }

    private function splitConsentLine(string $line): array
    {
        $parts = explode(';', $line);
        $cpf = Cpf::normalize($parts[0] ?? null);
        $nome = $this->cleanName($parts[1] ?? '');
        $nasc = trim($parts[2] ?? '');
        $consultId = trim($parts[3] ?? '');
        $mode = trim($parts[4] ?? '');
        return [$cpf, $nome, $nasc, $consultId, $mode];
    }

    private function baseRow(string $cpf, ?string $nome, ?string $nasc): array
    {
        $row = array_fill_keys(V8Schema::COLS, null);
        $row['cpf'] = $cpf;
        $row['nome'] = $this->cleanName($nome);
        $row['data_nascimento'] = $nasc;
        return $row;
    }

    private function appendErrorRow(V8ConsultJob $job, ?string $cpf, ?string $nome, ?string $nasc, string $error): void
    {
        $row = $this->baseRow($cpf ?? '', $nome, $nasc);
        $row['status'] = 'ERROR';
        $this->markErro($row, $error);
        $row['status'] = 'FALHOU';
        $this->spoolAppendManyPersist($job, [$row]);
    }

    private function markNaoElegivel(array &$row, string $message): void
    {
        $row['mensagem'] = $this->formatMessageWithPhase('NAO_ELEGIVEL', $message);
        $this->accNaoElegivel++;
    }

    private function markErro(array &$row, string $message): void
    {
        $row['mensagem'] = $this->formatMessageWithPhase('ERRO', $message);
        $this->accFail++;
    }

    private function formatMessageWithPhase(string $type, string $message): string
    {
        if ($message === '') {
            return $message;
        }

        if (str_starts_with($message, '[') && str_contains($message, 'FASE')) {
            return $message;
        }

        $phase = $this->currentPhase !== '' ? $this->currentPhase : 'FASE';
        return sprintf('[%s] %s', $phase, $message);
    }

    private function shouldReconsentBlocked(array $statusResp): bool
    {
        if (($statusResp['status'] ?? null) !== 'REJECTED') {
            return false;
        }

        $rawError = (string) ($statusResp['error'] ?? '');
        $error = function_exists('mb_strtolower')
            ? mb_strtolower($rawError, 'UTF-8')
            : strtolower($rawError);
        if ($error === '') {
            return false;
        }

        return str_contains($error, 'consulta de margem bloqueada pelo trabalhador');
    }

    private function attemptReconsentBlocked(
        V8ApiService $api,
        V8ConsultJob $job,
        string $cpf,
        string $nome,
        string $nasc,
        ?string $oldConsultId
    ): array {
        $gender = $this->genderFromName($nome);
        if (!$gender) {
            return [
                'status' => 'error',
                'error' => 'Genero nao encontrado no IBGE.',
            ];
        }

        $consultResp = $api->createConsult([
            'borrowerDocumentNumber' => $cpf,
            'gender' => $gender,
            'birthDate' => $nasc,
            'signerName' => $nome,
            'signerEmail' => (string) config('v8.signer.email', 'luangstl@gmail.com'),
            'signerPhone' => [
                'phoneNumber' => (string) config('v8.signer.phone_number', '997664631'),
                'countryCode' => (string) config('v8.signer.phone_country', '55'),
                'areaCode' => (string) config('v8.signer.phone_area', '47'),
            ],
            'provider' => (string) config('v8.bff.provider', 'QI'),
        ]);

        if (!$consultResp['ok']) {
            if (($consultResp['type'] ?? null) === 'consult_already_exists_by_user_and_document_number') {
                return ['status' => 'existing'];
            }

            $this->logCpfFailure('reconsent', $cpf, $oldConsultId, $this->formatApiError($consultResp), $this->logContextFromApi($consultResp));
            return [
                'status' => 'error',
                'error' => $this->formatApiError($consultResp),
            ];
        }

        $consultId = $consultResp['data']['id'] ?? null;
        if (!is_string($consultId) || $consultId === '') {
            return [
                'status' => 'error',
                'error' => 'ID de consulta ausente.',
            ];
        }

        $authResp = $api->authorizeConsult($consultId);
        if (!$authResp['ok'] && !$this->isAuthorizeAlreadyApproved($authResp, $consultId)) {
            $this->logCpfFailure('reconsent', $cpf, $consultId, $this->formatApiError($authResp), $this->logContextFromApi($authResp));
            return [
                'status' => 'error',
                'error' => $this->formatApiError($authResp),
            ];
        }

        return [
            'status' => 'ok',
            'consult_id' => $consultId,
        ];
    }

    private function logCpfFailure(string $step, ?string $cpf, ?string $consultId, string $message, array $extra = []): void
    {
        try {
            Log::warning('[V8] CPF falhou', array_merge([
                'job_id' => $this->jobId,
                'step' => $step,
                'cpf' => $cpf,
                'consult_id' => $consultId,
                'message' => $message,
            ], $extra));
        } catch (Throwable) {
        }
    }

    private function logContextFromApi(array $resp): array
    {
        return [
            'status' => $resp['status'] ?? null,
            'type' => $resp['type'] ?? null,
            'retriable' => $resp['retriable'] ?? null,
        ];
    }

    private function truncate(string $value, int $limit = 120): string
    {
        $value = trim($value);
        if (strlen($value) <= $limit) {
            return $value;
        }
        return substr($value, 0, $limit) . '...';
    }

    private function genderFromName(string $nome): ?string
    {
        $first = $this->extractFirstName($nome);
        if (!$first) {
            return null;
        }
        $first = $this->upper($first);
        $gender = IbgeName::query()->where('name', $first)->value('gender');
        if (!is_string($gender) || $gender === '') {
            return null;
        }
        $gender = strtoupper($gender);
        if ($gender === 'M') {
            return 'male';
        }
        if ($gender === 'F') {
            return 'female';
        }
        return null;
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

    private function buildUniqueEntriesFile(string $inputsReal, string $uniqRel, V8ConsultJob $job): array
    {
        $disk = Storage::disk($this->disk);
        $uniqReal = $this->isAbsolutePath($uniqRel) ? $uniqRel : $disk->path($uniqRel);

        if (!$disk->exists($this->dirSpool)) {
            $disk->makeDirectory($this->dirSpool);
        }

        $blockSize = 5000;
        $chunks = [];

        $invalidCount = 0;

        $r = fopen($inputsReal, 'r');
        if ($r === false) {
            $this->logCpfFailure('inputs', null, null, 'Falha ao abrir arquivo de inputs.', [
                'inputs_real' => $inputsReal,
                'error' => error_get_last()['message'] ?? null,
            ]);
            return [0, $invalidCount];
        }

        try {
            $block = [];
            while (($line = fgets($r)) !== false) {
                if ($this->finishIfStopped($job)) {
                    return [0, $invalidCount];
                }

                $parsed = $this->parseRawLine($line);
                if ($parsed['error']) {
                    $this->appendErrorRow($job, $parsed['cpf'] ?? '', $parsed['nome'] ?? null, $parsed['nasc'] ?? null, $parsed['error']);
                    $this->logCpfFailure('parse', $parsed['cpf'] ?? '', null, $parsed['error'], [
                        'raw' => $this->truncate($line),
                    ]);
                    $invalidCount++;
                    continue;
                }

                $cpf = $parsed['cpf'];
                $lineOut = $cpf . ';' . $parsed['nome'] . ';' . $parsed['nasc'];
                $block[$cpf] = $lineOut;

                if (count($block) >= $blockSize || $this->shouldSpill(count($block))) {
                    $chunks[] = $this->writeSortedChunk($block);
                    $block = [];
                }
            }

            if (!empty($block)) {
                $chunks[] = $this->writeSortedChunk($block);
                $block = [];
            }
        } finally {
            fclose($r);
        }

        if (empty($chunks)) {
            $w = fopen($uniqReal, 'w');
            if ($w !== false) {
                fclose($w);
            }
            return [0, $invalidCount];
        }

        if (count($chunks) === 1) {
            @rename($chunks[0], $uniqReal);
            return [$this->countLines($uniqReal), $invalidCount];
        }

        $w = fopen($uniqReal, 'w');
        if ($w === false) {
            foreach ($chunks as $c) {
                @unlink($c);
            }
            return [0, $invalidCount];
        }

        $handles = [];
        $heads = [];
        foreach ($chunks as $i => $pReal) {
            $h = fopen($pReal, 'r');
            if ($h !== false) {
                $handles[$i] = $h;
                $heads[$i] = fgets($h);
            }
        }

        $written = 0;
        $lastCpf = null;
        while (!empty($handles)) {
            $minIdx = null;
            $minVal = null;
            foreach ($heads as $idx => $val) {
                if ($val === false || $val === null) {
                    continue;
                }
                $val = trim($val);
                if ($val === '') {
                    continue;
                }
                if ($minVal === null || strcmp($val, $minVal) < 0) {
                    $minVal = $val;
                    $minIdx = $idx;
                }
            }
            if ($minIdx === null) {
                break;
            }

            $cpf = $this->lineCpf($minVal);
            if ($cpf !== '' && $cpf !== $lastCpf) {
                fwrite($w, $minVal . "\n");
                $written++;
                $lastCpf = $cpf;
            }

            $heads[$minIdx] = fgets($handles[$minIdx]);
            if ($heads[$minIdx] === false) {
                fclose($handles[$minIdx]);
                unset($handles[$minIdx], $heads[$minIdx]);
            }
        }
        fclose($w);

        foreach ($chunks as $c) {
            @unlink($c);
        }

        return [$written, $invalidCount];
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

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
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

    private function lineCpf(string $line): string
    {
        $pos = strpos($line, ';');
        if ($pos === false) {
            return trim($line);
        }
        return trim(substr($line, 0, $pos));
    }

    private function writeSortedChunk(array $block): string
    {
        $disk = Storage::disk($this->disk);

        if (!$disk->exists($this->dirSpool)) {
            $disk->makeDirectory($this->dirSpool);
        }

        $rel = "{$this->dirSpool}/{$this->finalPrefix}_{$this->jobId}.inputs.chunk." . uniqid('', true) . ".txt";
        $real = $disk->path($rel);
        $this->pendFiles[] = $rel;

        ksort($block, SORT_STRING);
        $w = fopen($real, 'w');
        if ($w !== false) {
            foreach ($block as $line) {
                fwrite($w, $line . "\n");
            }
            fclose($w);
        }
        return $real;
    }

    private function countLines(string $real): int
    {
        $cnt = 0;
        $fh = fopen($real, 'r');
        if ($fh !== false) {
            while (!feof($fh)) {
                if (fgets($fh) !== false) {
                    $cnt++;
                }
            }
            fclose($fh);
        }
        return $cnt;
    }

    private function spoolAppendManyPersist(V8ConsultJob $job, array $rows): void
    {
        if (!is_resource($this->spoolFp)) {
            throw new \RuntimeException('Writer do spool não inicializado.');
        }
        $finishedAt = Carbon::now('America/Sao_Paulo')->toDateTimeString();
        if (flock($this->spoolFp, LOCK_EX)) {
            foreach ($rows as $row) {
                if (!empty($row['status']) && empty($row['finished_at'])) {
                    $row['finished_at'] = $finishedAt;
                }
                $ordered = [];
                foreach (V8Schema::COLS as $key) {
                    $ordered[] = $row[$key] ?? null;
                }
                fputcsv($this->spoolFp, $ordered, ';');
            }
            fflush($this->spoolFp);
            flock($this->spoolFp, LOCK_UN);
        }

        $this->updateTotalsThrottled($job);
    }

    private function updateTotalsThrottled(V8ConsultJob $job, array $extra = [], bool $force = false): void
    {
        $now = microtime(true);

        try {
            clearstatcache(true, $this->spoolReal);
            $bytes = file_exists($this->spoolReal) ? (int) filesize($this->spoolReal) : 0;
        } catch (Throwable) {
            $bytes = 0;
        }

        $triggerTime = ($now - $this->lastFlushAt) >= $this->flushEverySecs;
        $shouldFlush = $force || $triggerTime;
        if (!$shouldFlush) {
            return;
        }

        $updates = array_merge([
            'spool_bytes' => $bytes,
            'updated_at' => Carbon::now(),
        ], $extra);

        if ($this->accSuccess > 0) {
            $updates['success_count'] = DB::raw('COALESCE(success_count,0) + ' . $this->accSuccess);
        }
        if ($this->accNaoElegivel > 0) {
            $updates['nao_elegivel_count'] = DB::raw('COALESCE(nao_elegivel_count,0) + ' . $this->accNaoElegivel);
        }
        if ($this->accFail > 0) {
            $updates['fail_count'] = DB::raw('COALESCE(fail_count,0) + ' . $this->accFail);
        }

        DB::table('v8_consult_jobs')->where('id', $job->id)->update($updates);

        $job->spool_bytes = $bytes;
        $this->lastFlushAt = $now;
        $this->accSuccess = 0;
        $this->accNaoElegivel = 0;
        $this->accFail = 0;
    }

    private function finishIfStopped(V8ConsultJob $job): bool
    {
        if ($this->isCancelled($job)) {
            $this->cleanupSpool($job);
            return true;
        }
        if ($this->pauseIfNeeded($job)) {
            return true;
        }
        return false;
    }

    private function isCancelled(V8ConsultJob $job): bool
    {
        $status = DB::table('v8_consult_jobs')->where('id', $job->id)->value('status');
        return $status === 'cancelado';
    }

    private function pauseIfNeeded(V8ConsultJob $job): bool
    {
        if (!$this->pauseEnabled || !$this->pauseWindowConfigured()) {
            return false;
        }

        $now = microtime(true);
        if (!$this->isPaused && ($now - $this->lastPauseCheckAt) < $this->pauseCheckIntervalSeconds) {
            return false;
        }
        $this->lastPauseCheckAt = $now;

        $resumeAt = $this->pauseResumeAt();
        if (!$resumeAt) {
            if ($this->isPaused) {
                $this->setJobStatus($job, 'em_progresso');
                $this->isPaused = false;
            }
            return false;
        }

        if (!$this->isPaused) {
            $this->setJobStatus($job, 'pausado');
            $this->isPaused = true;
        }

        if (!$this->sleepUntil($job, $resumeAt)) {
            return true;
        }

        $this->setJobStatus($job, 'em_progresso');
        $this->isPaused = false;
        return false;
    }

    private function pauseWindowConfigured(): bool
    {
        return preg_match('/^\\d{2}:\\d{2}$/', $this->pauseStart) === 1
            && preg_match('/^\\d{2}:\\d{2}$/', $this->pauseEnd) === 1
            && $this->pauseStart !== $this->pauseEnd;
    }

    private function pauseResumeAt(): ?Carbon
    {
        $tz = $this->pauseTimezone !== '' ? $this->pauseTimezone : 'America/Sao_Paulo';
        $now = Carbon::now($tz);
        $start = $now->copy()->setTimeFromTimeString($this->pauseStart);
        $end = $now->copy()->setTimeFromTimeString($this->pauseEnd);

        if ($start->eq($end)) {
            return null;
        }

        if ($start->lt($end)) {
            if ($now->gte($start) && $now->lt($end)) {
                return $end;
            }
            return null;
        }

        if ($now->gte($start)) {
            return $end->addDay();
        }

        if ($now->lt($end)) {
            return $end;
        }

        return null;
    }

    private function sleepUntil(V8ConsultJob $job, Carbon $resumeAt): bool
    {
        $tz = $this->pauseTimezone !== '' ? $this->pauseTimezone : 'America/Sao_Paulo';
        $now = Carbon::now($tz);
        if ($resumeAt->lte($now)) {
            return true;
        }

        while ($resumeAt->gt($now)) {
            if ($this->isCancelled($job)) {
                $this->cleanupSpool($job);
                return false;
            }

            $remaining = $resumeAt->diffInSeconds($now);
            $sleepFor = min(60, max(1, $remaining));
            sleep($sleepFor);
            $now = Carbon::now($tz);
        }

        return true;
    }

    private function setJobStatus(V8ConsultJob $job, string $status): void
    {
        if ($job->status === $status) {
            return;
        }

        $job->status = $status;
        try {
            DB::table('v8_consult_jobs')->where('id', $job->id)->update([
                'status' => $status,
                'updated_at' => Carbon::now(),
            ]);
        } catch (Throwable) {
        }
    }

    private function cleanupSpool(V8ConsultJob $job): void
    {
        try {
            $disk = Storage::disk($this->disk);
            foreach (['spool_path', 'spool_inputs_path'] as $f) {
                $p = $job->{$f} ?? null;
                if ($p && $disk->exists($p)) {
                    try {
                        $disk->delete($p);
                    } catch (Throwable) {
                    }
                }
            }
            $this->deletePendFiles();
            $this->cleanupSpoolArtifacts($job, $disk);
        } finally {
            $job->updateQuietly(['spool_path' => null, 'spool_inputs_path' => null, 'spool_bytes' => 0]);
        }
    }

    private function cleanupSpoolArtifacts(V8ConsultJob $job, $disk): void
    {
        try {
            $dirSpool = (string) (config('v8.storage.dir_spool') ?? 'v8-spool');
            $prefix = (string) (config('v8.storage.final_prefix') ?? 'v8-consulta');
            $prefix = $prefix . '_' . $job->id;

            if (!$disk->exists($dirSpool)) {
                return;
            }

            foreach ($disk->files($dirSpool) as $rel) {
                $base = basename($rel);
                if (str_starts_with($base, $prefix)) {
                    try {
                        $disk->delete($rel);
                    } catch (Throwable) {
                    }
                }
            }
        } catch (Throwable) {
        }
    }

    private function sleepWithCancel(V8ConsultJob $job, int $seconds): bool
    {
        $seconds = max(0, $seconds);
        if ($seconds === 0) {
            return true;
        }
        for ($i = 0; $i < $seconds; $i++) {
            if ($this->isCancelled($job)) {
                $this->cleanupSpool($job);
                return false;
            }
            sleep(1);
        }
        return true;
    }

    private function fileSizeSafe(string $disk, string $relPath): int
    {
        try {
            $d = Storage::disk($disk);
            return $d->exists($relPath) ? (int) $d->size($relPath) : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function failFinalize(V8ConsultJob $job): void
    {
        dispatch(new FinalizeV8ConsultReportJob($this->jobId, 'falhou'))
            ->onQueue((string) config('v8.preview.queue', 'reports'));
        $this->deletePendFiles();
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
        $usage = memory_get_usage(true);
        return $usage > (int) ($limit * 0.70);
    }

    private function memoryLimitBytes(): int
    {
        $val = ini_get('memory_limit');
        if ($val === false || $val === '' || $val === '-1') {
            return PHP_INT_MAX;
        }
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int) $val;
        switch ($last) {
            case 'g':
                $num *= 1024;
            case 'm':
                $num *= 1024;
            case 'k':
                $num *= 1024;
        }
        return $num > 0 ? $num : PHP_INT_MAX;
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

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $first = $path[0];
        if ($first === '/' || $first === '\\') {
            return true;
        }
        return (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
