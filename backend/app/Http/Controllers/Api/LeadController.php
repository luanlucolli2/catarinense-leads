<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use App\Models\ImportJob;   // ⬅️ novo
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    /* ===============================================================
     * GET /leads  →  lista paginada com filtros dinâmicos
     * ===============================================================*/
    public function index(Request $r)
    {
        $perPage = (int) $r->input('per_page', 10);

        // aplica *todos* os filtros (inclui select, joins, withCount, addSelect)
        $query = \App\Http\Filters\LeadFilter::apply($r);

        // só falta paginar
        $leads = $query->paginate($perPage);

        return response()->json($leads);
    }


    /* ===============================================================
     * GET /leads/filters  →  motivos & origens distintos
     * ===============================================================*/
    public function filters()
    {
        // 1) subquery: lista dos primeiros job_ids (menor import_job_id) por lead
        $firstJobIds = DB::table('lead_imports')
            ->selectRaw('MIN(import_job_id) as id')
            ->groupBy('lead_id');

        // 1b) subquery: últimos job_ids de higienização por lead
        $lastHigJobIds = DB::table('lead_imports')
            ->join('import_jobs', 'import_jobs.id', '=', 'lead_imports.import_job_id')
            ->where('import_jobs.type', 'higienizacao')
            ->selectRaw('MAX(import_jobs.id) as id')
            ->groupBy('lead_imports.lead_id');


        return response()->json([
            'motivos' => Lead::query()
                ->whereNotNull('consulta')
                ->distinct()
                ->orderBy('consulta')
                ->pluck('consulta')
                ->values(),

            /* primeiros jobs do tipo **cadastral** */
            'origens' => ImportJob::query()
                ->where('type', 'cadastral')
                ->whereIn('id', $firstJobIds)
                ->distinct()
                ->orderBy('origin')
                ->pluck('origin')
                ->values(),

            /* **todas** as origens que apareceram em Higienizações  */
            'origens_hig' => ImportJob::query()
                ->where('type', 'higienizacao')
                ->whereIn('id', $lastHigJobIds)
                ->distinct()
                ->orderBy('origin')
                ->pluck('origin')
                ->values(),

            'vendors' => Vendor::query()
                ->whereHas('contracts')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn($v) => ['id' => $v->id, 'name' => $v->name])
                ->values(),
        ]);
    }

    /* ===============================================================
     * GET /leads/{id}
     * ===============================================================*/
    public function show(Lead $lead)
    {
        // eager‑loadamos também vendor dentro de contracts
        $lead->load(['contracts.vendor', 'importJobs']);
        return response()->json($lead);
    }

    /* ---------------------------------------------------------------
     * Helper: aplica whereIn a partir de texto massivo
     * --------------------------------------------------------------*/
    private function applyMassFilter(Builder $q, Request $r, string $key, array $columns): void
    {
        if (!$r->filled($key)) {
            return;
        }

        // normaliza → split por vírgula ; ou quebra de linha
        $values = preg_split('/[\s,;]+/', $r->input($key));
        $values = array_values(array_filter(array_unique($values)));

        if (empty($values)) {
            return;
        }

        $q->where(function ($sub) use ($columns, $values, $key) {
            foreach ($columns as $col) {
                if ($key === 'names') {
                    // para nomes, busca parcial
                    foreach ($values as $v) {
                        $sub->orWhere($col, 'like', '%' . $v . '%');
                    }
                } else {
                    // CPF ou telefones, comparação exata
                    $sub->orWhereIn($col, $values);
                }
            }
        });
    }
}
