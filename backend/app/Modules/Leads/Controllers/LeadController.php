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
        $mode = strtolower((string) $r->input('mode', 'fgts'));
        $idQuery = \App\Modules\Leads\Filters\LeadFilter::apply($r, null, true);
        $total = null;

        if ($mode === '360') {
            $ttlSeconds = max(1, (int) config('leads.pagination.count_cache_ttl_seconds', 60));
            $cachePrefix = (string) config('leads.pagination.count_cache_key_prefix', 'leads:360:count');
            $fingerprint = hash('sha256', serialize([$idQuery->toSql(), $idQuery->getBindings()]));

            $total = Cache::remember(
                "{$cachePrefix}:{$fingerprint}",
                now()->addSeconds($ttlSeconds),
                fn (): int => (int) $idQuery->toBase()->getCountForPagination()
            );
        }

        $idPage = $idQuery->paginate($perPage, ['*'], 'page', null, $total);
        $ids = collect($idPage->items())->pluck('id')->all();

        if (empty($ids)) {
            return response()->json($idPage);
        }

        $leads = \App\Modules\Leads\Filters\LeadFilter::apply($r, null, false, $mode !== '360')
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
        $lead->load(['contracts.vendor', 'importJobs', 'fgtsOffSnapshot', 'factaSnapshot', 'mercantilSnapshot', 'uy3Snapshot']);

        // FGTS OFF
        $lead->setAttribute('fgts_off_authorized', optional($lead->fgtsOffSnapshot)->authorized);
        $lead->setAttribute('fgts_off_consultado_em', optional($lead->fgtsOffSnapshot)->updated_at);

        // CLT – expõe campos e datas separadas
        $clt = $lead->factaSnapshot;
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
            $lead->setAttribute('facta_consultado_em', $clt->consulted_at);          // quando consultamos
            $lead->setAttribute('facta_dados_atualizados_em', $clt->updated_at);     // quando a origem atualizou
        }

        $mercantil = $lead->mercantilSnapshot;
        if ($mercantil) {
            $lead->setAttribute('mercantil_status', $mercantil->status);
            $lead->setAttribute('mercantil_mensagem_erro', $mercantil->mensagem_erro);
            $lead->setAttribute('mercantil_data_hora_origem', $mercantil->data_hora_origem);
            $lead->setAttribute('mercantil_valor_financiado', $mercantil->valor_financiado);
            $lead->setAttribute('mercantil_valor_iof', $mercantil->valor_iof);
            $lead->setAttribute('mercantil_data_primeiro_vencimento', $mercantil->data_primeiro_vencimento);
            $lead->setAttribute('mercantil_valor_emprestimo', $mercantil->valor_emprestimo);
            $lead->setAttribute('mercantil_quantidade_parcelas', $mercantil->quantidade_parcelas);
            $lead->setAttribute('mercantil_valor_liberado', $mercantil->valor_liberado);
            $lead->setAttribute('mercantil_taxa_juros_mes', $mercantil->taxa_juros_mes);
            $lead->setAttribute('mercantil_valor_parcela', $mercantil->valor_parcela);
        }

        $uy3 = $lead->uy3Snapshot;
        if ($uy3) {
            $lead->setAttribute('uy3_type_webhook', $uy3->type_webhook);
            $lead->setAttribute('uy3_status', $uy3->status);
            $lead->setAttribute('uy3_consultado_em', $uy3->updated_at);
            $lead->setAttribute('uy3_data_admissao', $uy3->data_admissao);
            $lead->setAttribute('uy3_valor_liberado', $uy3->valor_liberado);
            $lead->setAttribute('uy3_numero_parcelas', $uy3->numero_parcelas);
            $lead->setAttribute('uy3_codigo_requisicao', $uy3->codigo_requisicao);
            $lead->setAttribute('uy3_margem_disponivel', $uy3->margem_disponivel);
            $lead->setAttribute('uy3_elegivel_emprestimo', $uy3->elegivel_emprestimo);
            $lead->setAttribute('uy3_numero_inscricao_empregador', $uy3->numero_inscricao_empregador);
            $lead->setAttribute('uy3_pessoa_exposta_politicamente_codigo', $uy3->pessoa_exposta_politicamente_codigo);
            $lead->setAttribute('uy3_data_hora_validade_solicitacao', $uy3->data_hora_validade_solicitacao);
            $lead->setAttribute('uy3_is_mei', $uy3->is_mei);
            $lead->setAttribute('uy3_is_judicial_recovery', $uy3->is_judicial_recovery);
        }

        // últimas origens por tipo
        $ultimaCad = $this->latestOriginForLead((int) $lead->id, 'cadastral');
        $ultimaHig = $this->latestOriginForLead((int) $lead->id, 'higienizacao');
        $ultimaMercantil = $this->latestMercantilOriginForLead((string) $lead->cpf);

        $lead->setAttribute('ultima_origem_cadastral', $ultimaCad);
        $lead->setAttribute('ultima_origem_higienizacao', $ultimaHig);
        $lead->setAttribute('ultima_origem_mercantil', $ultimaMercantil);

        $lead->unsetRelation('fgtsOffSnapshot');
        $lead->unsetRelation('factaSnapshot');
        $lead->unsetRelation('mercantilSnapshot');
        $lead->unsetRelation('uy3Snapshot');

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

    private function latestMercantilOriginForLead(string $cpf): ?string
    {
        return DB::table('mercantil_snapshots as ms')
            ->join('import_jobs as ij', 'ij.id', '=', 'ms.job_id')
            ->where('ms.cpf', $cpf)
            ->where('ij.type', 'mercantil')
            ->orderByDesc('ms.updated_at')
            ->orderByDesc('ms.job_id')
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
