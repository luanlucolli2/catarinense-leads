<?php

namespace App\Modules\Leads\Controllers;

use App\Models\ImportJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Modules\Leads\Jobs\RollbackLeadImportJob;
use Illuminate\Support\Facades\DB;
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
        if ($job->status === 'rollback_falhou') {
            DB::table('import_jobs')->where('id', $job->id)->update([
                'status' => 'revertendo',
                'updated_at' => now(),
            ]);
            RollbackLeadImportJob::dispatch($job->id);
            return response()->json(['message' => 'Nova tentativa de reversão iniciada.', 'status' => 'revertendo'], 202);
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

        DB::table('import_jobs')
            ->where('id', $job->id)
            ->where('status', 'concluido')
            ->update([
                'status' => 'revertendo',
                'rollback_final_status' => 'revertido',
                'updated_at' => now(),
            ]);
        RollbackLeadImportJob::dispatch($job->id);

        return response()->json([
            'message' => 'Reversão iniciada.',
            'status' => 'revertendo',
        ], 202);
    }
}
