<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ImportJob;
use App\Jobs\ProcessLeadImportJob;
use App\Imports\CadastralImport;
use App\Imports\HigienizacaoImport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelReaderType;

class ImportController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'file'   => ['required', 'file', 'mimes:xlsx,xls'],
            'type'   => ['required', 'string', 'in:cadastral,higienizacao'],
            'origin' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $type = $validated['type'];
        $ext  = strtolower($file->getClientOriginalExtension());
        $readerName = $ext === 'xls' ? 'Xls' : 'Xlsx';

        // 2) Cabeçalhos (linha 1)
        $headers = $this->readHeaders($file->getPathname(), $readerName);
        $requiredHeaders = ($type === 'cadastral' ? CadastralImport::REQUIRED_HEADERS : HigienizacaoImport::REQUIRED_HEADERS);
        $missing = $this->diffMissingHeaders($headers, $requiredHeaders);
        if (!empty($missing)) {
            return response()->json([
                'message' => 'Planilha inválida. Cabeçalhos ausentes.',
                'missing' => $missing,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 3) Conta linhas válidas por coluna CPF (streaming)
        try {
            $cpfColIndex = $this->findCpfColumnIndex($headers); // 1-based
            $totalRows   = $this->countRowsByCpfColumn($file->getPathname(), $readerName, $cpfColIndex);
        } catch (ReaderException $e) {
            return response()->json([
                'message' => 'Não foi possível ler o arquivo. Verifique se o formato é válido e se não está corrompido.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 4) Mutex atômico
        $locked = DB::selectOne('SELECT GET_LOCK(?, ? ) AS l', ['imports_mutex', 5]);
        if (!$locked || (int)$locked->l !== 1) {
            return response()->json([
                'message' => 'Outro processo de importação está iniciando. Tente novamente.'
            ], Response::HTTP_CONFLICT);
        }

        try {
            $inProgress = ImportJob::whereIn('status', ['pendente', 'em_progresso'])->exists();
            if ($inProgress) {
                return response()->json([
                    'message' => 'Já existe uma importação em andamento. Aguarde a conclusão antes de iniciar outra.'
                ], Response::HTTP_CONFLICT);
            }

            $path = $file->store('imports', 'local');

            $importJob = ImportJob::create([
                'user_id'        => Auth::id(),
                'type'           => $type,
                'origin'         => $validated['origin'] ?? 'Upload Padrão',
                'file_name'      => $file->getClientOriginalName(),
                'file_path'      => $path,
                'status'         => 'pendente',
                'total_rows'     => (int)$totalRows,
                'processed_rows' => 0,
            ]);
        } finally {
            DB::select('SELECT RELEASE_LOCK(?) AS r', ['imports_mutex']);
        }

        ProcessLeadImportJob::dispatch($importJob);

        return response()->json([
            'message' => 'Arquivo recebido. A importação será processada em segundo plano.',
            'job_id'  => $importJob->id,
        ], Response::HTTP_ACCEPTED);
    }

    public function show(ImportJob $importJob)
    {
        $errors = $importJob->errors()->count();

        $percent = $importJob->total_rows
            ? (int) floor($importJob->processed_rows / max($importJob->total_rows, 1) * 100)
            : 0;

        return response()->json([
            'status'          => $importJob->status,
            'processed_rows'  => (int) $importJob->processed_rows,
            'total_rows'      => (int) $importJob->total_rows,
            'percent'         => min($percent, 100),
            'errors'          => $errors,
        ]);
    }

    public function index(Request $request)
    {
        $statuses = $request->query('status');

        $query = ImportJob::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->withCount('errors')
            ->with('user:id,name');

        if ($statuses) {
            $query->whereIn('status', explode(',', $statuses));
        }

        $jobs = $query->get([
            'id',
            'type',
            'file_name',
            'origin',
            'status',
            'total_rows',
            'processed_rows',
            'started_at',
            'finished_at',
        ]);

        return response()->json($jobs);
    }

    public function errors(ImportJob $importJob)
    {
        $errors = $importJob->errors()->get(['id', 'row_number', 'column_name', 'error_message']);
        return response()->json($errors);
    }

    public function exportErrors(ImportJob $importJob)
    {
        $filename = "import_job_{$importJob->id}_errors.csv";

        return response()->streamDownload(function () use ($importJob) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Linha', 'Coluna', 'Mensagem do Erro']);
            foreach ($importJob->errors()->cursor() as $err) {
                fputcsv($handle, [$err->row_number, $err->column_name, $err->error_message]);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /* ===================== helpers de leitura streaming ===================== */

    private function readHeaders(string $fullPath, string $readerName): array
    {
        $reader = IOFactory::createReader($readerName);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new HeaderRowReadFilter(1));

        $spreadsheet = $reader->load($fullPath);
        $sheet = $spreadsheet->getSheet(0);

        $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
        $headers = [];
        for ($col = 1; $col <= $highestColIndex; $col++) {
            $val = $sheet->getCellByColumnAndRow($col, 1)->getValue();
            $headers[$col] = is_string($val) ? Str::slug($val, '_') : '';
        }

        $spreadsheet->disconnectWorksheets();
        unset($sheet, $spreadsheet);

        return $headers;
    }

    private function findCpfColumnIndex(array $headers): int
    {
        foreach ($headers as $idx => $slug) {
            if ($slug === 'cpfcliente') {
                return (int)$idx;
            }
        }
        return 0;
    }

    private function countRowsByCpfColumn(string $fullPath, string $readerName, int $cpfColIndex): int
    {
        if ($cpfColIndex <= 0) {
            // fallback: totalRows bruto - 1
            $readerInfo = $readerName === 'Xls'
                ? new \PhpOffice\PhpSpreadsheet\Reader\Xls()
                : new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $info = $readerInfo->listWorksheetInfo($fullPath);
            $total = max(($info[0]['totalRows'] ?? 1) - 1, 0);
            return (int)$total;
        }

        // pega highestRow barato
        $readerInfo = $readerName === 'Xls'
            ? new \PhpOffice\PhpSpreadsheet\Reader\Xls()
            : new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $info = $readerInfo->listWorksheetInfo($fullPath);
        $highestRow = (int)($info[0]['totalRows'] ?? 1);

        if ($highestRow <= 1) return 0;

        // lê apenas a coluna do CPF, das linhas 2..highestRow
        $reader = IOFactory::createReader($readerName);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new SingleColumnReadFilter($cpfColIndex, 2, $highestRow));

        $spreadsheet = $reader->load($fullPath);
        $sheet = $spreadsheet->getSheet(0);

        $count = 0;
        for ($row = 2; $row <= $highestRow; $row++) {
            $v = $sheet->getCellByColumnAndRow($cpfColIndex, $row)->getValue();
            if ($v === null) continue;
            $str = trim(is_string($v) ? $v : (string)$v);
            $digits = preg_replace('/\D+/', '', $str);
            if ($digits !== '') $count++;
        }

        $spreadsheet->disconnectWorksheets();
        unset($sheet, $spreadsheet);

        return $count;
    }

    private function diffMissingHeaders(array $presentByIndex, array $requiredOriginal): array
    {
        $present = array_values($presentByIndex);
        $normalizedRequired = array_map(fn ($h) => Str::slug($h, '_'), $requiredOriginal);

        $missing = [];
        foreach ($normalizedRequired as $i => $slugReq) {
            if (!in_array($slugReq, $present, true)) {
                $missing[] = $requiredOriginal[$i];
            }
        }
        return $missing;
    }
}

/* ===================== ReadFilters ===================== */

class HeaderRowReadFilter implements IReadFilter
{
    private int $row;

    public function __construct(int $row = 1) { $this->row = $row; }

    public function readCell($column, $row, $worksheetName = '')
    {
        return $row === $this->row;
    }
}

class SingleColumnReadFilter implements IReadFilter
{
    private int $colIndex1Based;
    private int $startRow;
    private int $endRow;

    public function __construct(int $colIndex1Based, int $startRow, int $endRow)
    {
        $this->colIndex1Based = $colIndex1Based;
        $this->startRow = $startRow;
        $this->endRow = $endRow;
    }

    public function readCell($column, $row, $worksheetName = '')
    {
        $idx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($column);
        return $idx === $this->colIndex1Based && $row >= $this->startRow && $row <= $this->endRow;
    }
}
