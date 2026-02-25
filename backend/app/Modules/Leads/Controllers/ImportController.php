<?php

namespace App\Modules\Leads\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ImportJob;
use App\Modules\Leads\Jobs\ProcessLeadImportJob;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\UploadedFile;

class ImportController extends Controller
{
    private function canAccessImportJob(Request $request, ImportJob $importJob): bool
    {
        return (int) $importJob->user_id === (int) $request->user()->id;
    }

    public function store(Request $request)
    {
        $mimes = array_values(array_filter((array) config('leads.import.mimes', ['xlsx', 'xls'])));
        if (empty($mimes)) {
            $mimes = ['xlsx', 'xls'];
        }

        $types = array_values(array_filter((array) config('leads.import.types', ['cadastral', 'higienizacao', 'clt'])));
        if (empty($types)) {
            $types = ['cadastral', 'higienizacao', 'clt'];
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:' . implode(',', $mimes)],
            'type' => ['required', 'string', Rule::in($types)],
            'origin' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];
        $type = $validated['type'];

        $lockName = (string) config('leads.import.lock.name', 'imports_mutex');
        $lockWaitSeconds = (int) config('leads.import.lock.wait_seconds', 5);

        $locked = DB::selectOne('SELECT GET_LOCK(?, ? ) AS l', [$lockName, $lockWaitSeconds]);
        if (!$locked || (int) $locked->l !== 1) {
            return response()->json([
                'message' => 'Outro processo de importação está iniciando. Tente novamente.'
            ], Response::HTTP_CONFLICT);
        }

        try {
            $inProgressStatuses = array_values(array_filter((array) config('leads.import.in_progress_statuses', ['pendente', 'em_progresso'])));
            if (empty($inProgressStatuses)) {
                $inProgressStatuses = ['pendente', 'em_progresso'];
            }

            $inProgress = ImportJob::whereIn('status', $inProgressStatuses)->exists();
            if ($inProgress) {
                return response()->json([
                    'message' => 'Já existe uma importação em andamento. Aguarde a conclusão antes de iniciar outra.'
                ], Response::HTTP_CONFLICT);
            }

            $path = $file->store(
                (string) config('leads.import.storage.directory', 'imports'),
                (string) config('leads.import.storage.disk', 'local')
            );

            $importJob = ImportJob::create([
                'user_id' => Auth::id(),
                'type' => $type,
                'origin' => $validated['origin'] ?? (string) config('leads.import.default_origin', 'Upload Padrão'),
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'status' => 'pendente',
                'total_rows' => 0,
                'processed_rows' => 0,
            ]);
        } finally {
            DB::select('SELECT RELEASE_LOCK(?) AS r', [$lockName]);
        }

        ProcessLeadImportJob::dispatch($importJob);

        return response()->json([
            'message' => 'Arquivo recebido. A importação será processada em segundo plano.',
            'job_id' => $importJob->id,
        ], Response::HTTP_ACCEPTED);
    }

    public function show(Request $request, ImportJob $importJob)
    {
        if (!$this->canAccessImportJob($request, $importJob)) {
            return response()->json([
                'message' => 'Você não tem permissão para acessar esta importação.',
            ], Response::HTTP_FORBIDDEN);
        }

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
        $scope = strtolower((string) $request->query('scope', 'mine'));
        $scope = in_array($scope, ['mine', 'all'], true) ? $scope : 'mine';

        $statuses = $request->query('status');

        $query = ImportJob::query()
            ->orderByDesc('id')
            ->withCount('errors')
            ->with('user:id,name');

        if ($scope === 'mine') {
            $query->where('user_id', $request->user()->id);
        }

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

    public function errors(Request $request, ImportJob $importJob)
    {
        if (!$this->canAccessImportJob($request, $importJob)) {
            return response()->json([
                'message' => 'Você não tem permissão para acessar esta importação.',
            ], Response::HTTP_FORBIDDEN);
        }

        $errors = $importJob->errors()->get(['id', 'row_number', 'column_name', 'error_message']);
        return response()->json($errors);
    }

    public function exportErrors(Request $request, ImportJob $importJob)
    {
        if (!$this->canAccessImportJob($request, $importJob)) {
            return response()->json([
                'message' => 'Você não tem permissão para acessar esta importação.',
            ], Response::HTTP_FORBIDDEN);
        }

        $filename = "importacao_{$importJob->id}_erros.csv";

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
