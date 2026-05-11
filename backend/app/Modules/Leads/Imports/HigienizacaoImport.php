<?php

namespace App\Modules\Leads\Imports;

use App\Modules\Leads\Imports\Concerns\ImportLifecycleSupport;
use App\Models\Lead;
use App\Models\ImportJob;
use App\Services\BackupService;
use App\Support\Cpf;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\RemembersRowNumber;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterChunk;
use Maatwebsite\Excel\Events\AfterImport;
use Maatwebsite\Excel\Events\BeforeImport;
use Illuminate\Support\Facades\DB;

class HigienizacaoImport implements ToModel, WithHeadingRow, WithChunkReading, WithEvents, ShouldQueue
{
    use RemembersRowNumber;
    use ImportLifecycleSupport;

    public const REQUIRED_HEADERS = [
        'cpfcliente',
        'consulta',
        'dataatualizacao',
        'saldo',
        'libera',
    ];

    protected ImportJob $importJob;
    protected BackupService $backup;

    protected int $rowsInCurrentChunk = 0;
    protected array $rowBuffer = [];
    protected array $pendingLeadUpdates = [];
    protected array $pendingLeadImports = [];
    protected array $pendingErrors = [];
    protected array $backedUpLeadIds = [];
    protected int $errorCount = 0;
    protected int $maxErrorsPerJob = 0;

    public function __construct(ImportJob $importJob, BackupService $backup)
    {
        $this->importJob = $importJob;
        $this->backup    = $backup;
    }

    public function model(array $row)
    {
        if ($this->shouldStopImport()) {
            return null;
        }

        // contador real por CPF com dígitos
        $cpfRaw = $row['cpfcliente'] ?? null;
        $digits = $cpfRaw !== null ? preg_replace('/\D+/', '', (string)$cpfRaw) : '';
        if ($digits === '') {
            return null;
        }
        $this->rowsInCurrentChunk++;

        $this->rowBuffer[] = [
            'row'        => $row,
            'row_number' => $this->getRowNumber(),
        ];

        if (count($this->rowBuffer) >= $this->dbBatchSize()) {
            $this->flushRowBuffer();
        }

        return null;
    }

    public static function missingRequiredHeaders(array $headers): array
    {
        $index = self::buildHeaderIndex($headers);
        $missing = [];
        foreach (self::REQUIRED_HEADERS as $header) {
            if (!isset($index[$header])) {
                $missing[] = $header;
            }
        }
        return $missing;
    }

    public function process(string $fullPath): void
    {
        $this->bootImportLifecycleState();
        $this->rowBuffer = [];
        $this->pendingLeadUpdates = [];
        $this->pendingLeadImports = [];
        $this->pendingErrors = [];
        $this->backedUpLeadIds = [];

        $handle = fopen($fullPath, 'rb');
        if (!$handle) {
            throw new \RuntimeException("Não foi possível abrir o CSV de higienização: {$fullPath}");
        }

        try {
            $headers = fgetcsv($handle, 0, $this->csvDelimiter(), $this->csvEnclosure());
            if ($headers === false) {
                throw new \RuntimeException('CSV de higienização vazio ou sem cabeçalho.');
            }

            $headerIndex = self::buildHeaderIndex($headers);
            $missing = self::missingRequiredHeaders($headers);
            if (!empty($missing)) {
                throw new \RuntimeException('Cabeçalhos ausentes no CSV de higienização: ' . implode(', ', $missing));
            }

            $lineNumber = 1;
            while (($csvRow = fgetcsv($handle, 0, $this->csvDelimiter(), $this->csvEnclosure())) !== false) {
                if ($this->shouldStopImport()) {
                    $this->flushRowBuffer();
                    $this->flushQueuedErrors();
                    $this->finalizeImportJobAsCancelled();
                    return;
                }

                $lineNumber++;
                if ($this->isCsvRowEmpty($csvRow)) {
                    continue;
                }

                $row = $this->parseCsvRow($headerIndex, $csvRow);
                $cpfRaw = $row['cpfcliente'] ?? null;
                $digits = $cpfRaw !== null ? preg_replace('/\D+/', '', (string) $cpfRaw) : '';
                if ($digits === '') {
                    continue;
                }

                $this->rowsInCurrentChunk++;
                $this->rowBuffer[] = [
                    'row' => $row,
                    'row_number' => $lineNumber,
                ];

                if (count($this->rowBuffer) >= $this->dbBatchSize()) {
                    $this->flushRowBuffer();
                }

                if ($this->rowsInCurrentChunk >= $this->chunkSize()) {
                    $this->flushRowBuffer();
                    $this->updateProcessedRowsAfterChunk();
                }
            }

            $this->flushRowBuffer();
            $this->flushQueuedErrors();
            if ($this->refreshImportCancelledFlag(true)) {
                $this->finalizeImportJobAsCancelled();
                return;
            }
            $this->finalizeImportJobAsCompleted();
        } finally {
            fclose($handle);
        }
    }

