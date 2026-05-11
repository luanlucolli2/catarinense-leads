<?php

namespace App\Modules\Leads\Jobs;

use App\Models\ImportJob;
use App\Models\ImportError;
use App\Modules\Leads\Imports\CadastralImport;
use App\Modules\Leads\Imports\HigienizacaoImport;
use App\Modules\Leads\Imports\CltSnapshotImport;
use App\Modules\Leads\Imports\MercantilCsvImport;
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
        // Forca o job a ir para a fila configurada para importacao de leads
        $this->onQueue((string) config('leads.import.queue', 'imports'));
    }

    public function handle(): void
    {
        $this->importJob->refresh();
        if ($this->importJob->status === 'cancelado') {
            return;
        }

        $this->importJob->update([
            'status'     => 'em_progresso',
            'started_at' => $this->importJob->started_at ?? now(),
        ]);
        $this->importJob->refresh();
        if ($this->importJob->status === 'cancelado') {
            return;
        }

        // caminho relativo salvo no job (ex.: "imports/abc.xlsx")
        $uploadPath = $this->importJob->file_path;

        try {
            $primaryDisk = (string) config('leads.import.storage.disk', 'local');
            $fallbackDisks = array_values(array_filter((array) config('leads.import.storage.fallback_disks', ['public'])));

            $disk = $primaryDisk;
            $path = $this->importJob->file_path;

            $exists = Storage::disk($disk)->exists($path);
            $fullPath = $exists ? Storage::disk($disk)->path($path) : null;

            if (!$exists) {
                foreach ($fallbackDisks as $fallbackDisk) {
                    if ($fallbackDisk === '' || $fallbackDisk === $disk) {
                        continue;
                    }

                    if (Storage::disk($fallbackDisk)->exists($path)) {
                        $disk = $fallbackDisk;
                        $fullPath = Storage::disk($fallbackDisk)->path($path);
                        $exists = true;
                        break;
                    }
                }
            }

            if (!$exists || !$fullPath || !is_file($fullPath) || !is_readable($fullPath)) {
                throw new \RuntimeException('Arquivo de importação não encontrado ou ilegível: ' . ($fullPath ?? $path));
            }

            $type = $this->importJob->type;

            if ($type === 'mercantil') {
                [$missing, $totalRows] = $this->inspectMercantilCsv($fullPath);

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

                if ($totalRows > 0 && (int) $this->importJob->total_rows !== (int) $totalRows) {
                    $this->importJob->update(['total_rows' => (int) $totalRows]);
                }

                (new MercantilCsvImport($this->importJob))->process($fullPath);
                return;
            }

            if ($type === 'cadastral' || $type === 'higienizacao') {
                [$missing, $totalRows] = $this->inspectLeadCsv($fullPath, $type);

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

                if ($totalRows > 0 && (int) $this->importJob->total_rows !== (int) $totalRows) {
                    $this->importJob->update(['total_rows' => (int) $totalRows]);
                }

                $importer = $type === 'cadastral'
                    ? new CadastralImport($this->importJob, app(\App\Services\BackupService::class))
                    : new HigienizacaoImport($this->importJob, app(\App\Services\BackupService::class));

                $importer->process($fullPath);
                return;
            }

            // pré-validação leve (Excel)
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

            // Contagem prévia é opcional (evita uma passagem extra no arquivo em ambientes restritos).
            if ($this->shouldPreCountTotalRows($type)) {
                try {
                    $totalRows = $this->quickTotalRows($fullPath);
                    if ($totalRows > 0 && (int) $this->importJob->total_rows !== (int) $totalRows) {
                        $this->importJob->update(['total_rows' => (int) $totalRows]);
                    }
                } catch (ReaderException $e) {
                }
            }

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
            $this->importJob->refresh();
            if ($this->importJob->status === 'cancelado') {
                return;
            }

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

    private function shouldPreCountTotalRows(string $type): bool
    {
        if ($type === 'clt') {
            return true;
        }

        return (bool) config('leads.import.pre_count_total_rows', true);
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

    /**
     * @return array{0: array<int, string>, 1: int}
     */
    private function inspectMercantilCsv(string $fullPath): array
    {
        $handle = fopen($fullPath, 'rb');
        if (!$handle) {
            throw new \RuntimeException("Não foi possível abrir o CSV Mercantil: {$fullPath}");
        }

        try {
            $delimiter = (string) config('leads.import.mercantil.csv.delimiter', ';');
            $enclosure = (string) config('leads.import.mercantil.csv.enclosure', '"');
            $delimiter = $delimiter !== '' ? $delimiter[0] : ';';
            $enclosure = $enclosure !== '' ? $enclosure[0] : '"';

            $headers = fgetcsv($handle, 0, $delimiter, $enclosure);
            if ($headers === false) {
                return [MercantilCsvImport::requiredFieldLabels(), 0];
            }

            $missing = MercantilCsvImport::missingRequiredFields($headers);

            $totalRows = 0;
            while (($row = fgetcsv($handle, 0, $delimiter, $enclosure)) !== false) {
                if ($this->isCsvRowEmpty($row)) {
                    continue;
                }

                $totalRows++;
            }

            return [$missing, $totalRows];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{0: array<int, string>, 1: int}
     */
    private function inspectLeadCsv(string $fullPath, string $type): array
    {
        $handle = fopen($fullPath, 'rb');
        if (!$handle) {
            throw new \RuntimeException("Não foi possível abrir o CSV de importação: {$fullPath}");
        }

        try {
            $delimiter = $this->csvDelimiter();
            $enclosure = $this->csvEnclosure();

            $headers = fgetcsv($handle, 0, $delimiter, $enclosure);
            if ($headers === false) {
                $required = $type === 'cadastral'
                    ? CadastralImport::REQUIRED_HEADERS
                    : HigienizacaoImport::REQUIRED_HEADERS;
                return [$required, 0];
            }

            $missing = $type === 'cadastral'
                ? CadastralImport::missingRequiredHeaders($headers)
                : HigienizacaoImport::missingRequiredHeaders($headers);

            $totalRows = 0;
            while (($row = fgetcsv($handle, 0, $delimiter, $enclosure)) !== false) {
                if ($this->isCsvRowEmpty($row)) {
                    continue;
                }
                $totalRows++;
            }

            return [$missing, $totalRows];
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array<int, string> $row
     */
    private function isCsvRowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function csvDelimiter(): string
    {
        $configured = (string) config('leads.import.csv.delimiter', ';');
        return $configured !== '' ? $configured[0] : ';';
    }

    private function csvEnclosure(): string
    {
        $configured = (string) config('leads.import.csv.enclosure', '"');
        return $configured !== '' ? $configured[0] : '"';
    }

    private function deleteUploadedFile(?string $relativePath): void
    {
        if (!$relativePath) return;

        $primaryDisk = (string) config('leads.import.storage.disk', 'local');
        $fallbackDisks = array_values(array_filter((array) config('leads.import.storage.fallback_disks', ['public'])));
        $disks = array_values(array_unique(array_filter(array_merge([$primaryDisk], $fallbackDisks))));
        if (empty($disks)) {
            $disks = ['local', 'public'];
        }

        // tenta apagar no disco principal e nos discos de fallback
        foreach ($disks as $disk) {
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
