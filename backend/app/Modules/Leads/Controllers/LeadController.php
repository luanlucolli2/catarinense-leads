<?php

namespace App\Modules\Leads\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    public function index(Request $r)
    {
        return $this->paginateLeads($r);
    }

    public function search(Request $r)
    {
        return $this->paginateLeads($r);
    }

    private function paginateLeads(Request $r)
    {
        $perPage = (int) $r->input('per_page', (int) config('leads.pagination.per_page_default', 10));
        $maxPerPage = max(1, (int) config('leads.pagination.per_page_max', 100));
        $perPage = min(max(1, $perPage), $maxPerPage);
        $idPage = \App\Modules\Leads\Filters\LeadFilter::apply($r, null, true)->paginate($perPage);
        $ids = collect($idPage->items())->pluck('id')->all();

        if (empty($ids)) {
            return response()->json($idPage);
        }

        $leads = \App\Modules\Leads\Filters\LeadFilter::apply($r)
            ->whereIn('leads.id', $ids)
            ->get()
            ->keyBy('id');

        $idPage->setCollection(
            collect($ids)
                ->map(fn(int $id) => $leads->get($id))
                ->filter()
                ->values()
        );

        return response()->json($idPage);
    }

    public function filters()
    {
        $ttlSeconds = max(1, (int) config('leads.filters.cache_ttl_seconds', 60));
        $cacheKey = (string) config('leads.filters.cache_key', 'leads:filters:v1');

        $payload = Cache::remember($cacheKey, now()->addSeconds($ttlSeconds), function () {
            $lastCadJobIds = $this->latestImportJobIdsSubquery('cadastral');
            $lastHigJobIds = $this->latestImportJobIdsSubquery('higienizacao');

            return [
                'motivos' => Lead::query()
                    ->whereNotNull('consulta')
                    ->distinct()
                    ->orderBy('consulta')
                    ->pluck('consulta')
                    ->values()
                    ->all(),

                'origens' => DB::table('import_jobs')
                    ->where('type', 'cadastral')
                    ->whereIn('id', $lastCadJobIds)
                    ->distinct()
                    ->orderBy('origin')
                    ->pluck('origin')
                    ->values()
                    ->all(),

                'origens_hig' => DB::table('import_jobs')
                    ->where('type', 'higienizacao')
                    ->whereIn('id', $lastHigJobIds)
                    ->distinct()
                    ->orderBy('origin')
                    ->pluck('origin')
                    ->values()
                    ->all(),

                'origens_mercantil' => DB::table('import_jobs as ij')
                    ->where('ij.type', 'mercantil')
                    ->whereNotNull('ij.origin')
                    ->whereExists(function ($q) {
                        $q->selectRaw('1')
                            ->from('mercantil_snapshots as ms')
                            ->whereColumn('ms.job_id', 'ij.id');
                    })
                    ->select('ij.origin')
                    ->distinct()
                    ->orderBy('ij.origin')
                    ->pluck('ij.origin')
                    ->values()
                    ->all(),

                'mercantil_status' => DB::table('mercantil_snapshots')
                    ->whereNotNull('status')
                    ->distinct()
                    ->orderByRaw("CASE WHEN status = 'SUCESSO' THEN 0 ELSE 1 END")
                    ->orderBy('status')
                    ->pluck('status')
                    ->values()
                    ->all(),

                'vendors' => Vendor::query()
                    ->whereHas('contracts')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn($v) => ['id' => $v->id, 'name' => $v->name])
                    ->values()
                    ->all(),
            ];
        });

        return response()->json($payload);
    }

    public function show(Lead $lead)
    {
        $lead->load(['contracts.vendor', 'importJobs', 'fgtsOffSnapshot', 'cltSnapshot']);

        // FGTS OFF
        $lead->setAttribute('fgts_off_authorized', optional($lead->fgtsOffSnapshot)->authorized);
        $lead->setAttribute('fgts_off_consultado_em', optional($lead->fgtsOffSnapshot)->updated_at);

        // CLT – expõe campos e datas separadas
        $clt = $lead->cltSnapshot;
        if ($clt) {
            $lead->setAttribute('matricula', $clt->matricula);
            $lead->setAttribute('elegivel', $clt->elegivel);
            $lead->setAttribute('idade', $clt->idade);
            $lead->setAttribute('sexo', $clt->sexo);
            $lead->setAttribute('data_admissao', $clt->data_admissao);
            $lead->setAttribute('meses_admissao', $clt->meses_admissao);
            $lead->setAttribute('valor_renda', $clt->valor_renda);
            $lead->setAttribute('valor_base_margem', $clt->valor_base_margem);
            $lead->setAttribute('margem_disponivel', $clt->margem_disponivel);
            $lead->setAttribute('valor_max_prestacao', $clt->valor_max_prestacao);
            $lead->setAttribute('categoria_trabalhador_codigo', $clt->categoria_trabalhador_codigo);
            $lead->setAttribute('inicio_atividade_empregador', $clt->inicio_atividade_empregador);
            $lead->setAttribute('qtd_emprestimos_ativos_suspensos', $clt->qtd_emprestimos_ativos_suspensos);
            $lead->setAttribute('emprestimos_legados', $clt->emprestimos_legados);
            $lead->setAttribute('not_found', $clt->not_found);

            // datas:
            $lead->setAttribute('clt_consultado_em', $clt->consulted_at);          // quando consultamos
            $lead->setAttribute('clt_dados_atualizados_em', $clt->updated_at);     // quando a origem atualizou
        }

        // últimas origens por tipo
        $ultimaCad = $this->latestOriginForLead((int) $lead->id, 'cadastral');
        $ultimaHig = $this->latestOriginForLead((int) $lead->id, 'higienizacao');

        $lead->setAttribute('ultima_origem_cadastral', $ultimaCad);
        $lead->setAttribute('ultima_origem_higienizacao', $ultimaHig);

        $lead->unsetRelation('fgtsOffSnapshot');
        $lead->unsetRelation('cltSnapshot');

        return response()->json($lead);
    }

    private function latestOriginForLead(int $leadId, string $type): ?string
    {
        return DB::table('lead_imports as li')
            ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
            ->where('li.lead_id', $leadId)
            ->where('ij.type', $type)
            ->orderByDesc('li.created_at')
            ->orderByDesc('li.import_job_id')
            ->limit(1)
            ->value('ij.origin');
    }

    private function latestImportJobIdsSubquery(string $type)
    {
        return DB::table('lead_imports as li')
            ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
            ->where('ij.type', $type)
            ->selectRaw('MAX(li.import_job_id) as id')
            ->groupBy('li.lead_id');
    }
}
