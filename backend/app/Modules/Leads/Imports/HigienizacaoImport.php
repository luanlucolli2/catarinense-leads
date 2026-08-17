<?php

namespace App\Modules\Leads\Imports;

use App\Models\ImportJob;
use App\Modules\Leads\Imports\Concerns\ImportLifecycleSupport;
use App\Modules\Leads\Imports\Exceptions\ImportHeaderException;
use App\Support\Cpf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HigienizacaoImport
{
    use ImportLifecycleSupport;

    public const REQUIRED_HEADERS = ['cpfcliente', 'consulta', 'dataatualizacao', 'saldo', 'libera'];
    private const BACKUP_FIELDS = ['cpf', 'nome', 'data_nascimento', 'fone1', 'classe_fone1', 'fone2', 'classe_fone2', 'fone3', 'classe_fone3', 'fone4', 'classe_fone4', 'consulta', 'data_atualizacao', 'saldo', 'libera'];

    public function __construct(private readonly ImportJob $importJob) {}

    public static function missingRequiredHeaders(array $headers): array
    {
        $index = self::buildHeaderIndex($headers);
        return array_values(array_filter(self::REQUIRED_HEADERS, fn (string $header) => !isset($index[$header])));
    }

    public function process(string $path): void
    {
        $this->bootImportLifecycleState();
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new \RuntimeException('Não foi possível abrir o CSV de higienização.');

        try {
            $headers = fgetcsv($handle, 0, $this->csvDelimiter(), $this->csvEnclosure());
            if ($headers === false) throw new ImportHeaderException(self::REQUIRED_HEADERS);
            $headerIndex = self::buildHeaderIndex($headers);
            $missing = self::missingRequiredHeaders($headers);
            if ($missing !== []) throw new ImportHeaderException($missing);

            $batch = [];
            $line = 1;
            while (($csvRow = fgetcsv($handle, 0, $this->csvDelimiter(), $this->csvEnclosure())) !== false) {
                $line++;
                if ($this->isCsvRowEmpty($csvRow)) continue;
                $batch[] = ['row_number' => $line, 'row' => $this->parseRow($headerIndex, $csvRow)];
                if (count($batch) >= $this->batchSize()) {
                    $this->flushBatch($batch);
                    $this->advanceProgress(count($batch));
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->flushBatch($batch);
                $this->advanceProgress(count($batch));
            }
            $this->completeImport();
        } finally {
            fclose($handle);
        }
    }

    /** @param array<int, array{row_number:int,row:array<string,string|null>}> $batch */
    private function flushBatch(array $batch): void
    {
        $this->assertNotCancellationRequested();
        $errors = [];
        $prepared = [];
        foreach ($batch as $item) {
            try {
                $row = $this->prepareRow($item['row']);
                if ($row !== null) $prepared[] = ['row_number' => $item['row_number'], 'row' => $row];
            } catch (\Throwable $e) {
                $errors[] = ['row_number' => $item['row_number'], 'column_name' => $e->getPrevious()?->getMessage() ?: 'Geral', 'error_message' => $e->getMessage()];
            }
        }
        if ($prepared === []) {
            DB::transaction(fn () => $this->insertErrors($errors));
            return;
        }

        DB::transaction(function () use ($prepared, $errors): void {
            $cpfs = array_values(array_unique(array_column(array_column($prepared, 'row'), 'cpf')));
            $leads = DB::table('leads')->whereIn('cpf', $cpfs)->get()->keyBy('cpf');
            $updates = [];
            $backups = [];
            $links = [];
            $alreadyBacked = DB::table('lead_backups')->where('import_job_id', $this->importJob->id)->whereIn('lead_id', $leads->pluck('id')->all())->pluck('lead_id')->flip();
            $now = now();

            foreach ($prepared as $item) {
                $row = $item['row'];
                $lead = $leads->get($row['cpf']);
                if ($lead === null) {
                    $errors[] = ['row_number' => $item['row_number'], 'column_name' => 'cpfcliente', 'error_message' => 'Lead com CPF não encontrado na base de dados.'];
                    continue;
                }
                if (!isset($alreadyBacked[$lead->id])) {
                    $backups[] = ['import_job_id' => $this->importJob->id, 'lead_id' => $lead->id, 'was_new' => false, ...array_intersect_key((array) $lead, array_flip(self::BACKUP_FIELDS)), 'created_at' => $now, 'updated_at' => $now];
                    $alreadyBacked[$lead->id] = true;
                }
                $updates[$lead->id] = ['id' => $lead->id, 'consulta' => $row['consulta'], 'data_atualizacao' => $row['data_atualizacao'], 'saldo' => $row['saldo'], 'libera' => $row['libera'], 'updated_at' => $now];
                $links[$lead->id] = ['lead_id' => $lead->id, 'import_job_id' => $this->importJob->id, 'action' => 'update', 'created_at' => $now];
            }

            if ($backups !== []) DB::table('lead_backups')->insert($backups);
            if ($updates !== []) DB::table('leads')->upsert(array_values($updates), ['id'], ['consulta', 'data_atualizacao', 'saldo', 'libera', 'updated_at']);
            if ($links !== []) DB::table('lead_imports')->insertOrIgnore(array_values($links));
            $this->insertErrors($errors);
        });
    }

    /** @return array{cpf:string,consulta:string,data_atualizacao:string,saldo:string,libera:string}|null */
    private function prepareRow(array $row): ?array
    {
        $cpf = Cpf::normalize($row['cpfcliente'] ?? null);
        if ($cpf === null) return null;
        foreach (self::REQUIRED_HEADERS as $field) {
            if (($row[$field] ?? null) === null || trim((string) $row[$field]) === '') $this->rowError('Campo obrigatório ausente.', $field);
        }
        if (!Cpf::isValid($cpf)) $this->rowError('CPF inválido.', 'cpfcliente');
        $date = $this->dateTime($row['dataatualizacao']);
        if ($date === null) $this->rowError('Formato de data inválido. Use dd/mm/aaaa hh:mm:ss.', 'dataatualizacao');
        return ['cpf' => $cpf, 'consulta' => (string) $row['consulta'], 'data_atualizacao' => $date, 'saldo' => (string) $row['saldo'], 'libera' => (string) $row['libera']];
    }

    private function dateTime(string $value): ?string
    {
        try {
            return Carbon::createFromFormat('d/m/Y H:i:s', trim($value), 'America/Sao_Paulo')->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function rowError(string $message, string $column): never { throw new \RuntimeException($message, 0, new \RuntimeException($column)); }

    /** @param array<int,string> $headers @return array<string,int> */
    private static function buildHeaderIndex(array $headers): array
    {
        $aliases = ['cpf_cliente' => 'cpfcliente', 'data_atualizacao' => 'dataatualizacao'];
        $index = [];
        foreach ($headers as $position => $header) {
            $name = self::normalizeHeaderLabel((string) $header);
            $name = $aliases[$name] ?? $name;
            if (in_array($name, self::REQUIRED_HEADERS, true) && !isset($index[$name])) $index[$name] = $position;
        }
        return $index;
    }

    /** @param array<string,int> $headerIndex @param array<int,string> $csvRow @return array<string,string|null> */
    private function parseRow(array $headerIndex, array $csvRow): array
    {
        $row = [];
        foreach ($headerIndex as $header => $position) $row[$header] = isset($csvRow[$position]) ? self::normalizeCsvValue((string) $csvRow[$position]) : null;
        return $row;
    }
}
