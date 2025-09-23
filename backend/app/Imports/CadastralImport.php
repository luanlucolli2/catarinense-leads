<?php

namespace App\Imports;

use App\Models\Lead;
use App\Models\LeadContract;
use App\Models\ImportJob;
use App\Models\ImportError;
use App\Models\Vendor;
use App\Support\Cpf;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\RemembersRowNumber;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterChunk;
use Maatwebsite\Excel\Events\AfterImport;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use App\Services\BackupService;

class CadastralImport implements ToModel, WithHeadingRow, WithChunkReading, WithEvents, ShouldQueue
{
    use RemembersRowNumber;

    public const REQUIRED_HEADERS = [
        'cpfcliente',
        'nomecliente',
        'datanascimento',
        'fone1',
        'classefone1',
        'fone2',
        'classefone2',
        'fone3',
        'classefone3',
        'fone4',
        'classefone4',
        'datacontrato',
        'vendedor',
    ];

    protected ImportJob $importJob;
    protected BackupService $backup;

    /** Cache simples de vendors por import (name_clean => id) */
    protected array $vendorCache = [];

    public function __construct(ImportJob $importJob, BackupService $backup)
    {
        $this->importJob = $importJob;
        $this->backup    = $backup;
    }

    public function model(array $row)
    {
        // Alinha chaves caso planilha venha com underscores/variações
        $row = $this->normalizeRowKeys($row);

        // 🔕 Skip silencioso se a linha for realmente vazia (evita erros e I/O desnecessário)
        if ($this->isEffectivelyEmptyRow($row)) {
            return null;
        }

        DB::transaction(function () use ($row) {
            try {
                // 1) Validação mínima: CPF obrigatório para localizar/identificar
                $validatorCpf = Validator::make($row, [
                    'cpfcliente' => ['required'],
                ]);
                if ($validatorCpf->fails()) {
                    throw new ValidationException($validatorCpf);
                }

                // 2) CPF: normaliza (zeros à esquerda) e valida DV
                $cpf = Cpf::normalize($row['cpfcliente'] ?? null);
                if (!$cpf || !Cpf::isValid($cpf)) {
                    throw new \Exception("CPF inválido.", 0, new \Exception('cpfcliente'));
                }

                // 3) Busca/cria Lead (ainda sem salvar)
                $lead   = Lead::firstOrNew(['cpf' => $cpf]);
                $action = $lead->exists ? 'update' : 'insert';

                // 4) INSERT exige nome; UPDATE não exige
                if ($action === 'insert') {
                    $validatorNome = Validator::make($row, [
                        'nomecliente' => ['required', 'string'],
                    ]);
                    if ($validatorNome->fails()) {
                        throw new ValidationException($validatorNome);
                    }
                }

                // 5) Backup do estado anterior (apenas quando update)
                if (
                    $action === 'update'
                    && !$lead->backups()->where('import_job_id', $this->importJob->id)->exists()
                ) {
                    $this->backup->bulkBackupLeads(collect([$lead]), $this->importJob);
                }

                // 6) Normalização de campos
                $normalizedNameForInsert = null;
                if ($action === 'insert') {
                    $normalizedNameForInsert = $this->normalizeName($row['nomecliente'] ?? null);
                    if ($normalizedNameForInsert === null) {
                        throw new \Exception("Nome é obrigatório para inserir novo lead.", 0, new \Exception('nomecliente'));
                    }
                }

                $dataFromSheet = [
                    'nome'             => $normalizedNameForInsert ?? $this->normalizeName($row['nomecliente'] ?? null),
                    'data_nascimento'  => $this->transformDate($row['datanascimento'] ?? null),

                    'fone1'            => $this->normalizePhone($row['fone1'] ?? null, 'fone1'),
                    'classe_fone1'     => $this->normalizeClasse($row['classefone1'] ?? null),

                    'fone2'            => $this->normalizePhone($row['fone2'] ?? null, 'fone2'),
                    'classe_fone2'     => $this->normalizeClasse($row['classefone2'] ?? null),

                    'fone3'            => $this->normalizePhone($row['fone3'] ?? null, 'fone3'),
                    'classe_fone3'     => $this->normalizeClasse($row['classefone3'] ?? null),

                    'fone4'            => $this->normalizePhone($row['fone4'] ?? null, 'fone4'),
                    'classe_fone4'     => $this->normalizeClasse($row['classefone4'] ?? null),
                ];

                // 7) Reconciliador de telefones (preserva "Quente")
                $mergedPhones = $this->mergePhones($lead, $dataFromSheet);
                foreach ($mergedPhones as $field => $value) {
                    $dataFromSheet[$field] = $value;
                }

                // 8) Aplica apenas campos não-nulos/vazios (updates parciais)
                foreach ($dataFromSheet as $field => $value) {
                    if (!is_null($value) && $value !== '') {
                        $lead->{$field} = $value;
                    }
                }
                $lead->save();

                // 9) Backup marca lead novo
                if ($action === 'insert') {
                    $this->backup->backupNewLead($lead, $this->importJob);
                }

                // 10) Contratos + vendedor (opcional)
                if (!empty($row['datacontrato'])) {
                    $contractDate = $this->transformDate($row['datacontrato']);
                    if (!$contractDate) {
                        throw new \Exception("Formato de data inválido.", 0, new \Exception('datacontrato'));
                    }

                    $vendorId = null;
                    if (!empty($row['vendedor'])) {
                        $cleanedVendorName = $this->normalizeName($row['vendedor']);
                        $vendorId = $this->resolveVendorId($cleanedVendorName);
                    }

                    $contract = LeadContract::updateOrCreate(
                        ['lead_id' => $lead->id, 'data_contrato' => $contractDate],
                        ['vendor_id' => $vendorId]
                    );

                    if ($contract->wasRecentlyCreated) {
                        $this->backup->backupInsertedContract($contract, $this->importJob);
                    }
                }

                // 11) Registra pivot idempotente SEM timestamps extras
                DB::table('lead_imports')->insertOrIgnore([[
                    'lead_id'       => $lead->id,
                    'import_job_id' => $this->importJob->id,
                    'action'        => $action,
                    'created_at'    => now(),
                ]]);

            } catch (\Exception $e) {
                $columnName = 'Geral';
                if ($e instanceof ValidationException) {
                    $columnName = array_key_first($e->errors());
                } elseif ($e->getPrevious()) {
                    $columnName = $e->getPrevious()->getMessage();
                }

                ImportError::create([
                    'import_job_id' => $this->importJob->id,
                    'row_number'    => $this->getRowNumber(),
                    'column_name'   => $columnName,
                    'error_message' => $e->getMessage(),
                ]);
            }
        });

        return null;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    private function transformDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        if (is_numeric($value)) {
            return Carbon::instance(Date::excelToDateTimeObject($value))->format('Y-m-d');
        }
        try {
            return Carbon::createFromFormat('d/m/Y', trim($value))->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function normalizeName(?string $name): ?string
    {
        if ($name === null || $name === '') {
            return null; // updates parciais não obrigam nome
        }
        $name = trim($name);
        $name = preg_replace('/[^\p{L}\p{N} \'\-]/u', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $len  = mb_strlen($name);
        if ($len < 2 || $len > 100) {
            throw new \Exception("Tamanho de nome deve ser entre 2 e 100 caracteres.", 0, new \Exception('nomecliente'));
        }
        return $name;
    }

    private function mergePhones(Lead $lead, array $incomingSheet): array
    {
        $normClass = function ($c) {
            $c = ucfirst(strtolower($c ?? 'Frio'));
            return $c === 'Quente' ? 'Quente' : 'Frio';
        };

        $result = [
            ['phone' => $lead->fone1, 'class' => $normClass($lead->classe_fone1)],
            ['phone' => $lead->fone2, 'class' => $normClass($lead->classe_fone2)],
            ['phone' => $lead->fone3, 'class' => $normClass($lead->classe_fone3)],
            ['phone' => $lead->fone4, 'class' => $normClass($lead->classe_fone4)],
        ];

        $incoming = [
            ['phone' => $incomingSheet['fone1'] ?? null, 'class' => $normClass($incomingSheet['classe_fone1'] ?? null)],
            ['phone' => $incomingSheet['fone2'] ?? null, 'class' => $normClass($incomingSheet['classe_fone2'] ?? null)],
            ['phone' => $incomingSheet['fone3'] ?? null, 'class' => $normClass($incomingSheet['classe_fone3'] ?? null)],
            ['phone' => $incomingSheet['fone4'] ?? null, 'class' => $normClass($incomingSheet['classe_fone4'] ?? null)],
        ];

        foreach ($incoming as $slot) {
            $phone = $slot['phone'];
            if (!$phone) continue;

            $class = $slot['class'];

            // já existe?
            $idxExisting = array_search($phone, array_column($result, 'phone'), true);
            if ($idxExisting !== false) {
                if ($class === 'Quente' && $result[$idxExisting]['class'] !== 'Quente') {
                    $result[$idxExisting]['class'] = 'Quente';
                }
                continue;
            }

            if ($class === 'Quente') {
                // tenta slot vazio
                $freeIdx = null;
                foreach ($result as $i => $s) {
                    if (empty($s['phone'])) { $freeIdx = $i; break; }
                }
                if (!is_null($freeIdx)) { $result[$freeIdx] = $slot; continue; }

                // substitui primeiro "Frio"
                foreach ($result as $i => $s) {
                    if ($s['class'] === 'Frio') { $result[$i] = $slot; continue 2; }
                }
                // todos Quente → descarta
            } else {
                // Frio só entra em slot livre
                foreach ($result as $i => $s) {
                    if (empty($s['phone'])) { $result[$i] = $slot; break; }
                }
            }
        }

        return [
            'fone1'        => $result[0]['phone'],
            'classe_fone1' => $result[0]['class'],
            'fone2'        => $result[1]['phone'],
            'classe_fone2' => $result[1]['class'],
            'fone3'        => $result[2]['phone'],
            'classe_fone3' => $result[2]['class'],
            'fone4'        => $result[3]['phone'],
            'classe_fone4' => $result[3]['class'],
        ];
    }

    private function normalizePhone(?string $phone, string $column): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) > 11 && substr($digits, 0, 2) === '55') {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) !== 10 && strlen($digits) !== 11) {
            throw new \Exception("Formato de telefone inválido.", 0, new \Exception($column));
        }
        return $digits;
    }

    private function normalizeClasse($classe): ?string
    {
        if ($classe === null || $classe === '') return null;
        return ucfirst(strtolower(trim($classe)));
    }

    public function registerEvents(): array
    {
        return [
            // Incremento atômico capado em total_rows (tolerante a múltiplos workers)
            AfterChunk::class => function () {
                $remaining = max($this->importJob->total_rows - $this->importJob->processed_rows, 0);
                if ($remaining <= 0) {
                    return;
                }
                $increment = min($this->chunkSize(), $remaining);

                DB::table('import_jobs')
                    ->where('id', $this->importJob->id)
                    ->update([
                        'processed_rows' => DB::raw('LEAST(processed_rows + ' . (int)$increment . ', total_rows)')
                    ]);

                $this->importJob->refresh();
            },

            AfterImport::class => function () {
                $this->importJob->update([
                    'processed_rows' => $this->importJob->total_rows,
                    'status'         => 'concluido',
                    'finished_at'    => now(),
                ]);
            },
        ];
    }

    /**
     * Aceita aliases com underscore conforme HeadingRow (sem mudar chaves canônicas).
     * Ex.: data_nascimento → datanascimento
     */
    private function normalizeRowKeys(array $row): array
    {
        $aliases = [
            'cpf_cliente'       => 'cpfcliente',
            'cpf_cliente '      => 'cpfcliente',
            'nome_cliente'      => 'nomecliente',
            'data_nascimento'   => 'datanascimento',
            'classe_fone1'      => 'classefone1',
            'classe_fone2'      => 'classefone2',
            'classe_fone3'      => 'classefone3',
            'classe_fone4'      => 'classefone4',
            'data_contrato'     => 'datacontrato',
        ];

        foreach ($aliases as $from => $to) {
            if (!array_key_exists($to, $row) && array_key_exists($from, $row)) {
                $row[$to] = $row[$from];
            }
        }
        return $row;
    }

    /** Linha realmente vazia (todas as colunas canônicas sem conteúdo) */
    private function isEffectivelyEmptyRow(array $row): bool
    {
        foreach (self::REQUIRED_HEADERS as $key) {
            if (array_key_exists($key, $row)) {
                $v = $row[$key];
                if ($v !== null && trim((string)$v) !== '') {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Resolve vendor id com cache + tolerância a corrida de unicidade.
     */
    private function resolveVendorId(string $cleanedVendorName): ?int
    {
        $key = Vendor::clean($cleanedVendorName);
        if (isset($this->vendorCache[$key])) {
            return $this->vendorCache[$key];
        }

        // 1) tenta localizar
        $vendor = Vendor::where('name_clean', $key)->first();
        if ($vendor) {
            $this->vendorCache[$key] = $vendor->id;
            return $vendor->id;
        }

        // 2) tenta criar; se bater corrida, lê novamente
        try {
            $vendor = Vendor::firstOrCreate(
                ['name_clean' => $key],
                ['name' => $cleanedVendorName]
            );
        } catch (\Throwable $t) {
            $vendor = Vendor::where('name_clean', $key)->firstOrFail();
        }

        if ($vendor->wasRecentlyCreated ?? false) {
            $this->backup->backupVendorIfNew($vendor, $this->importJob);
        }

        $this->vendorCache[$key] = $vendor->id;
        return $vendor->id;
    }
}
