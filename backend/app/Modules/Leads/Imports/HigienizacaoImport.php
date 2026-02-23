<?php

namespace App\Modules\Leads\Imports;

use App\Models\Lead;
use App\Models\ImportJob;
use App\Models\ImportError;
use App\Services\BackupService;
use App\Support\Cpf;
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
use Maatwebsite\Excel\Events\BeforeImport;
use Illuminate\Support\Facades\DB;

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

    protected int $rowsInCurrentChunk = 0;

    public function __construct(ImportJob $importJob, BackupService $backup)
    {
        $this->importJob = $importJob;
        $this->backup    = $backup;
    }

    public function model(array $row)
    {
        // contador real por CPF com dígitos
        $cpfRaw = $row['cpfcliente'] ?? null;
        $digits = $cpfRaw !== null ? preg_replace('/\D+/', '', (string)$cpfRaw) : '';
        if ($digits === '') {
            return null;
        }
        $this->rowsInCurrentChunk++;

        try {
            // 1) validação
            $validator = Validator::make($row, [
                'cpfcliente'      => ['required'],
                'consulta'        => ['required', 'string'],
                'dataatualizacao' => ['required'],
                'saldo'           => ['required'],
                'libera'          => ['required'],
            ]);
            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $data = $validator->validated();

            // 2) CPF
            $cpf = Cpf::normalize($data['cpfcliente'] ?? null);
            if (!$cpf || !Cpf::isValid($cpf)) {
                throw new \Exception("CPF inválido.", 0, new \Exception('cpfcliente'));
            }

            // 3) Lead existente
            $lead = Lead::where('cpf', $cpf)->first();
            if (!$lead) {
                throw new \Exception("Lead com CPF não encontrado na base de dados.", 0, new \Exception('cpfcliente'));
            }

            // 4) backup antes de atualizar (snapshot único por import/lead)
            if (!$lead->backups()->where('import_job_id', $this->importJob->id)->exists()) {
                $this->backup->backupExistingLead($lead, $this->importJob);
            }

            // 5) data/hora
            $dt = $this->transformDateTime($data['dataatualizacao']);
            if (!$dt) {
                throw new \Exception("Formato de data inválido. Use dd/mm/aaaa hh:mm:ss.", 0, new \Exception('dataatualizacao'));
            }

            // 6) update Lead
            $lead->update([
                'consulta'         => $data['consulta'],
                'data_atualizacao' => $dt,
                'saldo'            => (string) $data['saldo'],
                'libera'           => (string) $data['libera'],
            ]);

            // 7) pivot idempotente
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
            $col = $e->getPrevious() ? $e->getPrevious()->getMessage() : 'Geral';
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
        return max(1, (int) config('leads.import.chunk_size', 1000));
    }

    private function transformDateTime($value): ?string
    {
        if (empty($value)) return null;

        try {
            if (is_numeric($value)) {
                $phpDate = Date::excelToDateTimeObject($value);
                $carbon  = Carbon::instance($phpDate)->setTimezone('America/Sao_Paulo');
            } else {
                $carbon = Carbon::createFromFormat('d/m/Y H:i:s', trim($value), 'America/Sao_Paulo');
            }
            return $carbon->clone()->setTimezone('UTC')->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    public function registerEvents(): array
    {
        return [
            BeforeImport::class => function () {
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
}
