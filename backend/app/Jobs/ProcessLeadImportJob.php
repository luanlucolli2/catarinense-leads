<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Models\ImportError;
use App\Imports\CadastralImport;
use App\Imports\HigienizacaoImport;
use App\Imports\CltSnapshotImport;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Excel as ExcelReaderType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;

class ProcessLeadImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ImportJob $importJob;

    public function __construct(ImportJob $importJob)
    {
        $this->importJob = $importJob;
        // ✅ Força o job a ir para a fila 'imports'
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $this->importJob->update([
            'status'     => 'em_progresso',
            'started_at' => now(),
        ]);

        // caminho relativo salvo no job (ex.: "imports/abc.xlsx")
        $uploadPath = $this->importJob->file_path;

        try {
            $disk = 'local';
            $path = $this->importJob->file_path;

            $exists = Storage::disk($disk)->exists($path);
            $fullPath = $exists ? Storage::disk($disk)->path($path) : null;

            if (!$exists) {
                if (Storage::disk('public')->exists($path)) {
                    $disk = 'public';
                    $fullPath = Storage::disk('public')->path($path);
                    $exists = true;
                }
            }

            if (!$exists || !$fullPath || !is_file($fullPath) || !is_readable($fullPath)) {
                throw new \RuntimeException('Arquivo de importação não encontrado ou ilegível: ' . ($fullPath ?? $path));
            }

            $type = $this->importJob->type;

            // pré-validação leve
            if ($type !== 'clt') {
                $requiredHeaders = ($type === 'cadastral' ? CadastralImport::REQUIRED_HEADERS : HigienizacaoImport::REQUIRED_HEADERS);
                $headers = $this->readHeaders($fullPath);
                $missing = $this->diffMissingHeadersIndexed($headers, $requiredHeaders);
                if (!empty($missing)) {
                    foreach ($missing as $h) {
                        ImportError::create([
                            'import_job_id' => $this->importJob->id,
                            'row_number'    => 1,
                            'column_name'   => $h,
                            'error_message' => 'Cabeçalho ausente.',
                        ]);
                    }
                    $this->importJob->update([
                        'status'      => 'falhou',
                        'finished_at' => now(),
                    ]);
                    return;
                }
            }

            // total de linhas estimado
            try {
                $totalRows = $this->quickTotalRows($fullPath);
                if ($totalRows > 0 && (int)$this->importJob->total_rows !== (int)$totalRows) {
                    $this->importJob->update(['total_rows' => (int)$totalRows]);
                }
            } catch (ReaderException $e) {}

            // importar
            $importer = match ($type) {
                'cadastral'    => new CadastralImport($this->importJob, app(\App\Services\BackupService::class)),
                'higienizacao' => new HigienizacaoImport($this->importJob, app(\App\Services\BackupService::class)),
                'clt'          => new CltSnapshotImport($this->importJob),
                default        => throw new \InvalidArgumentException("Tipo de import não suportado: {$type}"),
            };

            $ext = strtolower(pathinfo($this->importJob->file_name ?? $fullPath, PATHINFO_EXTENSION));
            $readerType = $ext === 'xls' ? ExcelReaderType::XLS : ExcelReaderType::XLSX;

            Excel::import($importer, $fullPath, null, $readerType);

        } catch (Throwable $e) {
            $this->importJob->update([
                'status'      => 'falhou',
                'finished_at' => now(),
            ]);
            Log::error("Falha na importação do Job ID {$this->importJob->id}", ['exception' => $e]);
        } finally {
            // apaga o arquivo enviado, independente de sucesso ou falha
            try {
                $this->deleteUploadedFile($uploadPath);
            } catch (Throwable $t) {
                Log::warning("Não foi possível apagar o arquivo do Job ID {$this->importJob->id}", ['path' => $uploadPath, 'exception' => $t]);
            }
        }
    }

    private function readHeaders(string $fullPath): array
    {
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $readerName = $ext === 'xls' ? 'Xls' : 'Xlsx';

        $reader = IOFactory::createReader($readerName);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new HeaderRowReadFilter(1));

        $spreadsheet = $reader->load($fullPath);
        $sheet = $spreadsheet->getSheet(0);

        $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $headers = [];
        for ($col = 1; $col <= $highestColIndex; $col++) {
            $val = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            $headers[$col] = is_string($val) ? \Illuminate\Support\Str::slug($val, '_') : '';
        }

        $spreadsheet->disconnectWorksheets();
        unset($sheet, $spreadsheet);

        return $headers;
    }

    private function quickTotalRows(string $fullPath): int
    {
        $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $readerInfo = $ext === 'xls'
            ? new \PhpOffice\PhpSpreadsheet\Reader\Xls()
            : new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();

        $info = $readerInfo->listWorksheetInfo($fullPath);
        return max((int)(($info[0]['totalRows'] ?? 1) - 1), 0);
    }

    private function diffMissingHeadersIndexed(array $presentByIndex, array $requiredOriginal): array
    {
        $present = array_values($presentByIndex);
        $normalizedRequired = array_map(fn ($h) => \Illuminate\Support\Str::slug($h, '_'), $requiredOriginal);

        $missing = [];
        foreach ($normalizedRequired as $i => $slugReq) {
            if (!in_array($slugReq, $present, true)) {
                $missing[] = $requiredOriginal[$i];
            }
        }
        return $missing;
    }

    private function deleteUploadedFile(?string $relativePath): void
    {
        if (!$relativePath) return;

        // tenta nos discos "local" e "public"
        foreach (['local', 'public'] as $disk) {
            try {
                if (Storage::disk($disk)->exists($relativePath)) {
                    Storage::disk($disk)->delete($relativePath);
                }
            } catch (Throwable $t) {
                // continua tentando nos demais discos
            }
        }
    }
}

/* ReadFilter inalterado */
class HeaderRowReadFilter implements IReadFilter
{
    private int $row;
    public function __construct(int $row = 1) { $this->row = $row; }
    public function readCell($column, $row, $worksheetName = '') { return $row === $this->row; }
}