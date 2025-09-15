<?php

namespace App\Imports;

use App\Models\Lead;
use App\Models\ImportJob;
use App\Models\ImportError;
use App\Services\BackupService;
use App\Support\Cpf; // ✅ passa a usar o helper de CPF
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
use Illuminate\Support\Facades\DB; // ✅ para updates atômicos e pivot idempotente

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

    public function __construct(ImportJob $importJob, BackupService $backup)
    {
        $this->importJob = $importJob;
        $this->backup    = $backup;
    }

    public function model(array $row)
    {
        try {
            // 1) Validação dos campos de entrada
            $validator = Validator::make($row, [
                'cpfcliente'     => ['required'],
                'consulta'       => ['required', 'string'],
                'dataatualizacao'=> ['required'],
                'saldo'          => ['required'],
                'libera'         => ['required'],
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $data = $validator->validated();

            // 2) CPF: normaliza (zeros à esquerda) e valida DV com App\Support\Cpf
            $cpf = Cpf::normalize($data['cpfcliente'] ?? null);
            if (!$cpf || !Cpf::isValid($cpf)) {
                throw new \Exception(
                    "CPF inválido.",
                    0,
                    new \Exception('cpfcliente')
                );
            }

            // 3) Lead deve existir (higienização só atualiza)
            $lead = Lead::where('cpf', $cpf)->first();
            if (!$lead) {
                throw new \Exception(
                    "Lead com CPF não encontrado na base de dados.",
                    0,
                    new \Exception('cpfcliente')
                );
            }

            // 4) Backup antes de atualizar (snapshot único por import/lead)
            $alreadyBackedUp = \App\Models\Backup\LeadBackup::where('lead_id', $lead->id)
                ->where('import_job_id', $this->importJob->id)
                ->exists();

            if (!$alreadyBackedUp) {
                $this->backup->bulkBackupLeads(collect([$lead]), $this->importJob);
            }

            // 5) Data/hora: aceita “dd/mm/aaaa hh:mm:ss” ou serial Excel; converte BRT→UTC
            $dt = $this->transformDateTime($data['dataatualizacao']);
            if (!$dt) {
                throw new \Exception(
                    "Formato de data inválido. Use dd/mm/aaaa hh:mm:ss.",
                    0,
                    new \Exception('dataatualizacao')
                );
            }

            // 6) Atualização do Lead
            $lead->update([
                'consulta'         => $data['consulta'],
                'data_atualizacao' => $dt,
                'saldo'            => (string) $data['saldo'],
                'libera'           => (string) $data['libera'],
            ]);

            // 7) Pivot lead_imports idempotente (evita duplicate key e não usa updated_at)
            DB::table('lead_imports')->insertOrIgnore([[
                'lead_id'       => $lead->id,
                'import_job_id' => $this->importJob->id,
                'action'        => 'update',
                'created_at'    => now(),
            ]]);

        } catch (ValidationException $e) {
            foreach ($e->errors() as $col => $msgs) {
                ImportError::create([
                    'import_job_id' => $this->importJob->id,
                    'row_number'    => $this->getRowNumber(),
                    'column_name'   => $col,
                    'error_message' => implode(', ', $msgs),
                ]);
            }
        } catch (\Exception $e) {
            $col = $e->getPrevious()
                ? $e->getPrevious()->getMessage()
                : 'Geral';
            ImportError::create([
                'import_job_id' => $this->importJob->id,
                'row_number'    => $this->getRowNumber(),
                'column_name'   => $col,
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
     * interpretando em America/Sao_Paulo e persistindo em UTC ("Y-m-d H:i:s").
     */
    private function transformDateTime($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                // 1) Converte serial para DateTime nativo
                $phpDate = Date::excelToDateTimeObject($value);
                // 2) Formata temporariamente
                $brString = $phpDate->format('d/m/Y H:i:s');
                // 3) Reparse como BRT
                $carbon = Carbon::createFromFormat(
                    'd/m/Y H:i:s',
                    $brString,
                    new \DateTimeZone('America/Sao_Paulo')
                );
            } else {
                // String no formato "dd/mm/yyyy hh:mm:ss", já em BRT
                $carbon = Carbon::createFromFormat(
                    'd/m/Y H:i:s',
                    trim($value),
                    new \DateTimeZone('America/Sao_Paulo')
                );
            }

            // 4) Converte para UTC
            return $carbon->setTimezone('UTC')->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    public function registerEvents(): array
    {
        return [
            // Incremento atômico e capado para evitar percent > 100% com múltiplos workers
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
}
