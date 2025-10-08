<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    public function index(Request $r)
    {
        $perPage = (int) $r->input('per_page', 10);
        $query = \App\Http\Filters\LeadFilter::apply($r);
        $leads = $query->paginate($perPage);
        return response()->json($leads);
    }

    public function search(Request $r)
    {
        $perPage = (int) $r->input('per_page', 10);
        $query = \App\Http\Filters\LeadFilter::apply($r);
        $leads = $query->paginate($perPage);
        return response()->json($leads);
    }

    public function filters()
    {
        // últimas importações por lead (separadas por tipo)
        $lastCadJobIds = DB::table('lead_imports as li')
            ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
            ->where('ij.type', 'cadastral')
            ->selectRaw('MAX(li.import_job_id) as id')
            ->groupBy('li.lead_id');

        $lastHigJobIds = DB::table('lead_imports as li')
            ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
            ->where('ij.type', 'higienizacao')
            ->selectRaw('MAX(li.import_job_id) as id')
            ->groupBy('li.lead_id');

        return response()->json([
            'motivos' => Lead::query()
                ->whereNotNull('consulta')
                ->distinct()
                ->orderBy('consulta')
                ->pluck('consulta')
                ->values(),

            // origens CADASTRAL derivadas da ÚLTIMA origem de cada lead
            'origens' => DB::table('import_jobs')
                ->where('type', 'cadastral')
                ->whereIn('id', $lastCadJobIds)
                ->distinct()
                ->orderBy('origin')
                ->pluck('origin')
                ->values(),

            // origens HIG derivadas da ÚLTIMA origem de cada lead
            'origens_hig' => DB::table('import_jobs')
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

    public function show(Lead $lead)
    {
        $lead->load(['contracts.vendor', 'importJobs', 'fgtsOffSnapshot']);

        $lead->setAttribute('fgts_off_authorized', optional($lead->fgtsOffSnapshot)->authorized);
        $lead->setAttribute('fgts_off_consultado_em', optional($lead->fgtsOffSnapshot)->updated_at);

        // últimas origens por tipo
        $ultimaCad = DB::table('lead_imports as li')
            ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
            ->where('li.lead_id', $lead->id)
            ->where('ij.type', 'cadastral')
            ->orderByDesc('li.created_at')
            ->orderByDesc('li.import_job_id')
            ->limit(1)
            ->value('ij.origin');

        $ultimaHig = DB::table('lead_imports as li')
            ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
            ->where('li.lead_id', $lead->id)
            ->where('ij.type', 'higienizacao')
            ->orderByDesc('li.created_at')
            ->orderByDesc('li.import_job_id')
            ->limit(1)
            ->value('ij.origin');

        $lead->setAttribute('ultima_origem_cadastral', $ultimaCad);
        $lead->setAttribute('ultima_origem_higienizacao', $ultimaHig);

        $lead->unsetRelation('fgtsOffSnapshot');

        return response()->json($lead);
    }
}