    private function flushRowBuffer(): void
    {
        if (empty($this->rowBuffer)) {
            return;
        }

        $buffer = $this->rowBuffer;
        $this->rowBuffer = [];

        $cpfs = [];
        foreach ($buffer as $item) {
            $cpf = Cpf::normalize($item['row']['cpfcliente'] ?? null);
            if ($cpf !== null) {
                $cpfs[$cpf] = true;
            }
        }

        /** @var Collection<string, Lead> $leadsByCpf */
        $leadsByCpf = empty($cpfs)
            ? collect()
            : Lead::query()->whereIn('cpf', array_keys($cpfs))->get()->keyBy('cpf');

        foreach ($buffer as $item) {
            $row = $item['row'];
            $rowNumber = (int) $item['row_number'];

            try {
                $this->processBufferedRow($row, $leadsByCpf);
            } catch (\Throwable $e) {
                $column = $e->getPrevious() ? $e->getPrevious()->getMessage() : 'Geral';
                $this->queueImportError($rowNumber, $column, $e->getMessage());
            }
        }

        $this->flushLeadUpdates();
        $this->flushQueuedLeadImports();
        $this->flushQueuedErrors();
    }

    private function processBufferedRow(array $row, Collection $leadsByCpf): void
    {
        foreach (['cpfcliente', 'consulta', 'dataatualizacao', 'saldo', 'libera'] as $required) {
            $value = $row[$required] ?? null;
            if ($value === null || trim((string) $value) === '') {
                throw new \Exception("Campo obrigatório ausente.", 0, new \Exception($required));
            }
        }

        $cpf = Cpf::normalize($row['cpfcliente'] ?? null);
        if (!$cpf || !Cpf::isValid($cpf)) {
            throw new \Exception("CPF inválido.", 0, new \Exception('cpfcliente'));
        }

        /** @var Lead|null $lead */
        $lead = $leadsByCpf->get($cpf);
        if (!$lead) {
            throw new \Exception("Lead com CPF não encontrado na base de dados.", 0, new \Exception('cpfcliente'));
        }

        if (!isset($this->backedUpLeadIds[$lead->id])) {
            $this->backup->backupExistingLead($lead, $this->importJob);
            $this->backedUpLeadIds[$lead->id] = true;
        }

        $dt = $this->transformDateTime($row['dataatualizacao']);
        if (!$dt) {
            throw new \Exception("Formato de data inválido. Use dd/mm/aaaa hh:mm:ss.", 0, new \Exception('dataatualizacao'));
        }

        $this->pendingLeadUpdates[(int) $lead->id] = [
            'id'               => (int) $lead->id,
            'consulta'         => (string) $row['consulta'],
            'data_atualizacao' => $dt,
            'saldo'            => (string) $row['saldo'],
            'libera'           => (string) $row['libera'],
            'updated_at'       => now(),
        ];

        $this->queueLeadImport((int) $lead->id, 'update');

        if (count($this->pendingLeadUpdates) >= $this->dbBatchSize()) {
            $this->flushLeadUpdates();
        }
    }

    private function flushLeadUpdates(): void
    {
        if (empty($this->pendingLeadUpdates)) {
            return;
        }

        DB::table('leads')->upsert(
            array_values($this->pendingLeadUpdates),
            ['id'],
            ['consulta', 'data_atualizacao', 'saldo', 'libera', 'updated_at']
        );

        $this->pendingLeadUpdates = [];
    }

