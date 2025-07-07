<?php

namespace App\Imports;

use App\Models\Lead;
use App\Models\ImportJob;
use App\Models\ImportError;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Contracts\Queue\ShouldQueue;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\RemembersRowNumber; // 1. Importar o Trait

class HigienizacaoImport implements ToModel, WithHeadingRow, WithChunkReading, ShouldQueue
{
    use RemembersRowNumber; // 2. Usar o Trait

    protected ImportJob $importJob;

    public function __construct(ImportJob $importJob)
    {
        $this->importJob = $importJob;
    }

    public function model(array $row)
    {
        try {
            $cpf = preg_replace('/\D/', '', $row['cpfcliente']);
            if (strlen($cpf) !== 11) {
                throw new \Exception("CPF inválido.");
            }

            $lead = Lead::where('cpf', $cpf)->first();

            if (!$lead) {
                throw new \Exception("Lead com CPF não encontrado na base de dados.");
            }

            $lead->update([
                'consulta' => $row['consulta'],
                'data_atualizacao' => $this->transformDateTime($row['dataatualizacao']),
                'saldo' => $row['saldo'],
                'libera' => $row['libera'],
            ]);

            $lead->importJobs()->attach($this->importJob->id, ['action' => 'update']);

        } catch (\Exception $e) {
            ImportError::create([
                'import_job_id' => $this->importJob->id,
                'row_number' => $this->getRowNumber(),
                'column_name' => 'cpfcliente',
                'error_message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    private function transformDateTime($value): ?string
    {
        if (empty($value))
            return null;
        try {
            // Lida com o formato "d/m/Y H:i:s"
            return Carbon::createFromFormat('d/m/Y H:i:s', trim($value))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}