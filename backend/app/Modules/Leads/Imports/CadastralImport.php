<?php

namespace App\Modules\Leads\Imports;

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
use Maatwebsite\Excel\Events\BeforeImport;
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

    protected array $vendorCache = [];

    /** contador real deste chunk */
    protected int $rowsInCurrentChunk = 0;

    public function __construct(ImportJob $importJob, BackupService $backup)
    {
        $this->importJob = $importJob;
        $this->backup    = $backup;
    }

    public function model(array $row)
    {
        // normaliza chaves
        $row = $this->normalizeRowKeys($row);

        // cpf com dígitos?
        $cpfRaw = $row['cpfcliente'] ?? null;
        $digits = $cpfRaw !== null ? preg_replace('/\D+/', '', (string)$cpfRaw) : '';
        if ($digits === '') {
            return null; // ignora linha sem CPF
        }
        $this->rowsInCurrentChunk++;

        // skip linha realmente vazia por segurança
        if ($this->isEffectivelyEmptyRow($row)) {
            return null;
        }

        try {
            // 1) valida cpf requerido
            $validatorCpf = Validator::make($row, ['cpfcliente' => ['required']]);
            if ($validatorCpf->fails()) {
                throw new ValidationException($validatorCpf);
            }

            // 2) normaliza/valida cpf
            $cpf = Cpf::normalize($row['cpfcliente'] ?? null);
            if (!$cpf || !Cpf::isValid($cpf)) {
                throw new \Exception("CPF inválido.", 0, new \Exception('cpfcliente'));
            }

            // 3) busca/cria lead
            $lead   = Lead::firstOrNew(['cpf' => $cpf]);
            $action = $lead->exists ? 'update' : 'insert';

            // 4) insert exige nome
            if ($action === 'insert') {
                $validatorNome = Validator::make($row, ['nomecliente' => ['required', 'string']]);
                if ($validatorNome->fails()) {
                    throw new ValidationException($validatorNome);
                }
            }

            // 5) backup antes de atualizar
            if ($action === 'update' && !$lead->backups()->where('import_job_id', $this->importJob->id)->exists()) {
                $this->backup->backupExistingLead($lead, $this->importJob);
            }

            // 6) normalização de campos
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

            // 7) merge de telefones (com prioridades Carteira > Atendimento IA > demais)
            $mergedPhones = $this->mergePhones($lead, $dataFromSheet);
            foreach ($mergedPhones as $field => $value) {
                $dataFromSheet[$field] = $value;
            }

            // 8) aplica somente campos não vazios
            foreach ($dataFromSheet as $field => $value) {
                if (!is_null($value) && $value !== '') {
                    $lead->{$field} = $value;
                }
            }
            $lead->save();

            // 9) backup de lead novo
            if ($action === 'insert') {
                $this->backup->backupNewLead($lead, $this->importJob);
            }

            // 10) contratos + vendedor
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

            // 11) pivot idempotente
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

        return null;
    }

    public function chunkSize(): int
    {
        return max(1, (int) config('leads.import.chunk_size', 1000));
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
            return null;
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

    /**
     * Retorna prioridade da classe (com string já normalizada para comparação):
     *  - 2: carteira
     *  - 1: atendimento ia
     *  - 0: demais / vazio
     */
    private function classPriority(?string $class): int
    {
        $c = $this->normalizeClasse($class) ?? '';
        if ($c === 'carteira') return 2;
        if ($c === 'atendimento ia') return 1;
        return 0;
    }

    private function mergePhones(Lead $lead, array $incomingSheet): array
    {
        // empacota número + classe normalizada + prioridade
        $pack = function ($phone, $class) {
            $normClass = $this->normalizeClasse($class);
            return [
                'phone' => $phone ?: null,
                'class' => $normClass,                 // sempre salvo/propago normalizado (lowercase, espaços colapsados)
                'prio'  => $this->classPriority($normClass),
            ];
        };

        // estado atual (classes normalizadas na leitura)
        $result = [
            $pack($lead->fone1, $lead->classe_fone1),
            $pack($lead->fone2, $lead->classe_fone2),
            $pack($lead->fone3, $lead->classe_fone3),
            $pack($lead->fone4, $lead->classe_fone4),
        ];

        // entradas novas (já normalizadas via normalizeClasse)
        $incoming = [
            $pack($incomingSheet['fone1'] ?? null, $incomingSheet['classe_fone1'] ?? null),
            $pack($incomingSheet['fone2'] ?? null, $incomingSheet['classe_fone2'] ?? null),
            $pack($incomingSheet['fone3'] ?? null, $incomingSheet['classe_fone3'] ?? null),
            $pack($incomingSheet['fone4'] ?? null, $incomingSheet['classe_fone4'] ?? null),
        ];

        foreach ($incoming as $slot) {
            $phone = $slot['phone'];
            if (!$phone) continue;

            $incomingClass = $slot['class'];
            $incomingPrio  = $slot['prio'];

            // já existe o mesmo número?
            $idxExisting = array_search($phone, array_column($result, 'phone'), true);
            if ($idxExisting !== false) {
                // só atualiza a classificação se a nova tiver prioridade MAIOR
                if ($incomingPrio > $result[$idxExisting]['prio']) {
                    $result[$idxExisting]['class'] = $incomingClass; // persistimos já normalizado
                    $result[$idxExisting]['prio']  = $incomingPrio;
                }
                continue;
            }

            // número novo
            // 1) tenta preencher slot vazio
            $freeIdx = null;
            foreach ($result as $i => $s) {
                if (empty($s['phone'])) { $freeIdx = $i; break; }
            }
            if (!is_null($freeIdx)) {
                $result[$freeIdx] = $slot;
                continue;
            }

            // 2) sem vagas: substitui apenas se prioridade do novo for maior que algum existente
            if ($incomingPrio > 0) {
                $minPrio = min(array_column($result, 'prio'));
                if ($incomingPrio > $minPrio) {
                    foreach ($result as $i => $s) {
                        if ($s['prio'] === $minPrio) {
                            $result[$i] = $slot;
                            break;
                        }
                    }
                }
            }
            // prioridade 0 sem vagas: ignora (não derruba ninguém)
        }

        return [
            'fone1'        => $result[0]['phone'] ?? null,
            'classe_fone1' => $result[0]['class'] ?? null,
            'fone2'        => $result[1]['phone'] ?? null,
            'classe_fone2' => $result[1]['class'] ?? null,
            'fone3'        => $result[2]['phone'] ?? null,
            'classe_fone3' => $result[2]['class'] ?? null,
            'fone4'        => $result[3]['phone'] ?? null,
            'classe_fone4' => $result[3]['class'] ?? null,
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

    /**
     * Normaliza a classificação para persistência:
     * - trim + substitui underscores por espaço
     * - colapsa espaços
     * - remove caracteres de controle
     * - lowercase (sempre salvar como minúsculas)
     * - limita a 255 caracteres
     */
    private function normalizeClasse($classe): ?string
    {
        if ($classe === null) return null;
        $s = trim((string)$classe);
        if ($s === '') return null;

        $s = str_replace('_', ' ', $s);
        $s = preg_replace('/\s+/', ' ', $s);
        $s = preg_replace('/[^\P{C}]+/u', '', $s) ?? $s;
        $s = mb_strtolower($s);
        return mb_substr($s, 0, 255);
    }

    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function () {
                // limpa backups antigos no início do import
                $this->backup->purgeOldBackups();
                $this->rowsInCurrentChunk = 0;
            },

            AfterChunk::class => function () {
                if ($this->rowsInCurrentChunk <= 0) {
                    return;
                }

                DB::table('import_jobs')
                    ->where('id', $this->importJob->id)
                    ->update([
                        'processed_rows' => DB::raw('LEAST(processed_rows + ' . (int)$this->rowsInCurrentChunk . ', total_rows)')
                    ]);

                $this->rowsInCurrentChunk = 0;
            },

            AfterImport::class => function () {
                $this->rowsInCurrentChunk = 0;
                $this->importJob->update([
                    'processed_rows' => $this->importJob->total_rows,
                    'status'         => 'concluido',
                    'finished_at'    => now(),
                ]);
            },
        ];
    }

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

    private function resolveVendorId(string $cleanedVendorName): ?int
    {
        $key = Vendor::clean($cleanedVendorName);
        if (isset($this->vendorCache[$key])) {
            return $this->vendorCache[$key];
        }

        $vendor = Vendor::where('name_clean', $key)->first();
        if ($vendor) {
            $this->vendorCache[$key] = $vendor->id;
            return $vendor->id;
        }

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
