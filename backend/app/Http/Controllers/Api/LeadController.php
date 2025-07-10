<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class LeadController extends Controller
{
    /**
     * Exibe uma lista paginada e filtrada de leads com dados agregados.
     */
    public function index(Request $request)
    {
        $query = Lead::query()
            // 1. Otimização: Adiciona a contagem de contratos como um campo 'contracts_count'
            ->withCount('contracts')
            // 2. Otimização: Adiciona a origem do primeiro job como um campo 'primeira_origem'
            ->addSelect(['primeira_origem' => function ($subQuery) {
                $subQuery->select('origin')
                    ->from('import_jobs')
                    ->join('lead_imports', 'import_jobs.id', '=', 'lead_imports.import_job_id')
                    ->whereColumn('lead_imports.lead_id', 'leads.id')
                    ->orderBy('lead_imports.created_at', 'asc')
                    ->limit(1);
            }]);

        // A lógica de filtros continua a mesma...
        if ($request->filled('search')) {
            $searchTerm = '%' . $request->input('search') . '%';
            $query->where(function (Builder $q) use ($searchTerm) {
                $q->where('nome', 'like', $searchTerm)
                  ->orWhere('cpf', 'like', $searchTerm);
            });
        }
        
        if ($request->filled('status') && $request->input('status') !== 'todos') {
            if ($request->input('status') === 'elegiveis') {
                $query->where('consulta', 'Saldo FACTA')->where('libera', '>', 0);
            } else {
                $query->where(function (Builder $q) {
                    $q->where('consulta', '!=', 'Saldo FACTA')->orWhere('libera', '=', '0.00');
                });
            }
        }

        $leads = $query->latest('updated_at')->paginate(10);

        return response()->json($leads);
    }

    /**
     * Exibe um único lead com todos os seus relacionamentos carregados.
     */
    public function show(Lead $lead)
    {
        // Aqui mantemos o load() pois queremos todos os detalhes do lead específico.
        $lead->load('contracts', 'importJobs');

        return response()->json($lead);
    }
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
 
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
