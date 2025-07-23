<?php

namespace App\Imports;

use App\Models\Lead;
use App\Models\ImportJob;
use App\Models\ImportError;
use App\Services\BackupService;               // 👈 NOVO
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\RemembersRowNumber;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterChunk;
use Maatwebsite\Excel\Events\AfterImport;

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
    protected BackupService $backup;          // 👈 NOVO

    public function __construct(ImportJob $importJob, BackupService $backup)
    {
        $this->importJob = $importJob;
        $this->backup = $backup;           // 👈 NOVO
    }

    public function model(array $row)
    {
        try {
            /* ---------- validação original ---------- */
            $validator = Validator::make($row, [
                'cpfcliente' => ['required'],
                'consulta' => ['required', 'string'],
                'dataatualizacao' => ['required'],
                'saldo' => ['required'],
                'libera' => ['required'],
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $data = $validator->validated();

            /* ---------- CPF normalizado ---------- */
            $cpf = preg_replace('/\D/', '', (string) $data['cpfcliente']);
            if (strlen($cpf) !== 11) {
                throw new \Exception(
                    "Formato de CPF inválido.",
                    0,
                    new \Exception('cpfcliente')
                );
            }

            /* ---------- Lead deve existir ---------- */
            $lead = Lead::where('cpf', $cpf)->first();
            if (!$lead) {
                throw new \Exception(
                    "Lead com CPF não encontrado na base de dados.",
                    0,
                    new \Exception('cpfcliente')
                );
            }

            /* ---------- BACKUP antes de atualizar ---------- */
            $alreadyBackedUp = \App\Models\Backup\LeadBackup::where('lead_id', $lead->id)
                ->where('import_job_id', $this->importJob->id)
                ->exists();

            if (!$alreadyBackedUp) {
                $this->backup->bulkBackupLeads(collect([$lead]), $this->importJob);
            }

            /* ---------- Transformação de data ---------- */
            $dt = $this->transformDateTime($data['dataatualizacao']);
            if (!$dt) {
                throw new \Exception(
                    "Formato de data inválido. Use dd/mm/aaaa hh:mm:ss.",
                    0,
                    new \Exception('dataatualizacao')
                );
            }

            /* ---------- Atualização do Lead ---------- */
            $lead->update([
                'consulta' => $data['consulta'],
                'data_atualizacao' => $dt,
                'saldo' => (string) $data['saldo'],
                'libera' => (string) $data['libera'],
            ]);

            /* ---------- Pivot lead_imports ---------- */
            $lead->importJobs()
                ->attach($this->importJob->id, ['action' => 'update']);

        } catch (ValidationException $e) {
            foreach ($e->errors() as $col => $msgs) {
                ImportError::create([
                    'import_job_id' => $this->importJob->id,
                    'row_number' => $this->getRowNumber(),
                    'column_name' => $col,
                    'error_message' => implode(', ', $msgs),
                ]);
            }
        } catch (\Exception $e) {
            $col = $e->getPrevious()
                ? $e->getPrevious()->getMessage()
                : 'Geral';
            ImportError::create([
                'import_job_id' => $this->importJob->id,
                'row_number' => $this->getRowNumber(),
                'column_name' => $col,
                'error_message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    /**
     * Transforma a data/hora da planilha (dd/mm/aaaa hh:mm:ss ou serial do Excel)
     * garantindo que, se vier em fuso de Brasília, seja convertido para UTC antes de salvar.
     *
     * @param  mixed  $value
     * @return string|null  formato "Y-m-d H:i:s" em UTC, ou null se inválido
     */
    private function transformDateTime($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                // 1) Converte o serial do Excel num DateTime
                $phpDate = Date::excelToDateTimeObject($value);

                // 2) Formata em string d/m/Y H:i:s
                $brString = $phpDate->format('d/m/Y H:i:s');

                // 3) Reparsea como BRT, “rotulando” sem alterar hora
                $carbon = Carbon::createFromFormat(
                    'd/m/Y H:i:s',
                    $brString,
                    new \DateTimeZone('America/Sao_Paulo')
                );
            } else {
                // string no formato "dd/mm/yyyy hh:mm:ss", já em BRT
                $carbon = Carbon::createFromFormat(
                    'd/m/Y H:i:s',
                    trim($value),
                    new \DateTimeZone('America/Sao_Paulo')
                );
            }

            // 4) Converte finalmente para UTC e retorna
            return $carbon
                ->setTimezone('UTC')
                ->format('Y-m-d H:i:s');

        } catch (\Exception $e) {
            return null;
        }
    }

    public function registerEvents(): array
    {
        return [
            AfterChunk::class => function () {
                $this->importJob->refresh();

                $remaining = $this->importJob->total_rows
                    - $this->importJob->processed_rows;

                if ($remaining <= 0) {
                    return;
                }

                $increment = min($this->chunkSize(), $remaining);

                $this->importJob->increment(
                    'processed_rows',
                    $increment
                );
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