    private function queueLeadImport(int $leadId, string $action): void
    {
        if (isset($this->pendingLeadImports[$leadId])) {
            return;
        }

        $this->pendingLeadImports[$leadId] = [
            'lead_id'       => $leadId,
            'import_job_id' => $this->importJob->id,
            'action'        => $action,
            'created_at'    => now(),
        ];

        if (count($this->pendingLeadImports) >= $this->dbBatchSize()) {
            $this->flushQueuedLeadImports();
        }
    }

    private function flushQueuedLeadImports(): void
    {
        if (empty($this->pendingLeadImports)) {
            return;
        }

        DB::table('lead_imports')->insertOrIgnore(array_values($this->pendingLeadImports));
        $this->pendingLeadImports = [];
    }

    public function chunkSize(): int
    {
        return max(1, (int) config('leads.import.chunk_size', 1000));
    }

    private function transformDateTime($value): ?string
    {
        if (empty($value)) return null;

        try {
            if (is_numeric($value)) {
                $phpDate = Date::excelToDateTimeObject($value);
                $carbon  = Carbon::instance($phpDate)->setTimezone('America/Sao_Paulo');
            } else {
                $carbon = Carbon::createFromFormat('d/m/Y H:i:s', trim($value), 'America/Sao_Paulo');
            }
            return $carbon->clone()->setTimezone('UTC')->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function () {
                $this->bootImportLifecycleState();
                $this->rowBuffer = [];
                $this->pendingLeadUpdates = [];
                $this->pendingLeadImports = [];
                $this->pendingErrors = [];
                $this->backedUpLeadIds = [];
            },

            AfterChunk::class => function () {
                $this->flushRowBuffer();
                $this->updateProcessedRowsAfterChunk();
                if ($this->refreshImportCancelledFlag(true)) {
                    $this->finalizeImportJobAsCancelled();
                }
            },

            AfterImport::class => function () {
                $this->flushRowBuffer();
                if ($this->refreshImportCancelledFlag(true)) {
                    $this->finalizeImportJobAsCancelled();
                    return;
                }
                $this->finalizeImportJobAsCompleted();
            },
        ];
    }

    private static function buildHeaderIndex(array $headers): array
    {
        $aliases = [
            'cpf_cliente' => 'cpfcliente',
            'data_atualizacao' => 'dataatualizacao',
        ];

        $index = [];
        foreach ($headers as $i => $header) {
            $normalized = self::normalizeHeaderLabel((string) $header);
            $canonical = $aliases[$normalized] ?? $normalized;
            if (in_array($canonical, self::REQUIRED_HEADERS, true) && !isset($index[$canonical])) {
                $index[$canonical] = (int) $i;
            }
        }
        return $index;
    }

    private static function normalizeHeaderLabel(string $value): string
    {
        $value = self::normalizeCsvValue($value);
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
        $value = str_replace("\u{00A0}", ' ', $value);
        $normalized = \Illuminate\Support\Str::of($value)->ascii()->lower()->value();
        $normalized = preg_replace('/[^a-z0-9]+/u', '_', $normalized) ?? '';
        return trim((string) preg_replace('/_+/u', '_', $normalized), '_');
    }

    private function parseCsvRow(array $headerIndex, array $csvRow): array
    {
        $row = [];
        foreach ($headerIndex as $header => $index) {
            $row[$header] = isset($csvRow[$index]) ? self::normalizeCsvValue((string) $csvRow[$index]) : null;
        }
        return $row;
    }

    private static function normalizeCsvValue(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
        return str_replace("\u{00A0}", ' ', $value);
    }

    private function isCsvRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function csvDelimiter(): string
    {
        $configured = (string) config('leads.import.csv.delimiter', ';');
        return $configured !== '' ? $configured[0] : ';';
    }

    private function csvEnclosure(): string
    {
        $configured = (string) config('leads.import.csv.enclosure', '"');
        return $configured !== '' ? $configured[0] : '"';
    }
}
