<?php

namespace App\Modules\Leads\Controllers;

use App\Models\ImportJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Modules\Leads\Services\RollbackService;
class RollbackController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const ROLLBACK_ELIGIBLE_TYPES = ['cadastral', 'higienizacao'];

    /**
     * Inicia rollback do último import.
     */
    public function store(Request $request, int $jobId): JsonResponse
    {
        $job = ImportJob::findOrFail($jobId);

        if ((int) $job->user_id !== (int) $request->user()->id) {
            return response()->json([
                'error' => 'Você não tem permissão para reverter esta importação.'
            ], 403);
        }

        // validação inline em vez de Policy:
        if ($job->rolled_back_at !== null) {
            return response()->json([
                'error' => 'Esta importação já foi revertida.'
            ], 422);
        }
        if ($job->status !== 'concluido') {
            return response()->json([
                'error' => 'Somente importações concluídas podem ser revertidas.'
            ], 422);
        }
        if (!in_array((string) $job->type, self::ROLLBACK_ELIGIBLE_TYPES, true)) {
            return response()->json([
                'error' => 'Somente importações cadastrais e de higienização participam de rollback.'
            ], 422);
        }

        $lastEligibleId = ImportJob::query()
            ->whereIn('type', self::ROLLBACK_ELIGIBLE_TYPES)
            ->where('status', 'concluido')
            ->whereNull('rolled_back_at')
            ->max('id');

        if ($lastEligibleId === null || (int) $job->id !== (int) $lastEligibleId) {
            return response()->json([
                'error' => 'Somente a importação cadastral/higienização concluída mais recente pode ser revertida.'
            ], 403);
        }

        // executa rollback de forma síncrona (pode demorar!)
        (new RollbackService())->rollback($job);

        return response()->json([
            'message' => 'Rollback concluído com sucesso.',
        ], 200);
    }
}
