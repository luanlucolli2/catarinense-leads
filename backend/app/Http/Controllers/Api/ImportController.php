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
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\UploadedFile;

class ImportController extends Controller
{
    /* -----------------------------------------------------------------------
     |  POST /import  – envia arquivo e cria o Job
     |-----------------------------------------------------------------------*/
    public function store(Request $request)
    {
        /* 1. Validação da requisição */
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
            'type' => ['required', 'string', 'in:cadastral,higienizacao'],
            'origin' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $type = $validated['type'];

        /* 2. Validação de cabeçalhos */
        $importerClass = $type === 'cadastral' ? CadastralImport::class : HigienizacaoImport::class;
        $requiredHeaders = $importerClass::REQUIRED_HEADERS;

        $missing = $this->missingHeaders($file, $requiredHeaders);
        if ($missing) {
            return response()->json([
                'message' => 'Planilha inválida. Cabeçalhos ausentes.',
                'missing' => $missing,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /* 3. Conta linhas de dados (para barra de progresso) */
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $totalRows = max($sheet->getHighestDataRow() - 1, 0); // -1 = cabeçalho

        /* 4. Armazena arquivo e cria ImportJob */
        $path = $file->store('imports');

        $importJob = ImportJob::create([
            'user_id' => Auth::id(),
            'type' => $type,
            'origin' => $validated['origin'] ?? 'Upload Padrão',
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'status' => 'pendente',
            'total_rows' => $totalRows,
            'processed_rows' => 0,
        ]);

        /* 5. Despacha o job para a fila */
        ProcessLeadImportJob::dispatch($importJob);

        /* 6. Resposta 202 Accepted */
        return response()->json([
            'message' => 'Arquivo recebido. A importação será processada em segundo plano.',
            'job_id' => $importJob->id,
        ], Response::HTTP_ACCEPTED);
    }

    /* -----------------------------------------------------------------------
     |  GET /import/{id} – retorna progresso em tempo real
     |-----------------------------------------------------------------------*/
    public function show(ImportJob $importJob)
    {
        $errors = $importJob->errors()->count();

        $percent = $importJob->total_rows
            ? (int) floor($importJob->processed_rows / $importJob->total_rows * 100)
            : 0;

        return response()->json([
            'status' => $importJob->status,          // agora vem 'pendente', 'em_progresso', ...
            'processed_rows' => (int) $importJob->processed_rows,
            'total_rows' => (int) $importJob->total_rows,
            'percent' => $percent,
            'errors' => $errors,
        ]);
    }

    /* -----------------------------------------------------------------------
     |  Helpers
     |-----------------------------------------------------------------------*/
    /**
     * Retorna um array de cabeçalhos ausentes; vazio se todos presentes.
     */
    private function missingHeaders(UploadedFile $file, array $requiredHeaders): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $firstRow = $sheet->rangeToArray(
            'A1:' . $sheet->getHighestColumn() . '1',
            null,
            true,
            false
        )[0];

        $normalize = fn($v) => Str::of($v)->trim()->lower()->replace(' ', '')->value();
        $present = array_map($normalize, $firstRow);

        $missing = [];
        foreach ($requiredHeaders as $h) {
            if (!in_array($normalize($h), $present, true)) {
                $missing[] = $h;
            }
        }

        return $missing;
    }

    public function index(Request $request)
    {
        $statuses = $request->query('status'); // ex: "pendente,em_progresso"

        $query = ImportJob::where('user_id', $request->user()->id)
            ->orderByDesc('id');

        if ($statuses) {
            $query->whereIn('status', explode(',', $statuses));
        }

        $jobs = $query->get([
            'id',
            'status',
            'processed_rows',
            'total_rows',
        ]);

        return response()->json($jobs);
    }
}
