<?php

namespace App\Http\Controllers\Api;

use App\Jobs\RollbackLastImportJob;
use App\Models\ImportJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\RollbackService;
class RollbackController extends Controller
{
    /**
     * Inicia rollback do último import.
     */
    public function store(Request $request, int $jobId): JsonResponse
    {
        $job = ImportJob::findOrFail($jobId);

        // validação inline em vez de Policy:
        if ($job->rolled_back_at !== null) {
            return response()->json([
                'error' => 'Este job já foi revertido.'
            ], 422);
        }
        if ($job->status !== 'concluido') {
            return response()->json([
                'error' => 'Somente jobs concluídos podem ser revertidos.'
            ], 422);
        }
        // Verifica se é o último concluído
        // Tem que ser o registro mais novo na tabela:
        if ($job->id !== ImportJob::max('id')) {
            return response()->json([
                'error' => 'Somente o job mais recente pode ser revertido.'
            ], 403);
        }

        // executa rollback de forma síncrona (pode demorar!)
        (new RollbackService())->rollback($job);

        return response()->json([
            'message' => 'Rollback concluído com sucesso.',
        ], 200);
    }
}
