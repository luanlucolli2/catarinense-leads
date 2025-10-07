<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use App\Models\ImportJob;
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
        $firstJobIds = DB::table('lead_imports')
            ->selectRaw('MIN(import_job_id) as id')
            ->groupBy('lead_id');

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
            'origens' => ImportJob::query()
                ->where('type', 'cadastral')
                ->whereIn('id', $firstJobIds)
                ->distinct()
                ->orderBy('origin')
                ->pluck('origin')
                ->values(),
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

    public function show(Lead $lead)
    {
        $lead->load(['contracts.vendor', 'importJobs', 'fgtsOffSnapshot']);

        $lead->setAttribute('fgts_off_authorized', optional($lead->fgtsOffSnapshot)->authorized);
        // usar updated_at do snapshot como "consultado em"
        $lead->setAttribute('fgts_off_consultado_em', optional($lead->fgtsOffSnapshot)->updated_at);

        $lead->unsetRelation('fgtsOffSnapshot');

        return response()->json($lead);
    }
}
