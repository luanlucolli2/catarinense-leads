<?php

namespace App\Imports;

use App\Models\Lead;
use App\Models\LeadContract;
use App\Models\ImportJob;
use App\Models\ImportError;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB; // 1. Importar a classe DB para transações
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\RemembersRowNumber;
use Maatwebsite\Excel\Concerns\WithEvents;          // ← novo
use Maatwebsite\Excel\Events\AfterChunk;  
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class CadastralImport implements ToModel, WithHeadingRow, WithChunkReading, WithEvents,ShouldQueue
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
    ];

    protected ImportJob $importJob;

    public function __construct(ImportJob $importJob)
    {
        $this->importJob = $importJob;
    }

    public function model(array $row)
    {
        // 2. Envolver toda a lógica em uma transação de banco de dados
        DB::transaction(function () use ($row) {
            try {
                $validator = Validator::make($row, [
                    'cpfcliente' => ['required'],
                ]);

                if ($validator->fails()) {
                    throw new ValidationException($validator);
                }

                $cpf = preg_replace('/\D/', '', (string) ($row['cpfcliente'] ?? ''));

                // 3. Adicionar a validação de CPF real
                if (!$this->isValidCpf($cpf)) {
                    throw new \Exception("CPF inválido (dígito verificador não confere).", 0, new \Exception('cpfcliente'));
                }

                $lead = Lead::firstOrNew(['cpf' => $cpf]);
                $action = $lead->exists ? 'update' : 'insert';

                $dataFromSheet = [
                    'nome' => $row['nomecliente'] ?? null,
                    'data_nascimento' => $this->transformDate($row['datanascimento'] ?? null),
                    'fone1' => $this->normalizePhone($row['fone1'] ?? null),
                    'classe_fone1' => $this->normalizeClasse($row['classefone1'] ?? null),
                    'fone2' => $this->normalizePhone($row['fone2'] ?? null),
                    'classe_fone2' => $this->normalizeClasse($row['classefone2'] ?? null),
                    'fone3' => $this->normalizePhone($row['fone3'] ?? null),
                    'classe_fone3' => $this->normalizeClasse($row['classefone3'] ?? null),
                    'fone4' => $this->normalizePhone($row['fone4'] ?? null),
                    'classe_fone4' => $this->normalizeClasse($row['classefone4'] ?? null),
                ];

                foreach ($dataFromSheet as $field => $value) {
                    if (!is_null($value) && $value !== '') {
                        $lead->{$field} = $value;
                    }
                }

                $lead->save();

                if (!empty($row['datacontrato'])) {
                    $contractDate = $this->transformDate($row['datacontrato']);
                    if ($contractDate) {
                        LeadContract::updateOrCreate(
                            ['lead_id' => $lead->id, 'data_contrato' => $contractDate],
                            []
                        );
                    } else {
                        throw new \Exception("Formato de data inválido.", 0, new \Exception('datacontrato'));
                    }
                }

                $lead->importJobs()->attach($this->importJob->id, ['action' => $action]);

            } catch (\Exception $e) {
                // Se qualquer erro ocorrer, a transação será desfeita (rollback)
                // e nós registramos o erro.
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
        if (empty($value)) return null;
        if (is_numeric($value)) {
            return Carbon::instance(Date::excelToDateTimeObject($value))->format('Y-m-d');
        }
        try {
            return Carbon::createFromFormat('d/m/Y', trim($value))->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private function normalizePhone($phone): ?string
    {
        if (empty($phone)) return null;
        return preg_replace('/\D/', '', (string) $phone);
    }
    
    private function normalizeClasse($classe): ?string
    {
        if (empty($classe)) return null;
        return ucfirst(strtolower(trim($classe)));
    }

    /**
     * Valida um CPF usando o algoritmo de cálculo dos dígitos verificadores.
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

    public function registerEvents(): array
    {
        return [
            AfterChunk::class => function () {

                // 1. Sincroniza o modelo com o banco
                $this->importJob->refresh();

                // 2. Quanto ainda falta?
                $remaining = $this->importJob->total_rows
                            - $this->importJob->processed_rows;

                if ($remaining <= 0) {
                    return;           // já registrado tudo
                }

                // 3. Soma o menor valor: chunkSize() ou o que ainda resta
                $increment = min($this->chunkSize(), $remaining);

                $this->importJob->increment('processed_rows', $increment);
            },
        ];
    }

}