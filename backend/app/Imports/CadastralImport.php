<?php

namespace App\Imports;

use App\Models\Lead;
use App\Models\LeadContract;
use App\Models\ImportJob;
use App\Models\ImportError;
use App\Models\Vendor;
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

    public function __construct(ImportJob $importJob)
    {
        $this->importJob = $importJob;
    }

    public function model(array $row)
    {
        DB::transaction(function () use ($row) {
            try {
                // validações iniciais
                $validator = Validator::make($row, [
                    'cpfcliente' => ['required'],
                    'nomecliente' => ['required', 'string'],
                ]);
                if ($validator->fails()) {
                    throw new ValidationException($validator);
                }

                // CPF
                $cpf = preg_replace('/\D/', '', (string) ($row['cpfcliente'] ?? ''));
                if (!$this->isValidCpf($cpf)) {
                    throw new \Exception("CPF inválido.", 0, new \Exception('cpfcliente'));
                }

                // busca ou cria Lead
                $lead = Lead::firstOrNew(['cpf' => $cpf]);
                $action = $lead->exists ? 'update' : 'insert';

                // normalização e validação de campos
                $dataFromSheet = [
                    'nome' => $this->normalizeName($row['nomecliente']),
                    'data_nascimento' => $this->transformDate($row['datanascimento'] ?? null),
                    'fone1' => $this->normalizePhone($row['fone1'] ?? null, 'fone1'),
                    'classe_fone1' => $this->normalizeClasse($row['classefone1'] ?? null),
                    'fone2' => $this->normalizePhone($row['fone2'] ?? null, 'fone2'),
                    'classe_fone2' => $this->normalizeClasse($row['classefone2'] ?? null),
                    'fone3' => $this->normalizePhone($row['fone3'] ?? null, 'fone3'),
                    'classe_fone3' => $this->normalizeClasse($row['classefone3'] ?? null),
                    'fone4' => $this->normalizePhone($row['fone4'] ?? null, 'fone4'),
                    'classe_fone4' => $this->normalizeClasse($row['classefone4'] ?? null),
                ];

                // aplica apenas campos não-nulos
                foreach ($dataFromSheet as $field => $value) {
                    if (!is_null($value) && $value !== '') {
                        $lead->{$field} = $value;
                    }
                }

                $lead->save();

                // contratos + vendedor
                if (!empty($row['datacontrato'])) {
                    $contractDate = $this->transformDate($row['datacontrato']);
                    if (!$contractDate) {
                        throw new \Exception("Formato de data inválido.", 0, new \Exception('datacontrato'));
                    }

                    if (!empty($row['vendedor'])) {
                        // aplica a mesma normalizeName() que usamos pro lead
                        $cleanedVendorName = $this->normalizeName($row['vendedor']);
                        $vendor = Vendor::firstOrCreate(
                            ['name_clean' => Vendor::clean($cleanedVendorName)],
                            ['name' => $cleanedVendorName]
                        );
                        $vendorId = $vendor->id;
                    }

                    LeadContract::updateOrCreate(
                        ['lead_id' => $lead->id, 'data_contrato' => $contractDate],
                        ['vendor_id' => $vendorId]
                    );
                }

                // registra import_job pivot
                $lead->importJobs()->attach($this->importJob->id, ['action' => $action]);

            } catch (\Exception $e) {
                // captura erro e grava ImportError
                $columnName = 'Geral';
                if ($e instanceof ValidationException) {
                    $columnName = array_key_first($e->errors());
                } elseif ($e->getPrevious()) {
                    $columnName = $e->getPrevious()->getMessage();
                }

                ImportError::create([
                    'import_job_id' => $this->importJob->id,
                    'row_number' => $this->getRowNumber(),
                    'column_name' => $columnName,
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
            return Carbon::instance(Date::excelToDateTimeObject($value))
                ->format('Y-m-d');
        }
        try {
            return Carbon::createFromFormat('d/m/Y', trim($value))
                ->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Normaliza CPF (já feito antes).
     */
    private function isValidCpf(string $cpf): bool
    {
        if (strlen($cpf) != 11 || preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) {
                return false;
            }
        }
        return true;
    }

    /**
     * Valida e normaliza um nome:
     * - remove emojis e caracteres especiais
     * - trim + colapsa espaços
     * - verifica tamanho mínimo e máximo (2–100)
     */
    private function normalizeName(?string $name): ?string
    {
        if (empty($name)) {
            return null;
        }
        $name = trim($name);
        // remove tudo que não for letra, número, espaço, apóstrofo ou hífen
        $name = preg_replace('/[^\p{L}\p{N} \'\-]/u', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $len = mb_strlen($name);
        if ($len < 2 || $len > 100) {
            throw new \Exception("Tamanho de nome deve ser entre 2 e 100 caracteres.", 0, new \Exception('nomecliente'));
        }
        return $name;
    }

    /**
     * Valida e normaliza telefone:
     * - strip non digits
     * - strip DDI “55” se presente
     * - garante DDD + número (10 ou 11 dígitos)
     */
    private function normalizePhone(?string $phone, string $column): ?string
    {
        if (empty($phone)) {
            return null;
        }
        // tira tudo que não for dígito
        $digits = preg_replace('/\D/', '', $phone);
        // remove DDI “55” caso tenha mais de 11 dígitos
        if (strlen($digits) > 11 && substr($digits, 0, 2) === '55') {
            $digits = substr($digits, 2);
        }
        // deve ter DDD + número: 10 ou 11 dígitos
        if (strlen($digits) !== 10 && strlen($digits) !== 11) {
            throw new \Exception("Formato de telefone inválido.", 0, new \Exception($column));
        }
        return $digits;
    }

    private function normalizeClasse($classe): ?string
    {
        if (empty($classe)) {
            return null;
        }
        return ucfirst(strtolower(trim($classe)));
    }

    public function registerEvents(): array
    {
        return [
            AfterChunk::class => function () {
                $this->importJob->refresh();
                $remaining = $this->importJob->total_rows - $this->importJob->processed_rows;
                if ($remaining <= 0) {
                    return;
                }
                $increment = min($this->chunkSize(), $remaining);
                $this->importJob->increment('processed_rows', $increment);
            },
            AfterImport::class => function () {
                $this->importJob->update([
                    'processed_rows' => $this->importJob->total_rows,
                    'status' => 'concluido',
                    'finished_at' => now(),
                ]);
            },
        ];
    }
}
