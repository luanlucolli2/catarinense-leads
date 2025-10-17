<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ImportJob;
use App\Jobs\ProcessLeadImportJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\UploadedFile;

class ImportController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
            'type' => ['required', 'string', 'in:cadastral,higienizacao,clt'],
            'origin' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $type = $validated['type'];

        $locked = DB::selectOne('SELECT GET_LOCK(?, ? ) AS l', ['imports_mutex', 5]);
        if (!$locked || (int) $locked->l !== 1) {
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
                'user_id' => Auth::id(),
                'type' => $type,
                'origin' => $validated['origin'] ?? 'Upload Padrão',
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'status' => 'pendente',
                'total_rows' => 0,
                'processed_rows' => 0,
            ]);
        } finally {
            DB::select('SELECT RELEASE_LOCK(?) AS r', ['imports_mutex']);
        }

        ProcessLeadImportJob::dispatch($importJob);

        return response()->json([
            'message' => 'Arquivo recebido. A importação será processada em segundo plano.',
            'job_id' => $importJob->id,
        ], Response::HTTP_ACCEPTED);
    }

    public function show(ImportJob $importJob)
    {
        $errors = $importJob->errors()->count();

        $percent = $importJob->total_rows
            ? (int) floor($importJob->processed_rows / max($importJob->total_rows, 1) * 100)
            : 0;

        return response()->json([
            'status' => $importJob->status,
            'processed_rows' => (int) $importJob->processed_rows,
            'total_rows' => (int) $importJob->total_rows,
            'percent' => min($percent, 100),
            'errors' => $errors,
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
}
