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

        // 1) Cabeçalhos (linha 1) – barato
        $headers = $this->readHeaders($file->getPathname(), $readerName);
        $requiredHeaders = ($type === 'cadastral' ? CadastralImport::REQUIRED_HEADERS : HigienizacaoImport::REQUIRED_HEADERS);
        $missing = $this->diffMissingHeaders($headers, $requiredHeaders);
        if (!empty($missing)) {
            return response()->json([
                'message' => 'Planilha inválida. Cabeçalhos ausentes.',
                'missing' => $missing,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 2) Estimar total de linhas de forma leve (sem varrer a coluna)
        try {
            $totalRows = $this->quickTotalRows($file->getPathname(), $readerName);
        } catch (ReaderException $e) {
            return response()->json([
                'message' => 'Não foi possível ler o arquivo. Verifique se o formato é válido e se não está corrompido.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 3) Mutex atômico
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
                // usa estimativa leve; o progresso é atualizado chunk a chunk pelo import
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

    /* ===================== helpers ===================== */

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

    /** Contagem leve: pega total de linhas da planilha e subtrai o header. */
    private function quickTotalRows(string $fullPath, string $readerName): int
    {
        $readerInfo = $readerName === 'Xls'
            ? new \PhpOffice\PhpSpreadsheet\Reader\Xls()
            : new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();

        $info = $readerInfo->listWorksheetInfo($fullPath);
        $total = max((int) (($info[0]['totalRows'] ?? 1) - 1), 0);
        return $total;
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
