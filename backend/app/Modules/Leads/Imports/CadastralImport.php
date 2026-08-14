<?php

namespace App\Modules\Leads\Imports;

use App\Models\ImportJob;
use App\Models\Vendor;
use App\Modules\Leads\Imports\Concerns\ImportLifecycleSupport;
use App\Modules\Leads\Imports\Exceptions\ImportHeaderException;
use App\Support\Cpf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CadastralImport
{
    use ImportLifecycleSupport;

    public const REQUIRED_HEADERS = ['cpfcliente', 'nomecliente', 'datanascimento', 'fone1', 'classefone1', 'fone2', 'classefone2', 'fone3', 'classefone3', 'fone4', 'classefone4', 'datacontrato', 'vendedor'];
    private const LEAD_FIELDS = ['cpf', 'nome', 'data_nascimento', 'fone1', 'classe_fone1', 'fone2', 'classe_fone2', 'fone3', 'classe_fone3', 'fone4', 'classe_fone4', 'consulta', 'data_atualizacao', 'saldo', 'libera'];

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
        if ($handle === false) throw new \RuntimeException('Não foi possível abrir o CSV cadastral.');

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

    /** @param array<int, array{row_number:int,row:array<string, string|null>}> $batch */
    private function flushBatch(array $batch): void
    {
        $this->assertNotCancellationRequested();
        $valid = [];
        $errors = [];
        foreach ($batch as $item) {
            try {
                $row = $this->prepareRow($item['row']);
                if ($row !== null) $valid[] = ['row_number' => $item['row_number'], 'row' => $row];
            } catch (\Throwable $e) {
                $errors[] = ['row_number' => $item['row_number'], 'column_name' => $e->getPrevious()?->getMessage() ?: 'Geral', 'error_message' => $e->getMessage()];
            }
        }
        if ($valid === []) {
            DB::transaction(fn () => $this->insertErrors($errors));
            return;
        }

        DB::transaction(function () use ($valid, $errors): void {
            $cpfs = array_values(array_unique(array_column(array_column($valid, 'row'), 'cpf')));
            $existing = DB::table('leads')->whereIn('cpf', $cpfs)->get()->keyBy('cpf');
            $states = [];
            $contracts = [];

            foreach ($valid as $item) {
                $row = $item['row'];
                $cpf = $row['cpf'];
                if (!isset($states[$cpf])) {
                    $current = $existing->get($cpf);
                    $states[$cpf] = [
                        'id' => $current?->id,
                        'is_new' => $current === null,
                        'action' => $current === null ? 'insert' : 'update',
                        'before' => $current ? (array) $current : null,
                        'data' => $current ? $this->leadData((array) $current) : $this->emptyLead($cpf),
                    ];
                }
                $states[$cpf]['data'] = $this->mergeLead($states[$cpf]['data'], $row);
                if ($row['contract_date'] !== null) {
                    $contracts[$cpf . '|' . $row['contract_date']] = ['cpf' => $cpf, 'date' => $row['contract_date'], 'vendor' => $row['vendor_name']];
                }
            }

            $now = now();
            $existingStates = array_filter($states, fn (array $state) => !$state['is_new']);
            $this->backupExisting($existingStates, $now);
            $updates = array_map(fn (array $state) => ['id' => $state['id'], ...$state['data'], 'updated_at' => $now], $existingStates);
            if ($updates !== []) DB::table('leads')->upsert($updates, ['id'], array_merge(array_diff(self::LEAD_FIELDS, ['cpf']), ['updated_at']));

            $newStates = array_filter($states, fn (array $state) => $state['is_new']);
            $inserts = array_map(fn (array $state) => [...$state['data'], 'created_at' => $now, 'updated_at' => $now], $newStates);
            if ($inserts !== []) DB::table('leads')->insertOrIgnore($inserts);

            $leadIds = DB::table('leads')->whereIn('cpf', array_keys($states))->pluck('id', 'cpf')->all();
            $this->backupNew($newStates, $leadIds, $now);
            $this->linkLeads($states, $leadIds, $now);
            $this->upsertContracts($contracts, $leadIds, $now);
            $this->insertErrors($errors);
        });
    }

    /** @return array<string, mixed>|null */
    private function prepareRow(array $row): ?array
    {
        $cpf = Cpf::normalize($row['cpfcliente'] ?? null);
        if ($cpf === null) return null;
        if (!Cpf::isValid($cpf)) $this->rowError('CPF inválido.', 'cpfcliente');

        $contractDate = null;
        $vendor = null;
        if (($row['datacontrato'] ?? '') !== '') {
            $contractDate = $this->date($row['datacontrato']);
            if ($contractDate === null) $this->rowError('Formato de data inválido.', 'datacontrato');
            if (($row['vendedor'] ?? '') !== '') {
                $vendor = $this->name($row['vendedor']);
                if ($vendor === null) $this->rowError('Vendedor inválido.', 'vendedor');
            }
        }

        return [
            'cpf' => $cpf,
            'nome' => $this->name($row['nomecliente'] ?? null),
            'data_nascimento' => $this->date($row['datanascimento'] ?? null),
            'fone1' => $this->phone($row['fone1'] ?? null, 'fone1'),
            'classe_fone1' => $this->normalizeClass($row['classefone1'] ?? null),
            'fone2' => $this->phone($row['fone2'] ?? null, 'fone2'),
            'classe_fone2' => $this->normalizeClass($row['classefone2'] ?? null),
            'fone3' => $this->phone($row['fone3'] ?? null, 'fone3'),
            'classe_fone3' => $this->normalizeClass($row['classefone3'] ?? null),
            'fone4' => $this->phone($row['fone4'] ?? null, 'fone4'),
            'classe_fone4' => $this->normalizeClass($row['classefone4'] ?? null),
            'contract_date' => $contractDate,
            'vendor_name' => $vendor,
        ];
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $incoming */
    private function mergeLead(array $data, array $incoming): array
    {
        foreach (['nome', 'data_nascimento'] as $field) if ($incoming[$field] !== null && $incoming[$field] !== '') $data[$field] = $incoming[$field];

        $current = $incomingSlots = [];
        for ($i = 1; $i <= 4; $i++) {
            $current[] = $this->slot($data["fone{$i}"], $data["classe_fone{$i}"]);
            $incomingSlots[] = $this->slot($incoming["fone{$i}"], $incoming["classe_fone{$i}"]);
        }
        foreach ($incomingSlots as $slot) {
            if ($slot['phone'] === null) continue;
            $same = array_search($slot['phone'], array_column($current, 'phone'), true);
            if ($same !== false) {
                if ($slot['priority'] > $current[$same]['priority']) $current[$same] = $slot;
                continue;
            }
            foreach ($current as $index => $old) {
                if ($old['phone'] === null) {
                    $current[$index] = $slot;
                    continue 2;
                }
            }
            $minimum = min(array_column($current, 'priority'));
            if ($slot['priority'] > $minimum) foreach ($current as $index => $old) if ($old['priority'] === $minimum) {
                $current[$index] = $slot;
                break;
            }
        }
        foreach ($current as $index => $slot) {
            $number = $index + 1;
            $data["fone{$number}"] = $slot['phone'];
            $data["classe_fone{$number}"] = $slot['class'];
        }
        return $data;
    }

    /** @param array<string, array<string,mixed>> $states */
    private function backupExisting(array $states, mixed $now): void
    {
        $ids = array_values(array_filter(array_column($states, 'id')));
        if ($ids === []) return;
        $already = DB::table('lead_backups')->where('import_job_id', $this->importJob->id)->whereIn('lead_id', $ids)->pluck('lead_id')->flip();
        $rows = [];
        foreach ($states as $state) {
            if (isset($already[$state['id']])) continue;
            $rows[] = ['import_job_id' => $this->importJob->id, 'lead_id' => $state['id'], 'was_new' => false, ...array_intersect_key($state['before'], array_flip(self::LEAD_FIELDS)), 'created_at' => $now, 'updated_at' => $now];
        }
        if ($rows !== []) DB::table('lead_backups')->insert($rows);
    }

    /** @param array<string, array<string,mixed>> $states @param array<string,int> $leadIds */
    private function backupNew(array $states, array $leadIds, mixed $now): void
    {
        $rows = [];
        foreach ($states as $cpf => $state) if (isset($leadIds[$cpf])) {
            $rows[] = ['import_job_id' => $this->importJob->id, 'lead_id' => $leadIds[$cpf], 'was_new' => true, 'cpf' => $cpf, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($rows !== []) DB::table('lead_backups')->insert($rows);
    }

    /** @param array<string, array<string,mixed>> $states @param array<string,int> $leadIds */
    private function linkLeads(array $states, array $leadIds, mixed $now): void
    {
        $rows = [];
        foreach ($states as $cpf => $state) if (isset($leadIds[$cpf])) $rows[] = ['lead_id' => $leadIds[$cpf], 'import_job_id' => $this->importJob->id, 'action' => $state['action'], 'created_at' => $now];
        if ($rows !== []) DB::table('lead_imports')->insertOrIgnore($rows);
    }

    /** @param array<string,array{cpf:string,date:string,vendor:?string}> $contracts @param array<string,int> $leadIds */
    private function upsertContracts(array $contracts, array $leadIds, mixed $now): void
    {
        if ($contracts === []) return;
        $names = array_values(array_unique(array_filter(array_column($contracts, 'vendor'))));
        $vendorIds = [];
        if ($names !== []) {
            $keys = array_map(fn (string $name) => Vendor::clean($name), $names);
            $known = DB::table('vendors')->whereIn('name_clean', $keys)->pluck('id', 'name_clean')->all();
            $missing = [];
            foreach ($names as $name) {
                $key = Vendor::clean($name);
                if (!isset($known[$key])) $missing[$key] = ['name' => $name, 'name_clean' => $key, 'created_at' => $now, 'updated_at' => $now];
            }
            if ($missing !== []) DB::table('vendors')->insertOrIgnore(array_values($missing));
            $vendorIds = DB::table('vendors')->whereIn('name_clean', $keys)->pluck('id', 'name_clean')->all();
            $vendorBackups = [];
            foreach ($missing as $key => $vendor) if (isset($vendorIds[$key])) $vendorBackups[] = ['import_job_id' => $this->importJob->id, 'vendor_id' => $vendorIds[$key], 'name' => $vendor['name'], 'name_clean' => $key, 'original_created_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            if ($vendorBackups !== []) DB::table('vendor_backups')->insertOrIgnore($vendorBackups);
        }

        $rows = [];
        foreach ($contracts as $contract) {
            $leadId = $leadIds[$contract['cpf']] ?? null;
            if ($leadId !== null) $rows[] = ['lead_id' => $leadId, 'data_contrato' => $contract['date'], 'vendor_id' => $contract['vendor'] === null ? null : ($vendorIds[Vendor::clean($contract['vendor'])] ?? null), 'created_at' => $now, 'updated_at' => $now];
        }
        if ($rows === []) return;
        $leadIdsForContracts = array_values(array_unique(array_column($rows, 'lead_id')));
        $dates = array_values(array_unique(array_column($rows, 'data_contrato')));
        $before = DB::table('lead_contracts')->whereIn('lead_id', $leadIdsForContracts)->whereIn('data_contrato', $dates)->get()->keyBy(fn ($row) => $row->lead_id . '|' . $row->data_contrato);
        $beforeIds = $before->pluck('id')->all();
        $backed = $beforeIds === [] ? collect() : DB::table('lead_contract_backups')->where('import_job_id', $this->importJob->id)->whereIn('lead_contract_id', $beforeIds)->pluck('lead_contract_id')->flip();
        $updates = [];
        foreach ($before as $contract) if (!isset($backed[$contract->id])) $updates[] = ['import_job_id' => $this->importJob->id, 'lead_id' => $contract->lead_id, 'lead_contract_id' => $contract->id, 'vendor_id' => $contract->vendor_id, 'data_contrato' => $contract->data_contrato, 'action' => 'update', 'created_at' => $now, 'updated_at' => $now];
        if ($updates !== []) DB::table('lead_contract_backups')->insert($updates);
        DB::table('lead_contracts')->upsert($rows, ['lead_id', 'data_contrato'], ['vendor_id', 'updated_at']);
        $after = DB::table('lead_contracts')->whereIn('lead_id', $leadIdsForContracts)->whereIn('data_contrato', $dates)->get()->keyBy(fn ($row) => $row->lead_id . '|' . $row->data_contrato);
        $inserts = [];
        foreach ($rows as $row) {
            $key = $row['lead_id'] . '|' . $row['data_contrato'];
            if (!isset($before[$key]) && isset($after[$key])) {
                $contract = $after[$key];
                $inserts[] = ['import_job_id' => $this->importJob->id, 'lead_id' => $contract->lead_id, 'lead_contract_id' => $contract->id, 'vendor_id' => $contract->vendor_id, 'data_contrato' => $contract->data_contrato, 'action' => 'insert', 'created_at' => $now, 'updated_at' => $now];
            }
        }
        if ($inserts !== []) DB::table('lead_contract_backups')->insert($inserts);
    }

    /** @return array<string,mixed> */
    private function emptyLead(string $cpf): array { return ['cpf' => $cpf, 'nome' => null, 'data_nascimento' => null, 'fone1' => null, 'classe_fone1' => null, 'fone2' => null, 'classe_fone2' => null, 'fone3' => null, 'classe_fone3' => null, 'fone4' => null, 'classe_fone4' => null, 'consulta' => null, 'data_atualizacao' => null, 'saldo' => null, 'libera' => null]; }
    /** @return array<string,mixed> */
    private function leadData(array $record): array { return array_intersect_key($record, array_flip(self::LEAD_FIELDS)); }
    /** @return array{phone:?string,class:?string,priority:int} */
    private function slot(?string $phone, ?string $class): array { $class = $this->normalizeClass($class); return ['phone' => $phone ?: null, 'class' => $class, 'priority' => $class === 'carteira' ? 2 : ($class === 'atendimento ia' ? 1 : 0)]; }
    private function date(mixed $value): ?string { if ($value === null || $value === '') return null; try { return Carbon::createFromFormat('d/m/Y', trim((string) $value))->format('Y-m-d'); } catch (\Throwable) { return null; } }
    private function name(?string $value): ?string { if ($value === null || $value === '') return null; $value = trim(self::normalizeCsvValue($value)); $value = preg_replace('/[^\p{L}\p{N} \'\-]/u', '', $value) ?? ''; $value = preg_replace('/\s+/', ' ', $value) ?? ''; if (mb_strlen($value) < 2 || mb_strlen($value) > 100) $this->rowError('Tamanho de nome deve ser entre 2 e 100 caracteres.', 'nomecliente'); return $value; }
    private function phone(?string $value, string $column): ?string { if ($value === null || $value === '') return null; $digits = preg_replace('/\D/', '', $value); if (strlen($digits) > 11 && str_starts_with($digits, '55')) $digits = substr($digits, 2); if (!in_array(strlen($digits), [10, 11], true)) $this->rowError('Formato de telefone inválido.', $column); return $digits; }
    private function normalizeClass(?string $value): ?string { if ($value === null || trim($value) === '') return null; $value = mb_strtolower(trim(str_replace('_', ' ', $value))); return mb_substr(preg_replace('/\s+/', ' ', $value) ?? $value, 0, 255); }
    private function rowError(string $message, string $column): never { throw new \RuntimeException($message, 0, new \RuntimeException($column)); }

    /** @param array<int,string> $headers @return array<string,int> */
    private static function buildHeaderIndex(array $headers): array
    {
        $aliases = ['cpf_cliente' => 'cpfcliente', 'nome_cliente' => 'nomecliente', 'data_nascimento' => 'datanascimento', 'classe_fone_1' => 'classefone1', 'classe_fone_2' => 'classefone2', 'classe_fone_3' => 'classefone3', 'classe_fone_4' => 'classefone4', 'data_contrato' => 'datacontrato'];
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
