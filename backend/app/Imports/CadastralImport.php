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
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\RemembersRowNumber; // 1. Importar o Trait

class CadastralImport implements ToModel, WithHeadingRow, WithChunkReading, ShouldQueue
{
        use RemembersRowNumber; // 2. Usar o Trait

    protected ImportJob $importJob;

    public function __construct(ImportJob $importJob)
    {
        $this->importJob = $importJob;
    }

    /**
    * @param array $row
    *
    * @return \Illuminate\Database\Eloquent\Model|null
    */
    public function model(array $row)
    {
        try {
            // 1. Normalizar e validar dados da linha
            $cpf = preg_replace('/\D/', '', $row['cpfcliente']);
            if (strlen($cpf) !== 11) {
                throw new \Exception("CPF inválido.");
            }

            // 2. Lógica de Upsert do Lead
            $lead = Lead::updateOrCreate(
                ['cpf' => $cpf],
                [
                    'nome' => $row['nomecliente'],
                    'data_nascimento' => $this->transformDate($row['datanascimento']),
                    'fone1' => $this->normalizePhone($row['fone1']),
                    'classe_fone1' => $row['classe1'],
                    'fone2' => $this->normalizePhone($row['fone2']),
                    'classe_fone2' => $row['classe2'],
                    'fone3' => $this->normalizePhone($row['fone3']),
                    'classe_fone3' => $row['classe3'],
                    'fone4' => $this->normalizePhone($row['fone4']),
                    'classe_fone4' => $row['classe4'],
                ]
            );

            // 3. Adicionar o contrato, se houver
            if (!empty($row['datacontrato'])) {
                LeadContract::updateOrCreate(
                    ['lead_id' => $lead->id, 'data_contrato' => $this->transformDate($row['datacontrato'])],
                    []
                );
            }

            // 4. Registrar na tabela pivot
            $lead->importJobs()->attach($this->importJob->id, ['action' => $lead->wasRecentlyCreated ? 'insert' : 'update']);

        } catch (\Exception $e) {
            // 5. Registrar qualquer erro que ocorrer nesta linha
            ImportError::create([
                'import_job_id' => $this->importJob->id,
                'row_number' => $this->getRowNumber(),
                'column_name' => 'N/A', // Pode ser melhorado para identificar a coluna exata
                'error_message' => $e->getMessage(),
            ]);
        }

        return null; // Retornamos null porque já estamos tratando a criação/atualização manualmente
    }

    public function chunkSize(): int
    {
        return 1000; // Processa a planilha em blocos de 1000 linhas
    }

    private function transformDate($value): ?string
    {
        if (empty($value)) return null;
        // Tenta converter o formato dd/mm/aaaa para aaaa-mm-dd
        try {
            return Carbon::createFromFormat('d/m/Y', $value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null; // Retorna nulo se a data for inválida
        }
    }
    
    private function normalizePhone($phone): ?string
    {
        if (empty($phone)) return null;
        return preg_replace('/\D/', '', $phone);
    }
}