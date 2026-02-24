<?php

namespace App\Modules\Leads\Imports;

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

    private function dbBatchSize(): int
    {
        return max(50, (int) config('leads.import.db_batch_size', 500));
    }

    private function maxErrorsPerJob(): int
    {
        return max(1, (int) config('leads.import.max_errors_per_job', 5000));
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

    private function queueImportError(int $rowNumber, string $columnName, string $message): void
    {
        if ($this->maxErrorsPerJob > 0 && $this->errorCount >= $this->maxErrorsPerJob) {
            return;
        }

        $now = now();
        $this->pendingErrors[] = [
            'import_job_id' => $this->importJob->id,
            'row_number'    => $rowNumber,
            'column_name'   => $columnName,
            'error_message' => $message,
            'created_at'    => $now,
            'updated_at'    => $now,
        ];
        $this->errorCount++;

        if (count($this->pendingErrors) >= $this->dbBatchSize()) {
            $this->flushQueuedErrors();
        }
    }

    private function flushQueuedErrors(): void
    {
        if (empty($this->pendingErrors)) {
            return;
        }

        foreach (array_chunk($this->pendingErrors, $this->dbBatchSize()) as $chunk) {
            DB::table('import_errors')->insert($chunk);
        }

        $this->pendingErrors = [];
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
                $this->backup->purgeOldBackups();
                $this->rowsInCurrentChunk = 0;
                $this->rowBuffer = [];
                $this->pendingLeadUpdates = [];
                $this->pendingLeadImports = [];
                $this->pendingErrors = [];
                $this->backedUpLeadIds = [];
                $this->maxErrorsPerJob = $this->maxErrorsPerJob();
                $this->errorCount = (int) DB::table('import_errors')
                    ->where('import_job_id', $this->importJob->id)
                    ->count();
            },

            AfterChunk::class => function () {
                $this->flushRowBuffer();

                if ($this->rowsInCurrentChunk <= 0) {
                    return;
                }
                DB::table('import_jobs')
                    ->where('id', $this->importJob->id)
                    ->update([
                        'processed_rows' => DB::raw(
                            'CASE
                                WHEN total_rows > 0
                                    THEN LEAST(processed_rows + ' . (int) $this->rowsInCurrentChunk . ', total_rows)
                                ELSE processed_rows + ' . (int) $this->rowsInCurrentChunk . '
                             END'
                        )
                    ]);
                $this->rowsInCurrentChunk = 0;
            },

            AfterImport::class => function () {
                $this->flushRowBuffer();
                $this->rowsInCurrentChunk = 0;
                $snapshot = DB::table('import_jobs')
                    ->where('id', $this->importJob->id)
                    ->first(['processed_rows', 'total_rows']);

                $processed = (int) ($snapshot->processed_rows ?? 0);
                $total = max((int) ($snapshot->total_rows ?? 0), $processed);

                $this->importJob->update([
                    'total_rows'     => $total,
                    'processed_rows' => $total,
                    'status'         => 'concluido',
                    'finished_at'    => now(),
                ]);
            },
        ];
    }
}
