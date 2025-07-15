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
        /* ----------- Config ----------- */
        $perPage = (int) $r->input('per_page', 10);

        /* ----------- Query base ----------- */
        $leads = Lead::query()
            ->select('leads.*')                  // evita conflito com sub-select
            ->withCount('contracts')             // contracts_count
            ->addSelect([                        // primeira_origem
                'primeira_origem' => function ($q) {
                    $q->select('origin')
                        ->from('import_jobs')
                        ->join('lead_imports', 'import_jobs.id', '=', 'lead_imports.import_job_id')
                        ->whereColumn('lead_imports.lead_id', 'leads.id')
                        ->orderBy('lead_imports.created_at')
                        ->limit(1);
                },
            ]);

        /* ----------- 1) Pesquisa livre ----------- */
        if ($r->filled('search')) {
            $term = '%' . $r->input('search') . '%';

            $leads->where(function (Builder $q) use ($term) {
                $q->where('nome', 'like', $term)
                    ->orWhere('cpf', 'like', $term)
                    ->orWhere('fone1', 'like', $term)
                    ->orWhere('fone2', 'like', $term)
                    ->orWhere('fone3', 'like', $term)
                    ->orWhere('fone4', 'like', $term);
            });
        }

        /* ----------- 2) Elegibilidade ----------- */
        if ($r->filled('status') && $r->status !== 'todos') {
            if ($r->status === 'elegiveis') {
                $leads->where('consulta', 'Saldo FACTA')
                    ->where('libera', '>', 0);
            } elseif ($r->status === 'nao-elegiveis') {
                $leads->where(function ($q) {
                    $q->where('consulta', '!=', 'Saldo FACTA')
                        ->orWhere('libera', '=', 0);
                });
            }
        }

        /* ----------- 3) Motivos (consulta) multi-select ----------- */
        if ($r->filled('motivos')) {
            $motivos = explode(',', $r->motivos);        // frontend envia coma-separated
            $leads->whereIn('consulta', $motivos);
        }

        /* ----------- 4) Origem multi-select ----------- */
        if ($r->filled('origens')) {
            $origens = explode(',', $r->origens);
            $leads->whereHas('importJobs', function (Builder $q) use ($origens) {
                $q->whereIn('import_jobs.origin', $origens);
            });
        }

        /* ----------- 4-B) Origem (somente HIGIENIZAÇÕES) ---------- */
        // parâmetro novo:  ?origens_hig=callcenter,teste
        if ($r->filled('origens_hig')) {
            $origHig = explode(',', $r->origens_hig);
            $leads->whereHas('importJobs', function (Builder $q) use ($origHig) {
                $q->where('import_jobs.type', 'higienizacao')
                    ->whereIn('import_jobs.origin', $origHig);
            });
        }

        /* ----------- 5) Período de atualização ----------- */
        if ($r->filled('date_from') || $r->filled('date_to')) {
            $from = $r->input('date_from', '1900-01-01');
            $to = $r->input('date_to', now()->toDateString());
            $leads->whereBetween('data_atualizacao', [$from . ' 00:00:00', $to . ' 23:59:59']);
        }

        /* ----------- 6) Período de contratos ----------- */
        if ($r->filled('contract_from') || $r->filled('contract_to')) {
            $from = $r->input('contract_from', '1900-01-01');
            $to = $r->input('contract_to', now()->toDateString());

            $leads->whereHas('contracts', function ($q) use ($from, $to) {
                $q->whereBetween('data_contrato', [$from, $to]);
            });
        }

        /* ----------- 7) Mass filters (cpf / nome / fone) ----------- */
        $this->applyMassFilter($leads, $r, 'cpf', ['cpf']);
        $this->applyMassFilter($leads, $r, 'names', ['nome']);
        $this->applyMassFilter($leads, $r, 'phones', ['fone1', 'fone2', 'fone3', 'fone4']);

        if ($r->filled('vendors')) {
        // explode pelos nomes enviados e
        // normaliza igual ao Vendor::clean()
        $vendorNames = explode(',', $r->vendors);
        $cleanNames  = array_map(fn($n) => Vendor::clean($n), $vendorNames);

        // filtra leads pelos contratos cujo vendor.name_clean está na lista
        $leads->whereHas('contracts.vendor', function (Builder $q) use ($cleanNames) {
            $q->whereIn('name_clean', $cleanNames);
        });
    }

        /* ----------- Resultado ----------- */
        $leads = $leads->latest('updated_at')->paginate($perPage);

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
