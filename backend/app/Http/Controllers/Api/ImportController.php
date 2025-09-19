<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ImportJob;
use App\Jobs\ProcessLeadImportJob;
use App\Imports\CadastralImport;
use App\Imports\HigienizacaoImport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\HeadingRowImport;
use Maatwebsite\Excel\Excel as ExcelReaderType;

class ImportController extends Controller
{
    public function store(Request $request)
    {
        // 0) Mutex simples
        $inProgress = ImportJob::whereIn('status', ['pendente', 'em_progresso'])->exists();
        if ($inProgress) {
            return response()->json([
                'message' => 'Já existe uma importação em andamento. Aguarde a conclusão antes de iniciar outra.'
            ], Response::HTTP_CONFLICT);
        }

        // 1) Validação
        $validated = $request->validate([
            'file'   => ['required', 'file', 'mimes:xlsx,xls'],
            'type'   => ['required', 'string', 'in:cadastral,higienizacao'],
            'origin' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $type = $validated['type'];

        // 2) Validação de cabeçalhos
        $importerClass   = $type === 'cadastral' ? CadastralImport::class : HigienizacaoImport::class;
        $requiredHeaders = $importerClass::REQUIRED_HEADERS;

        $missing = $this->getMissingHeadersFromFile($file, $requiredHeaders);
        if (!empty($missing)) {
            return response()->json([
                'message' => 'Planilha inválida. Cabeçalhos ausentes.',
                'missing' => $missing,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 3) Contagem de linhas reais (baseada na coluna CPF)
        try {
            $ext = strtolower($file->getClientOriginalExtension());
            $readerName = $ext === 'xls' ? 'Xls' : 'Xlsx';

            $reader = IOFactory::createReader($readerName);
            $reader->setReadDataOnly(true);

            $spreadsheet = $reader->load($file->getPathname());
            $sheet = $spreadsheet->getSheet(0);

            // encontra o índice da coluna do "cpfcliente" pela linha de cabeçalho (linha 1 pelo WithHeadingRow)
            $headerRow = 1;
            $highestColIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            $cpfColIndex = null;

            for ($col = 1; $col <= $highestColIndex; $col++) {
                $val = $sheet->getCellByColumnAndRow($col, $headerRow)->getValue();
                $slug = is_string($val) ? Str::slug($val, '_') : '';
                if ($slug === 'cpfcliente') {
                    $cpfColIndex = $col;
                    break;
                }
            }

            // fallback seguro se não achar (não deve acontecer pois já validamos headers)
            if ($cpfColIndex === null) {
                $totalRows = max($sheet->getHighestDataRow() - 1, 0);
            } else {
                $highestRow = $sheet->getHighestDataRow();
                $count = 0;
                for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
                    $v = $sheet->getCellByColumnAndRow($cpfColIndex, $row)->getValue();
                    if ($v === null) {
                        continue;
                    }
                    $str = trim(is_string($v) ? $v : (string) $v);
                    // considera real apenas se tem dígitos (evita espaços/formatos vazios)
                    $digits = preg_replace('/\D+/', '', $str);
                    if ($digits !== '') {
                        $count++;
                    }
                }
                $totalRows = $count;
            }

            unset($spreadsheet, $sheet);
        } catch (ReaderException $e) {
            return response()->json([
                'message' => 'Não foi possível ler o arquivo. Verifique se o formato é válido e se não está corrompido.'
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // 4) Salva arquivo SEMPRE no disco 'local' e cria ImportJob
        $path = $file->store('imports', 'local');

        $importJob = ImportJob::create([
            'user_id'        => Auth::id(),
            'type'           => $type,
            'origin'         => $validated['origin'] ?? 'Upload Padrão',
            'file_name'      => $file->getClientOriginalName(),
            'file_path'      => $path,
            'status'         => 'pendente',
            'total_rows'     => (int) $totalRows,
            'processed_rows' => 0,
        ]);

        // 5) Dispara o processamento
        ProcessLeadImportJob::dispatch($importJob);

        // 6) 202
        return response()->json([
            'message' => 'Arquivo recebido. A importação será processada em segundo plano.',
            'job_id'  => $importJob->id,
        ], Response::HTTP_ACCEPTED);
    }

    public function show(ImportJob $importJob)
    {
        $errors = $importJob->errors()->count();

        $percent = $importJob->total_rows
            ? (int) floor($importJob->processed_rows / $importJob->total_rows * 100)
            : 0;

        return response()->json([
            'status'          => $importJob->status,
            'processed_rows'  => (int) $importJob->processed_rows,
            'total_rows'      => (int) $importJob->total_rows,
            'percent'         => $percent,
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

    /**
     * Lê os headers com HeadingRowImport, informando explicitamente o reader type (XLSX/XLS).
     */
    private function getMissingHeadersFromFile(UploadedFile $file, array $requiredHeaders): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $readerType = $ext === 'xls' ? ExcelReaderType::XLS : ExcelReaderType::XLSX;

        $arrays = (new HeadingRowImport)->toArray($file->getPathname(), null, $readerType);
        $present = array_map(
            fn ($h) => is_string($h) ? Str::slug($h, '_') : $h,
            ($arrays[0][0] ?? [])
        );

        $normalizedRequired = array_map(
            fn ($h) => Str::slug($h, '_'),
            $requiredHeaders
        );

        $missing = [];
        foreach ($normalizedRequired as $i => $slugReq) {
            if (!in_array($slugReq, $present, true)) {
                $missing[] = $requiredHeaders[$i];
            }
        }

        return $missing;
    }
}
