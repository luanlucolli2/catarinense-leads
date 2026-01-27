<?php

namespace App\Jobs;

use App\Models\IbgeName;
use App\Models\V8ConsultJob;
use App\Services\V8ApiService;
use App\Support\V8Schema;
use App\Jobs\FinalizeV8ConsultReportJob;
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
    private int $accFail = 0;

    private int $statusMaxAttempts;
    private int $statusRetryDelay;
    private int $statusLookbackHours;

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
        $this->statusLookbackHours = (int) config('v8.job.status_lookback_hours', 48);
    }

    public function uniqueId(): string
    {
        return (string) $this->jobId;
    }

    public function handle(V8ApiService $api): void
    {
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

        $job->update([
            'status' => 'em_progresso',
            'started_at' => $job->started_at ?? Carbon::now(),
            'spool_bytes' => $this->fileSizeSafe($this->disk, $job->spool_path),
        ]);

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

            $uniqueCount = $this->buildUniqueEntriesFile($inputsReal, $uniqRel, $job);
            if ($uniqueCount === 0) {
                $this->logCpfFailure('inputs', null, null, 'Nenhuma linha válida encontrada.', [
                    'inputs_path' => $job->spool_inputs_path,
                    'inputs_size' => $this->fileSizeSafe($this->disk, $job->spool_inputs_path ?? ''),
                ]);
                $this->updateTotalsThrottled($job, [], true);
                $this->failFinalize($job);
                return;
            }

            $this->updateTotalsThrottled($job, ['total_cpfs' => $uniqueCount], true);

            $reader = fopen($disk->path($uniqRel), 'r');
            if ($reader === false) {
                $this->failFinalize($job);
                return;
            }

            try {
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

                    $this->processEntry($api, $job, $cpf, $nome, $nasc);
                }
            } finally {
                fclose($reader);
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
            $row['error'] = 'Genero nao encontrado no IBGE.';
            $this->logCpfFailure('gender', $cpf, null, $row['error'], ['nome' => $nome]);
            $this->accFail++;
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
            $row['error'] = $this->formatApiError($consultResp);
            $this->logCpfFailure('consult', $cpf, null, $row['error'], $this->logContextFromApi($consultResp));
            $this->accFail++;
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $consultId = $consultResp['data']['id'] ?? null;
        if (!is_string($consultId) || $consultId === '') {
            $row['status'] = 'ERROR';
            $row['error'] = 'ID de consulta ausente.';
            $this->logCpfFailure('consult', $cpf, null, $row['error']);
            $this->accFail++;
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }
        $row['consult_id'] = $consultId;

        $authResp = $api->authorizeConsult($consultId);
        if (!$authResp['ok']) {
            $row['status'] = 'ERROR';
            $row['error'] = $this->formatApiError($authResp);
            $this->logCpfFailure('authorize', $cpf, $consultId, $row['error'], $this->logContextFromApi($authResp));
            $this->accFail++;
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $statusResp = $this->pollStatus($api, $cpf, $consultId);
        if (!$statusResp['ok']) {
            $row['status'] = 'ERROR';
            $row['error'] = $statusResp['error'] ?? 'Falha ao obter status.';
            $this->logCpfFailure('status', $cpf, $consultId, $row['error']);
            $this->accFail++;
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $row['status'] = $statusResp['status'] ?? null;
        $row['available_margin_value'] = $statusResp['available_margin_value'] ?? null;

        $status = $statusResp['status'] ?? null;
        if ($status !== 'SUCCESS') {
            $row['error'] = $statusResp['error'] ?? ($status ? "Status {$status}" : 'Status inválido.');
            $this->logCpfFailure('status', $cpf, $consultId, $row['error'], ['status' => $status]);
            $this->accFail++;
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $simResp = $api->simulate([
            'consult_id' => $consultId,
            'config_id' => $configId,
            'disbursed_amount' => $disbursedAmount,
            'number_of_installments' => $installments,
            'provider' => $provider,
        ]);

        if (!$simResp['ok']) {
            $row['error'] = $this->formatApiError($simResp);
            $this->logCpfFailure('simulation', $cpf, $consultId, $row['error'], $this->logContextFromApi($simResp));
            $this->accFail++;
            $this->spoolAppendManyPersist($job, [$row]);
            return;
        }

        $this->applySimulation($row, $simResp['data'] ?? []);
        $this->accSuccess++;
        $this->spoolAppendManyPersist($job, [$row]);
    }

    private function pollStatus(V8ApiService $api, string $cpf, string $consultId): array
    {
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
            if ($status === 'WAITING_CONSULT' || $status === 'CONSENT_APPROVED') {
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
        $row['provider'] = $data['provider'] ?? null;
        $row['simulation_config_id'] = $data['simulation_config_id'] ?? null;
        $row['simulation_config_slug'] = $data['simulation_config_slug'] ?? null;
    }

    private function formatApiError(array $resp): string
    {
        $type = $resp['type'] ?? null;
        $error = $resp['error'] ?? null;
        if ($type && $error) {
            return "{$type}: {$error}";
        }
        if ($error) {
            return (string) $error;
        }
        return 'Erro na API V8.';
    }

    private function splitEntryLine(string $line): array
    {
        $parts = explode(';', $line);
        $cpf = trim($parts[0] ?? '');
        $nome = trim($parts[1] ?? '');
        $nasc = trim($parts[2] ?? '');
        return [$cpf, $nome, $nasc];
    }

    private function baseRow(string $cpf, ?string $nome, ?string $nasc): array
    {
        $row = array_fill_keys(V8Schema::COLS, null);
        $row['cpf'] = $cpf;
        $row['nome'] = $nome;
        $row['data_nascimento'] = $nasc;
        return $row;
    }

    private function appendErrorRow(V8ConsultJob $job, ?string $cpf, ?string $nome, ?string $nasc, string $error): void
    {
        $row = $this->baseRow($cpf ?? '', $nome, $nasc);
        $row['status'] = 'ERROR';
        $row['error'] = $error;
        $this->accFail++;
        $this->spoolAppendManyPersist($job, [$row]);
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

    private function buildUniqueEntriesFile(string $inputsReal, string $uniqRel, V8ConsultJob $job): int
    {
        $disk = Storage::disk($this->disk);
        $uniqReal = $this->isAbsolutePath($uniqRel) ? $uniqRel : $disk->path($uniqRel);

        if (!$disk->exists($this->dirSpool)) {
            $disk->makeDirectory($this->dirSpool);
        }

        $blockSize = 5000;
        $chunks = [];

        $r = fopen($inputsReal, 'r');
        if ($r === false) {
            $this->logCpfFailure('inputs', null, null, 'Falha ao abrir arquivo de inputs.', [
                'inputs_real' => $inputsReal,
                'error' => error_get_last()['message'] ?? null,
            ]);
            return 0;
        }

        try {
            $block = [];
            while (($line = fgets($r)) !== false) {
                if ($this->finishIfStopped($job)) {
                    return 0;
                }

                $parsed = $this->parseRawLine($line);
                if ($parsed['error']) {
                    $this->appendErrorRow($job, $parsed['cpf'] ?? '', $parsed['nome'] ?? null, $parsed['nasc'] ?? null, $parsed['error']);
                    $this->logCpfFailure('parse', $parsed['cpf'] ?? '', null, $parsed['error'], [
                        'raw' => $this->truncate($line),
                    ]);
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
            return 0;
        }

        if (count($chunks) === 1) {
            @rename($chunks[0], $uniqReal);
            return $this->countLines($uniqReal);
        }

        $w = fopen($uniqReal, 'w');
        if ($w === false) {
            foreach ($chunks as $c) {
                @unlink($c);
            }
            return 0;
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

        return $written;
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
        $cpf = preg_replace('/\D+/', '', (string) $cpfRaw);
        if ($cpf === '' || strlen($cpf) !== 11) {
            return ['error' => 'CPF inválido.', 'cpf' => $cpf];
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

        $nome = trim(implode(' ', array_slice($parts, 1, $dateIdx - 1)));
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
        if (flock($this->spoolFp, LOCK_EX)) {
            foreach ($rows as $row) {
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
        if ($this->accFail > 0) {
            $updates['fail_count'] = DB::raw('COALESCE(fail_count,0) + ' . $this->accFail);
        }

        DB::table('v8_consult_jobs')->where('id', $job->id)->update($updates);

        $job->spool_bytes = $bytes;
        $this->lastFlushAt = $now;
        $this->accSuccess = 0;
        $this->accFail = 0;
    }

    private function finishIfStopped(V8ConsultJob $job): bool
    {
        if ($this->isCancelled($job)) {
            $this->cleanupSpool($job);
            return true;
        }
        return false;
    }

    private function isCancelled(V8ConsultJob $job): bool
    {
        $status = DB::table('v8_consult_jobs')->where('id', $job->id)->value('status');
        return $status === 'cancelado';
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
        } finally {
            $job->updateQuietly(['spool_path' => null, 'spool_inputs_path' => null, 'spool_bytes' => 0]);
        }
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
