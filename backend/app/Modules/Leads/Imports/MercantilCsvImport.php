<?php

namespace App\Modules\Leads\Imports;

use App\Models\ImportJob;
use App\Support\Cpf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MercantilCsvImport
{
    public const FIELD_LABELS = [
        'cpf' => 'cpf',
        'nome' => 'nome',
        'data_nascimento' => 'data_nascimento',
        'status' => 'status',
        'mensagem_erro' => 'mensagem_erro',
        'data_hora' => 'data_hora',
        'valor_financiado' => 'Valor financiado',
        'valor_iof' => 'Valor IOF',
        'data_primeiro_vencimento' => 'Data 1º vencimento',
        'valor_emprestimo' => 'Valor empréstimo',
        'quantidade_parcelas' => 'Quantidade de parcelas',
        'valor_liberado' => 'Valor liberado',
        'taxa_juros_mes' => 'Taxa juros (mês)',
        'valor_parcela' => 'Valor da parcela',
    ];

    public const REQUIRED_FIELDS = [
        'cpf',
        'nome',
        'data_nascimento',
        'status',
        'mensagem_erro',
        'data_hora',
        'valor_financiado',
        'valor_iof',
        'data_primeiro_vencimento',
        'valor_emprestimo',
        'quantidade_parcelas',
        'valor_liberado',
        'taxa_juros_mes',
        'valor_parcela',
    ];

    public const HEADER_ALIASES = [
        'cpf' => ['cpf', 'c_p_f'],
        'nome' => ['nome'],
        'data_nascimento' => ['data_nascimento', 'dt_nascimento', 'data_de_nascimento'],
        'status' => ['status'],
        'mensagem_erro' => ['mensagem_erro', 'mensagem_de_erro'],
        'data_hora' => ['data_hora', 'datahora'],
        'valor_financiado' => ['valor_financiado'],
        'valor_iof' => ['valor_iof'],
        'data_primeiro_vencimento' => ['data_1_vencimento', 'data_1o_vencimento', 'data_primeiro_vencimento'],
        'valor_emprestimo' => ['valor_emprestimo'],
        'quantidade_parcelas' => ['quantidade_de_parcelas', 'quantidade_parcelas'],
        'valor_liberado' => ['valor_liberado'],
        'taxa_juros_mes' => ['taxa_juros_mes'],
        'valor_parcela' => ['valor_da_parcela', 'valor_parcela'],
    ];

    protected ImportJob $importJob;
    protected int $lineChunkSize;
    protected int $dbBatchSize;
    protected int $maxErrorsPerJob;

    /** @var array<string, bool> */
    protected array $ignoredStatuses = [];

    /** @var array<string, array<string, mixed>> */
    protected array $pendingSnapshots = [];

    /** @var array<int, array<string, mixed>> */
    protected array $pendingErrors = [];

    protected int $rowsInCurrentChunk = 0;
    protected int $errorCount = 0;
    protected bool $cancelled = false;
    protected int $cancelCheckCounter = 0;

    public function __construct(ImportJob $importJob)
    {
        $this->importJob = $importJob;
        $this->lineChunkSize = max(100, (int) config('leads.import.mercantil.chunk_size', 500));
        $this->dbBatchSize = max(50, (int) config('leads.import.db_batch_size', 500));
        $this->maxErrorsPerJob = max(1, (int) config('leads.import.max_errors_per_job', 5000));
        $this->cancelled = false;
        $this->cancelCheckCounter = 0;

        $ignored = array_values(array_filter((array) config('leads.import.mercantil.ignored_statuses', [])));
        foreach ($ignored as $status) {
            $this->ignoredStatuses[mb_strtoupper(trim((string) $status))] = true;
        }
    }

    /**
     * @return array<int, string>
     */
    public static function requiredFieldLabels(): array
    {
        return array_values(array_map(
            fn($field) => self::FIELD_LABELS[$field] ?? $field,
            self::REQUIRED_FIELDS
        ));
    }

    /**
     * @param array<int, string> $headers
     * @return array<int, string>
     */
    public static function missingRequiredFields(array $headers): array
    {
        $headerIndex = self::buildHeaderIndex($headers);
        return self::missingFromHeaderIndex($headerIndex);
    }

    public function process(string $fullPath): void
    {
        $this->errorCount = (int) DB::table('import_errors')
            ->where('import_job_id', $this->importJob->id)
            ->count();

        $handle = fopen($fullPath, 'rb');
        if (!$handle) {
            throw new \RuntimeException("Não foi possível abrir o CSV Mercantil: {$fullPath}");
        }

        try {
            $delimiter = $this->delimiter();
            $enclosure = $this->enclosure();

            $headers = fgetcsv($handle, 0, $delimiter, $enclosure);
            if ($headers === false) {
                throw new \RuntimeException('CSV Mercantil vazio ou sem cabeçalho.');
            }

            $headerIndex = self::buildHeaderIndex($headers);
            $missing = self::missingFromHeaderIndex($headerIndex);
            if (!empty($missing)) {
                throw new \RuntimeException('Cabeçalhos ausentes no CSV Mercantil: ' . implode(', ', $missing));
            }

            $lineNumber = 1;
            while (($row = fgetcsv($handle, 0, $delimiter, $enclosure)) !== false) {
                if ($this->shouldStopImport()) {
                    $this->flushPendingState();
                    $this->flushQueuedErrors();
                    $this->markImportAsCancelled();
                    return;
                }

                $lineNumber++;

                if ($this->isCsvRowEmpty($row)) {
                    continue;
                }

                $this->rowsInCurrentChunk++;

                $parsed = $this->parseRow($headerIndex, $row, $lineNumber);
                if ($parsed === null) {
                    $this->flushIfNeeded();
                    continue;
                }

                $status = (string) ($parsed['status'] ?? '');

                $cpf = (string) $parsed['cpf'];
                $current = $this->pendingSnapshots[$cpf] ?? null;

                if (
                    $current === null
                    || $this->shouldReplaceRecord(
                        (string) $parsed['data_hora_origem'],
                        $status,
                        (string) ($current['data_hora_origem'] ?? ''),
                        (string) ($current['status'] ?? '')
                    )
                ) {
                    $this->pendingSnapshots[$cpf] = $parsed;
                }

                $this->flushIfNeeded();
            }

            $this->flushPendingState();
            $this->flushQueuedErrors();
            $this->markImportAsCompleted();
        } finally {
            fclose($handle);
        }
    }

    protected function delimiter(): string
    {
        $configured = (string) config('leads.import.mercantil.csv.delimiter', ';');
        return $configured !== '' ? $configured[0] : ';';
    }

    protected function enclosure(): string
    {
        $configured = (string) config('leads.import.mercantil.csv.enclosure', '"');
        return $configured !== '' ? $configured[0] : '"';
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, int>
     */
    public static function buildHeaderIndex(array $headers): array
    {
        $aliasMap = [];
        foreach (self::HEADER_ALIASES as $canonical => $aliases) {
            $aliasMap[self::normalizeHeaderLabel($canonical)] = $canonical;
            foreach ($aliases as $alias) {
                $aliasMap[self::normalizeHeaderLabel($alias)] = $canonical;
            }
        }

        $index = [];
        foreach ($headers as $i => $rawHeader) {
            $normalized = self::normalizeHeaderLabel((string) $rawHeader);
            if ($normalized === '') {
                continue;
            }

            $canonical = $aliasMap[$normalized] ?? null;
            if ($canonical === null) {
                continue;
            }

            if (!isset($index[$canonical])) {
                $index[$canonical] = (int) $i;
            }
        }

        return $index;
    }

    public static function normalizeHeaderLabel(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
        $value = str_replace("\u{00A0}", ' ', $value);

        $normalized = Str::of($value)->ascii()->lower()->value();
        $normalized = preg_replace('/[^a-z0-9]+/u', '_', $normalized) ?? '';
        $normalized = trim((string) preg_replace('/_+/u', '_', $normalized), '_');
        return $normalized;
    }

    /**
     * @param array<string, int> $headerIndex
     * @return array<int, string>
     */
    protected static function missingFromHeaderIndex(array $headerIndex): array
    {
        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $headerIndex)) {
                $missing[] = self::FIELD_LABELS[$field] ?? $field;
            }
        }

        return $missing;
    }

    /**
     * @param array<int, string> $row
     */
    protected function isCsvRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function flushIfNeeded(): void
    {
        if ($this->rowsInCurrentChunk >= $this->lineChunkSize) {
            $this->flushPendingState();
        }
    }

    protected function flushPendingState(): void
    {
        if (!empty($this->pendingSnapshots)) {
            $this->upsertPendingSnapshots();
        }

        if ($this->rowsInCurrentChunk > 0) {
            DB::table('import_jobs')
                ->where('id', $this->importJob->id)
                ->update([
                    'processed_rows' => DB::raw(
                        'CASE
                            WHEN total_rows > 0
                                THEN LEAST(processed_rows + ' . (int) $this->rowsInCurrentChunk . ', total_rows)
                            ELSE processed_rows + ' . (int) $this->rowsInCurrentChunk . '
                         END'
                    ),
                ]);

            $this->rowsInCurrentChunk = 0;
        }

        if (count($this->pendingErrors) >= $this->dbBatchSize) {
            $this->flushQueuedErrors();
        }
    }

    protected function upsertPendingSnapshots(): void
    {
        $cpfs = array_keys($this->pendingSnapshots);
        $this->upsertLeadsForPendingSnapshots($cpfs);

        $existing = DB::table('mercantil_snapshots')
            ->whereIn('cpf', $cpfs)
            ->get(['cpf', 'status', 'data_hora_origem'])
            ->keyBy('cpf');

        $now = now();
        $payload = [];

        foreach ($this->pendingSnapshots as $cpf => $incoming) {
            $current = $existing->get($cpf);

            if ($current !== null) {
                $currentDate = $this->toMysqlDateTime($current->data_hora_origem);
                $currentStatus = mb_strtoupper(trim((string) $current->status));

                if (
                    !$this->shouldReplaceRecord(
                        (string) $incoming['data_hora_origem'],
                        (string) $incoming['status'],
                        (string) $currentDate,
                        $currentStatus
                    )
                ) {
                    continue;
                }
            }

            $row = $incoming;
            $row['job_id'] = $this->importJob->id;
            $row['updated_at'] = $now;
            $payload[] = $row;
        }

        if (!empty($payload)) {
            $updateColumns = [
                'nome',
                'data_nascimento',
                'status',
                'mensagem_erro',
                'data_hora_origem',
                'valor_financiado',
                'valor_iof',
                'data_primeiro_vencimento',
                'valor_emprestimo',
                'quantidade_parcelas',
                'valor_liberado',
                'taxa_juros_mes',
                'valor_parcela',
                'job_id',
                'updated_at',
            ];

            foreach (array_chunk($payload, $this->dbBatchSize) as $chunk) {
                DB::table('mercantil_snapshots')->upsert($chunk, ['cpf'], $updateColumns);
            }
        }

        $this->pendingSnapshots = [];
    }

    /**
     * @param array<int, string> $cpfs
     */
    protected function upsertLeadsForPendingSnapshots(array $cpfs): void
    {
        if (empty($cpfs)) {
            return;
        }

        $existingLeads = DB::table('leads')
            ->whereIn('cpf', $cpfs)
            ->get(['cpf', 'nome', 'data_nascimento'])
            ->keyBy('cpf');

        $now = now();
        $payload = [];

        foreach ($this->pendingSnapshots as $cpf => $snapshot) {
            if (isset($payload[$cpf])) {
                continue;
            }

            $existing = $existingLeads->get($cpf);

            $payload[$cpf] = [
                'cpf' => $cpf,
                'nome' => $this->preferIncomingValue(
                    $snapshot['nome'] ?? null,
                    $existing?->nome
                ),
                'data_nascimento' => $this->preferIncomingValue(
                    $snapshot['data_nascimento'] ?? null,
                    $existing?->data_nascimento
                ),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($payload)) {
            return;
        }

        foreach (array_chunk(array_values($payload), $this->dbBatchSize) as $chunk) {
            DB::table('leads')->upsert($chunk, ['cpf'], [
                'nome',
                'data_nascimento',
                'updated_at',
            ]);
        }
    }

    protected function preferIncomingValue(mixed $incoming, mixed $current): mixed
    {
        return $incoming !== null ? $incoming : $current;
    }

    protected function shouldReplaceRecord(
        string $incomingDate,
        string $incomingStatus,
        string $currentDate,
        string $currentStatus
    ): bool {
        if ($currentDate === '') {
            return $incomingDate !== '';
        }

        if ($incomingDate === '') {
            return false;
        }

        if ($incomingDate > $currentDate) {
            return true;
        }

        if ($incomingDate < $currentDate) {
            return false;
        }

        return $incomingStatus === 'SUCESSO' && $currentStatus !== 'SUCESSO';
    }

    protected function markImportAsCompleted(): void
    {
        $snapshot = DB::table('import_jobs')
            ->where('id', $this->importJob->id)
            ->first(['processed_rows', 'total_rows']);

        $processed = (int) ($snapshot->processed_rows ?? 0);
        $total = max((int) ($snapshot->total_rows ?? 0), $processed);

        $this->importJob->update([
            'total_rows' => $total,
            'processed_rows' => $total,
            'status' => 'concluido',
            'finished_at' => now(),
        ]);
    }

    protected function markImportAsCancelled(): void
    {
        DB::table('import_jobs')
            ->where('id', $this->importJob->id)
            ->where('status', 'cancelado')
            ->update([
                'finished_at' => DB::raw('COALESCE(finished_at, NOW())'),
            ]);
    }

    protected function shouldStopImport(): bool
    {
        if ($this->cancelled) {
            return true;
        }

        $this->cancelCheckCounter++;
        if (($this->cancelCheckCounter % 50) !== 0) {
            return false;
        }

        $status = DB::table('import_jobs')
            ->where('id', $this->importJob->id)
            ->value('status');

        $this->cancelled = ($status === 'cancelado');
        return $this->cancelled;
    }

    /**
     * @param array<string, int> $headerIndex
     * @param array<int, string> $row
     * @return array<string, mixed>|null
     */
    protected function parseRow(array $headerIndex, array $row, int $lineNumber): ?array
    {
        $cpf = Cpf::normalize($this->fieldValue($headerIndex, $row, 'cpf'));
        if ($cpf === null) {
            $this->queueImportError($lineNumber, 'cpf', 'CPF inválido ou ausente.');
            return null;
        }

        $nome = $this->nullableString($this->fieldValue($headerIndex, $row, 'nome'));
        if ($nome === null) {
            $this->queueImportError($lineNumber, 'nome', 'Nome ausente.');
            return null;
        }

        $dataNascimento = $this->parseDate($this->fieldValue($headerIndex, $row, 'data_nascimento'));
        if ($dataNascimento === null) {
            $this->queueImportError($lineNumber, 'data_nascimento', 'Data de nascimento inválida ou ausente.');
            return null;
        }

        $status = mb_strtoupper(trim((string) $this->fieldValue($headerIndex, $row, 'status')));
        if ($status === '') {
            $this->queueImportError($lineNumber, 'status', 'Status ausente.');
            return null;
        }

        if (isset($this->ignoredStatuses[$status])) {
            return null;
        }

        $dataHora = $this->parseDateTime($this->fieldValue($headerIndex, $row, 'data_hora'));
        if ($dataHora === null) {
            $this->queueImportError($lineNumber, 'data_hora', 'Data/hora inválida ou ausente.');
            return null;
        }

        return [
            'cpf' => $cpf,
            'nome' => $nome,
            'data_nascimento' => $dataNascimento,
            'status' => $status,
            'mensagem_erro' => $this->nullableString($this->fieldValue($headerIndex, $row, 'mensagem_erro')),
            'data_hora_origem' => $dataHora,
            'valor_financiado' => $this->parseMoney($this->fieldValue($headerIndex, $row, 'valor_financiado')),
            'valor_iof' => $this->parseMoney($this->fieldValue($headerIndex, $row, 'valor_iof')),
            'data_primeiro_vencimento' => $this->parseDate($this->fieldValue($headerIndex, $row, 'data_primeiro_vencimento')),
            'valor_emprestimo' => $this->parseMoney($this->fieldValue($headerIndex, $row, 'valor_emprestimo')),
            'quantidade_parcelas' => $this->parseUnsignedInt($this->fieldValue($headerIndex, $row, 'quantidade_parcelas')),
            'valor_liberado' => $this->parseMoney($this->fieldValue($headerIndex, $row, 'valor_liberado')),
            'taxa_juros_mes' => $this->parseRate($this->fieldValue($headerIndex, $row, 'taxa_juros_mes')),
            'valor_parcela' => $this->parseMoney($this->fieldValue($headerIndex, $row, 'valor_parcela')),
        ];
    }

    /**
     * @param array<string, int> $headerIndex
     * @param array<int, string> $row
     */
    protected function fieldValue(array $headerIndex, array $row, string $field): ?string
    {
        if (!array_key_exists($field, $headerIndex)) {
            return null;
        }

        $index = $headerIndex[$field];
        if (!array_key_exists($index, $row)) {
            return null;
        }

        return (string) $row[$index];
    }

    protected function parseDateTime(?string $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        foreach (['d/m/Y, H:i:s', 'd/m/Y H:i:s', 'Y-m-d H:i:s'] as $format) {
            try {
                $dt = Carbon::createFromFormat($format, $value);
                if ($dt !== false) {
                    return $dt->format('Y-m-d H:i:s');
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    protected function parseDate(?string $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        foreach (['d/m/Y', 'Y-m-d'] as $format) {
            try {
                $dt = Carbon::createFromFormat($format, $value);
                if ($dt !== false) {
                    return $dt->format('Y-m-d');
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    protected function parseMoney(?string $value): ?float
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        $value = str_replace("\u{00A0}", ' ', $value);
        $value = preg_replace('/[^\d,.\-]+/u', '', $value) ?? '';
        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    protected function parseRate(?string $value): ?float
    {
        $money = $this->parseMoney($value);
        if ($money === null) {
            return null;
        }

        return round($money, 4);
    }

    protected function parseUnsignedInt(?string $value): ?int
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }

        return (int) $digits;
    }

    protected function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace("\u{00A0}", ' ', (string) $value));
        return $value === '' ? null : $value;
    }

    protected function toMysqlDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $string = $this->nullableString((string) $value);
        if ($string === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $string) === 1) {
            return $string;
        }

        try {
            return Carbon::parse($string)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function queueImportError(int $rowNumber, string $columnName, string $message): void
    {
        if ($this->maxErrorsPerJob > 0 && $this->errorCount >= $this->maxErrorsPerJob) {
            return;
        }

        $now = now();
        $this->pendingErrors[] = [
            'import_job_id' => $this->importJob->id,
            'row_number' => $rowNumber,
            'column_name' => $columnName,
            'error_message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->errorCount++;

        if (count($this->pendingErrors) >= $this->dbBatchSize) {
            $this->flushQueuedErrors();
        }
    }

    protected function flushQueuedErrors(): void
    {
        if (empty($this->pendingErrors)) {
            return;
        }

        foreach (array_chunk($this->pendingErrors, $this->dbBatchSize) as $chunk) {
            DB::table('import_errors')->insert($chunk);
        }

        $this->pendingErrors = [];
    }
}
