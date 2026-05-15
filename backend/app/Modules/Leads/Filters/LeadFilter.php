<?php

namespace App\Modules\Leads\Filters;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Models\Lead;
use App\Models\Vendor;
use App\Support\Cpf;

class LeadFilter
{
    public static function apply(Request $r, ?array $columnsForExport = null): Builder
    {
        $exportMode = is_array($columnsForExport);
        $columnsForExport = $columnsForExport ?? [];
        $mode = strtolower((string) $r->input('mode', 'fgts')); // 'base' | 'fgts' | 'clt' | 'mercantil'
        if (!in_array($mode, ['base', 'fgts', 'clt', 'mercantil'], true)) {
            $mode = 'fgts';
        }

        $cltFields = [
            'matricula',
            'elegivel',
            'idade',
            'sexo',
            'data_admissao',
            'meses_admissao',
            'valor_renda',
            'valor_base_margem',
            'margem_disponivel',
            'valor_max_prestacao',
            'categoria_trabalhador_codigo',
            'inicio_atividade_empregador',
            'qtd_emprestimos_ativos_suspensos',
            'emprestimos_legados',
            'not_found',
            'politica_credito_aprovado',
            'politica_credito_mensagem',
            'politica_credito_valor_maximo_disponivel',
            'politica_credito_prazo_maximo_disponivel',
            'politica_credito_data_consulta',
            'politica_credito_tabela_aprovada',
        ];

        $mercantilFields = [
            'mercantil_status',
            'mercantil_mensagem_erro',
            'mercantil_data_hora_origem',
            'mercantil_valor_financiado',
            'mercantil_valor_iof',
            'mercantil_data_primeiro_vencimento',
            'mercantil_valor_emprestimo',
            'mercantil_quantidade_parcelas',
            'mercantil_valor_liberado',
            'mercantil_taxa_juros_mes',
            'mercantil_valor_parcela',
            'mercantil_dados_atualizados_em',
            'ultima_origem_mercantil',
        ];

        // ---- FGTS OFF: colunas projetadas (quando necessário) ----
        $needFgtsAuthorizedCol = $exportMode
            ? in_array('fgts_off_authorized', $columnsForExport, true)
            : ($mode === 'fgts');

        $needFgtsConsultadoCol = $exportMode
            ? in_array('fgts_off_consultado_em', $columnsForExport, true)
            : ($mode === 'fgts');

        $needAnyClt = $exportMode
            ? (bool) array_intersect($columnsForExport, array_merge($cltFields, ['clt_consultado_em', 'clt_dados_atualizados_em']))
            : ($mode === 'clt');

        $needAnyMercantil = $exportMode
            ? (bool) array_intersect($columnsForExport, $mercantilFields)
            : ($mode === 'mercantil');

        $hasMercantilScopedFilters =
            $r->filled('mercantil_situacao')
            || $r->filled('mercantil_status')
            || $r->filled('mercantil_origens')
            || $r->filled('mercantil_consulta_from')
            || $r->filled('mercantil_consulta_to')
            || $r->filled('mercantil_import_from')
            || $r->filled('mercantil_import_to')
            || $r->filled('mercantil_parcela_min')
            || $r->filled('mercantil_parcela_max')
            || $r->filled('mercantil_qtd_parcelas_min')
            || $r->filled('mercantil_qtd_parcelas_max');

        $needCltJoin = $mode === 'clt' || $needAnyClt;
        $needMercantilJoin = (!$exportMode && $mode === 'mercantil')
            || $needAnyMercantil
            || $hasMercantilScopedFilters;
        $needFgtsJoin = $mode === 'fgts'
            || $needFgtsAuthorizedCol
            || $needFgtsConsultadoCol
            || $r->filled('fgts_status')
            || $r->filled('fgts_consulta_from')
            || $r->filled('fgts_consulta_to');

        if ($exportMode) {
            $select = ['leads.id'];
            $allowedLeadCols = [
                'cpf',
                'nome',
                'created_at',
                'updated_at',
                'data_nascimento',
                'fone1',
                'fone2',
                'fone3',
                'fone4',
                'classe_fone1',
                'classe_fone2',
                'classe_fone3',
                'classe_fone4',
                'consulta',
                'saldo',
                'libera',
                'data_atualizacao',
            ];
            foreach ($columnsForExport as $col) {
                if (in_array($col, $allowedLeadCols, true))
                    $select[] = "leads.$col";
                if ($col === 'id' && !in_array('leads.id', $select, true))
                    $select[] = 'leads.id';
            }
            $query = Lead::query()->select($select);

            if ($needCltJoin) {
                $query->leftJoin('clt_snapshots as cs', 'cs.cpf', '=', 'leads.cpf');
            }
            if ($needMercantilJoin) {
                $query->leftJoin('mercantil_snapshots as ms', 'ms.cpf', '=', 'leads.cpf');
                $query->leftJoin('import_jobs as ijm', function ($join) {
                    $join->on('ijm.id', '=', 'ms.job_id')
                        ->where('ijm.type', '=', 'mercantil');
                });
            }
            if ($needFgtsJoin) {
                $query->leftJoin('fgts_off_snapshots as fos', 'fos.cpf', '=', 'leads.cpf');
            }

            if (in_array('ultima_origem_cadastral', $columnsForExport, true)) {
                self::addLatestOriginSelect($query, 'ultima_origem_cadastral', 'cadastral');
            }

            if (in_array('ultima_origem_higienizacao', $columnsForExport, true)) {
                self::addLatestOriginSelect($query, 'ultima_origem_higienizacao', 'higienizacao');
            }

            if (in_array('ultima_origem_mercantil', $columnsForExport, true)) {
                if ($needMercantilJoin) {
                    $query->addSelect(DB::raw('ijm.origin as ultima_origem_mercantil'));
                } else {
                    self::addLatestOriginSelect($query, 'ultima_origem_mercantil', 'mercantil');
                }
            }

            if (in_array('contracts_count', $columnsForExport, true)) {
                $query->withCount('contracts');
            }

            if (in_array('data_contrato_recente', $columnsForExport, true)) {
                $query->addSelect([
                    'data_contrato_recente' => DB::table('lead_contracts')
                        ->selectRaw('MAX(data_contrato)')
                        ->whereColumn('lead_contracts.lead_id', 'leads.id')
                        ->limit(1),
                ]);
            }

            if (in_array('vendedor', $columnsForExport, true)) {
                $query->addSelect([
                    'vendedor' => DB::table('lead_contracts as lc')
                        ->join('vendors as v', 'v.id', '=', 'lc.vendor_id')
                        ->whereColumn('lc.lead_id', 'leads.id')
                        ->orderByDesc('lc.data_contrato')
                        ->orderByDesc('lc.id')
                        ->limit(1)
                        ->select('v.name'),
                ]);
            }

            // FGTS OFF no export (se pedido)
            if ($needFgtsAuthorizedCol) {
                $query->addSelect(DB::raw('fos.authorized as fgts_off_authorized'));
            }
            if ($needFgtsConsultadoCol) {
                $query->addSelect(DB::raw('fos.updated_at as fgts_off_consultado_em'));
            }

            // CLT no export (se pedido explicitamente)
            if ($needAnyClt) {
                foreach ($cltFields as $f) {
                    if (in_array($f, $columnsForExport, true)) {
                        $query->addSelect(DB::raw("cs.{$f} as {$f}"));
                    }
                }
                if (in_array('clt_consultado_em', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('cs.consulted_at as clt_consultado_em'));
                }
                if (in_array('clt_dados_atualizados_em', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('cs.updated_at as clt_dados_atualizados_em'));
                }
            }

            // MERCANTIL no export (se pedido explicitamente)
            if ($needAnyMercantil) {
                if (in_array('mercantil_status', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('ms.status as mercantil_status'));
                }
                if (in_array('mercantil_mensagem_erro', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('ms.mensagem_erro as mercantil_mensagem_erro'));
                }
                if (in_array('mercantil_data_hora_origem', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('ms.data_hora_origem as mercantil_data_hora_origem'));
                }
                if (in_array('mercantil_valor_financiado', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('ms.valor_financiado as mercantil_valor_financiado'));
                }
                if (in_array('mercantil_valor_iof', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('ms.valor_iof as mercantil_valor_iof'));
                }
                if (in_array('mercantil_data_primeiro_vencimento', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('ms.data_primeiro_vencimento as mercantil_data_primeiro_vencimento'));
                }
                if (in_array('mercantil_valor_emprestimo', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('ms.valor_emprestimo as mercantil_valor_emprestimo'));
                }
                if (in_array('mercantil_quantidade_parcelas', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('ms.quantidade_parcelas as mercantil_quantidade_parcelas'));
                }
                if (in_array('mercantil_valor_liberado', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('ms.valor_liberado as mercantil_valor_liberado'));
                }
                if (in_array('mercantil_taxa_juros_mes', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('ms.taxa_juros_mes as mercantil_taxa_juros_mes'));
                }
                if (in_array('mercantil_valor_parcela', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('ms.valor_parcela as mercantil_valor_parcela'));
                }
                if (in_array('mercantil_dados_atualizados_em', $columnsForExport, true)) {
                    $query->addSelect(DB::raw('ms.updated_at as mercantil_dados_atualizados_em'));
                }
            }
        } else {
            // ====== LISTA (API) ======
            $query = Lead::query()->select('leads.*');

            if ($needCltJoin) {
                $query->leftJoin('clt_snapshots as cs', 'cs.cpf', '=', 'leads.cpf');
            }
            if ($needMercantilJoin) {
                $query->leftJoin('mercantil_snapshots as ms', 'ms.cpf', '=', 'leads.cpf');
                $query->leftJoin('import_jobs as ijm', function ($join) {
                    $join->on('ijm.id', '=', 'ms.job_id')
                        ->where('ijm.type', '=', 'mercantil');
                });
            }
            if ($needFgtsJoin) {
                $query->leftJoin('fgts_off_snapshots as fos', 'fos.cpf', '=', 'leads.cpf');
            }

            if ($mode === 'base') {
                $query->addSelect([
                    'ultima_origem_cadastral' => self::latestOriginSubquery('cadastral')
                ]);

                $query->addSelect([
                    'ultima_origem_higienizacao' => self::latestOriginSubquery('higienizacao')
                ]);
            } elseif ($mode === 'fgts') {
                $query->withCount('contracts');

                $query->addSelect([
                    'data_contrato_recente' => DB::table('lead_contracts as lc')
                        ->whereColumn('lc.lead_id', 'leads.id')
                        ->select('lc.data_contrato')
                        ->orderByDesc('lc.data_contrato')
                        ->orderByDesc('lc.id')
                        ->limit(1),
                ]);

                $query->addSelect([
                    'vendedor' => DB::table('lead_contracts as lc')
                        ->join('vendors as v', 'v.id', '=', 'lc.vendor_id')
                        ->whereColumn('lc.lead_id', 'leads.id')
                        ->select('v.name')
                        ->orderByDesc('lc.data_contrato')
                        ->orderByDesc('lc.id')
                        ->limit(1),
                ]);

                $query->addSelect([
                    'ultima_origem_cadastral' => self::latestOriginSubquery('cadastral')
                ]);

                $query->addSelect([
                    'ultima_origem_higienizacao' => self::latestOriginSubquery('higienizacao')
                ]);

                if ($needFgtsAuthorizedCol) {
                    $query->addSelect(DB::raw('fos.authorized as fgts_off_authorized'));
                }
                if ($needFgtsConsultadoCol) {
                    $query->addSelect(DB::raw('fos.updated_at as fgts_off_consultado_em'));
                }
            } elseif ($mode === 'clt') {
                // ===== MODO CLT
                $query->addSelect([
                    DB::raw('cs.matricula as matricula'),
                    DB::raw('cs.elegivel as elegivel'),
                    DB::raw('cs.idade as idade'),
                    DB::raw('cs.sexo as sexo'),
                    DB::raw('cs.data_admissao as data_admissao'),
                    DB::raw('cs.meses_admissao as meses_admissao'),
                    DB::raw('cs.valor_renda as valor_renda'),
                    DB::raw('cs.valor_base_margem as valor_base_margem'),
                    DB::raw('cs.margem_disponivel as margem_disponivel'),
                    DB::raw('cs.valor_max_prestacao as valor_max_prestacao'),
                    DB::raw('cs.categoria_trabalhador_codigo as categoria_trabalhador_codigo'),
                    DB::raw('cs.inicio_atividade_empregador as inicio_atividade_empregador'),
                    DB::raw('cs.qtd_emprestimos_ativos_suspensos as qtd_emprestimos_ativos_suspensos'),
                    DB::raw('cs.emprestimos_legados as emprestimos_legados'),
                    DB::raw('cs.not_found as not_found'),
                    DB::raw('cs.politica_credito_aprovado as politica_credito_aprovado'),
                    DB::raw('cs.politica_credito_mensagem as politica_credito_mensagem'),
                    DB::raw('cs.politica_credito_valor_maximo_disponivel as politica_credito_valor_maximo_disponivel'),
                    DB::raw('cs.politica_credito_prazo_maximo_disponivel as politica_credito_prazo_maximo_disponivel'),
                    DB::raw('cs.politica_credito_data_consulta as politica_credito_data_consulta'),
                    DB::raw('cs.politica_credito_tabela_aprovada as politica_credito_tabela_aprovada'),
                    DB::raw('cs.consulted_at as clt_consultado_em'),
                    DB::raw('cs.updated_at as clt_dados_atualizados_em'),

                    'ultima_origem_cadastral' => self::latestOriginSubquery('cadastral'),
                ]);
            } elseif ($mode === 'mercantil') {
                // ===== MODO CLT (MERCANTIL)
                $query->addSelect([
                    DB::raw('ms.status as mercantil_status'),
                    DB::raw('ms.mensagem_erro as mercantil_mensagem_erro'),
                    DB::raw('ms.data_hora_origem as mercantil_data_hora_origem'),
                    DB::raw('ms.valor_financiado as mercantil_valor_financiado'),
                    DB::raw('ms.valor_iof as mercantil_valor_iof'),
                    DB::raw('ms.data_primeiro_vencimento as mercantil_data_primeiro_vencimento'),
                    DB::raw('ms.valor_emprestimo as mercantil_valor_emprestimo'),
                    DB::raw('ms.quantidade_parcelas as mercantil_quantidade_parcelas'),
                    DB::raw('ms.valor_liberado as mercantil_valor_liberado'),
                    DB::raw('ms.taxa_juros_mes as mercantil_taxa_juros_mes'),
                    DB::raw('ms.valor_parcela as mercantil_valor_parcela'),
                    DB::raw('ms.updated_at as mercantil_dados_atualizados_em'),

                    'ultima_origem_cadastral' => self::latestOriginSubquery('cadastral'),
                    DB::raw('ijm.origin as ultima_origem_mercantil'),
                ]);
            }
        }

        // ----- busca geral -----
        if ($r->filled('search')) {
            $termRaw = (string) $r->input('search');
            $termLike = '%' . $termRaw . '%';
            $digits = preg_replace('/\D+/', '', $termRaw) ?: '';

            $query->where(function (Builder $q) use ($termLike, $digits) {
                $q->where('leads.nome', 'like', $termLike)
                    ->orWhere('leads.fone1', 'like', $termLike)
                    ->orWhere('leads.fone2', 'like', $termLike)
                    ->orWhere('leads.fone3', 'like', $termLike)
                    ->orWhere('leads.fone4', 'like', $termLike);

                if ($digits !== '') {
                    $norm = Cpf::normalize($digits);
                    if ($norm)
                        $q->orWhere('leads.cpf', $norm);
                    $q->orWhere('leads.cpf', 'like', '%' . $digits . '%');
                } else {
                    $q->orWhere('leads.cpf', 'like', $termLike);
                }
            });
        }

        // ===== filtros FGTS (não se aplicam ao CLT) =====
        if ($mode === 'fgts') {
            $motivos = self::requestList($r, 'motivos');
            if ($motivos)
                $query->whereIn('leads.consulta', $motivos);

            $origHig = self::requestList($r, 'origens_hig');
            if ($origHig) {
                self::applyLatestOriginFilter($query, 'higienizacao', $origHig);
            }

            if ($r->filled('contract_from') || $r->filled('contract_to')) {
                $from = $r->input('contract_from', '1900-01-01');
                $to = $r->input('contract_to', now()->toDateString());
                $query->whereHas('contracts', fn(Builder $q) => $q->whereBetween('data_contrato', [$from, $to]));
            }

            $vendors = self::requestList($r, 'vendors');
            if ($vendors) {
                $clean = array_map(fn($n) => Vendor::clean($n), $vendors);
                $query->whereHas('contracts.vendor', fn(Builder $q) => $q->whereIn('name_clean', $clean));
            }
        }

        // filtros de origem cadastral (válido para ambos)
        $origensCad = self::requestList($r, 'origens');
        if ($origensCad) {
            self::applyLatestOriginFilter($query, 'cadastral', $origensCad);
        }

        // datas gerais de atualização do LEAD
        if ($r->filled('date_from') || $r->filled('date_to')) {
            $from = $r->input('date_from', '1900-01-01');
            $to = $r->input('date_to', now()->toDateString());
            $query->whereBetween('leads.data_atualizacao', ["{$from} 00:00:00", "{$to} 23:59:59"]);
        }

        self::applyMassFilter($query, $r, 'cpf', ['leads.cpf']);
        self::applyMassFilter($query, $r, 'names', ['leads.nome']);
        self::applyMassFilter($query, $r, 'phones', ['leads.fone1', 'leads.fone2', 'leads.fone3', 'leads.fone4']);
        if ($r->boolean('without_phones')) {
            foreach (['leads.fone1', 'leads.fone2', 'leads.fone3', 'leads.fone4'] as $phoneColumn) {
                $query->where(function (Builder $phoneQuery) use ($phoneColumn) {
                    $phoneQuery
                        ->whereNull($phoneColumn)
                        ->orWhereRaw("TRIM({$phoneColumn}) = ''");
                });
            }
        }

        $birth = self::requestList($r, 'birth_month');
        self::applyBirthMonthFilter($query, $birth);

        // ======== CLT – filtros específicos ========
        if ($mode === 'clt') {
            $consultado = self::yn($r->input('clt_consultado', null));
            $situacao = self::normalizeCltSituacao($r->input('clt_situacao', null));

            $hasCltFilters =
                ($situacao !== null) ||
                $r->filled('clt_elegivel') || $r->filled('clt_not_found') ||
                $r->filled('clt_consulta_from') || $r->filled('clt_consulta_to') ||
                $r->filled('clt_admissao_from') || $r->filled('clt_admissao_to') ||
                $r->filled('clt_meses_min') || $r->filled('clt_meses_max') ||
                $r->filled('clt_inicio_empregador_from') || $r->filled('clt_inicio_empregador_to') ||
                $r->filled('clt_categoria_codigos') ||
                $r->filled('clt_idade_min') || $r->filled('clt_idade_max') ||
                $r->filled('clt_sexo') ||
                $r->filled('clt_renda_min') || $r->filled('clt_renda_max') ||
                $r->filled('clt_base_min') || $r->filled('clt_base_max') ||
                $r->filled('clt_margem_min') || $r->filled('clt_margem_max') ||
                $r->filled('clt_prestacao_min') || $r->filled('clt_prestacao_max') ||
                $r->filled('clt_ativos_min') || $r->filled('clt_ativos_max') || $r->filled('clt_tem_ativos') ||
                $r->filled('clt_tem_legados');

            if ($consultado === 'nao') {
                $query->whereNull('cs.cpf');
            } elseif ($consultado === 'sim' || $hasCltFilters) {
                $query->whereNotNull('cs.cpf');

                if ($situacao !== null) {
                    if ($situacao === 'elegivel') {
                        $query->where('cs.not_found', 0)->where('cs.elegivel', 1);
                    } elseif ($situacao === 'nao_elegivel') {
                        $query->where('cs.not_found', 0)
                            ->where(function ($q) {
                                $q->where('cs.elegivel', 0)->orWhereNull('cs.elegivel');
                            });
                    } elseif ($situacao === 'nao_encontrado') {
                        $query->where('cs.not_found', 1);
                    }
                } else {
                    if (($v = self::yn($r->input('clt_elegivel'))) !== null) {
                        $query->where('cs.elegivel', $v === 'sim' ? 1 : 0);
                    }
                    if (($v = self::yn($r->input('clt_not_found'))) !== null) {
                        $query->where('cs.not_found', $v === 'sim' ? 1 : 0);
                    }
                }

                if ($r->filled('clt_consulta_from') || $r->filled('clt_consulta_to')) {
                    $from = $r->input('clt_consulta_from', '1900-01-01');
                    $to = $r->input('clt_consulta_to', now()->toDateString());
                    $query->whereBetween('cs.consulted_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
                }

                if ($r->filled('clt_admissao_from') || $r->filled('clt_admissao_to')) {
                    $from = $r->input('clt_admissao_from', '1900-01-01');
                    $to = $r->input('clt_admissao_to', now()->toDateString());
                    $query->whereBetween('cs.data_admissao', [$from, $to]);
                }

                if ($r->filled('clt_meses_min') || $r->filled('clt_meses_max')) {
                    $min = (int) $r->input('clt_meses_min', 0);
                    $max = (int) $r->input('clt_meses_max', PHP_INT_MAX);
                    $query->whereBetween('cs.meses_admissao', [$min, $max]);
                }

                if ($r->filled('clt_inicio_empregador_from') || $r->filled('clt_inicio_empregador_to')) {
                    $from = $r->input('clt_inicio_empregador_from', '1900-01-01');
                    $to = $r->input('clt_inicio_empregador_to', now()->toDateString());
                    $query->whereBetween('cs.inicio_atividade_empregador', [$from, $to]);
                }

                if ($r->filled('clt_categoria_codigos')) {
                    $raw = is_array($r->clt_categoria_codigos)
                        ? $r->clt_categoria_codigos
                        : preg_split('/[\s,;]+/', (string) $r->clt_categoria_codigos);
                    $codes = array_values(array_filter(array_unique(array_map('trim', $raw)), fn($v) => $v !== ''));
                    if ($codes) {
                        $query->whereIn('cs.categoria_trabalhador_codigo', $codes);
                    }
                }

                if ($r->filled('clt_idade_min') || $r->filled('clt_idade_max')) {
                    $min = (int) $r->input('clt_idade_min', 0);
                    $max = (int) $r->input('clt_idade_max', 200);
                    $query->whereBetween('cs.idade', [$min, $max]);
                }

                if ($r->filled('clt_sexo')) {
                    $raw = is_array($r->clt_sexo) ? $r->clt_sexo : [$r->clt_sexo];
                    $vals = array_map(fn($s) => mb_strtoupper(trim((string) $s)), $raw);
                    $map = [];
                    foreach ($vals as $v) {
                        if ($v === 'M' || $v === 'MASCULINO')
                            $map[] = ['M', 'Masculino'];
                        if ($v === 'F' || $v === 'FEMININO')
                            $map[] = ['F', 'Feminino'];
                    }
                    $flat = array_values(array_unique(array_merge(...($map ?: [[]]))));
                    if ($flat) {
                        $query->whereIn('cs.sexo', $flat);
                    }
                }

                self::range($query, $r, 'clt_renda_min', 'clt_renda_max', 'cs.valor_renda');
                self::range($query, $r, 'clt_base_min', 'clt_base_max', 'cs.valor_base_margem');
                self::range($query, $r, 'clt_margem_min', 'clt_margem_max', 'cs.margem_disponivel');
                self::range($query, $r, 'clt_prestacao_min', 'clt_prestacao_max', 'cs.valor_max_prestacao');

                if ($r->filled('clt_ativos_min') || $r->filled('clt_ativos_max')) {
                    $min = (int) $r->input('clt_ativos_min', 0);
                    $max = (int) $r->input('clt_ativos_max', PHP_INT_MAX);
                    $query->whereBetween('cs.qtd_emprestimos_ativos_suspensos', [$min, $max]);
                }

                if (($v = self::yn($r->input('clt_tem_ativos'))) !== null) {
                    if ($v === 'sim') {
                        $query->where('cs.qtd_emprestimos_ativos_suspensos', '>', 0);
                    } else {
                        $query->where(function ($q) {
                            $q->whereNull('cs.qtd_emprestimos_ativos_suspensos')
                                ->orWhere('cs.qtd_emprestimos_ativos_suspensos', '=', 0);
                        });
                    }
                }

                if (($v = self::yn($r->input('clt_tem_legados'))) !== null) {
                    if ($v === 'sim') {
                        $query->where('cs.emprestimos_legados', '=', 1);
                    } else {
                        $query->where(function ($q) {
                            $q->whereNull('cs.emprestimos_legados')
                                ->orWhere('cs.emprestimos_legados', '=', 0);
                        });
                    }
                }
            }
        }

        // ======== MERCANTIL – filtros específicos ========
        if ($mode === 'mercantil') {
            $situacao = self::normalizeMercantilSituacao($r->input('mercantil_situacao', null));
            $statuses = self::requestList($r, 'mercantil_status');
            $origensMercantil = self::requestList($r, 'mercantil_origens');

            if ($origensMercantil) {
                $query->whereIn('ijm.origin', $origensMercantil);
            }

            $hasSnapshotScopedFilters =
                !empty($statuses) ||
                $r->filled('mercantil_consulta_from') || $r->filled('mercantil_consulta_to') ||
                $r->filled('mercantil_import_from') || $r->filled('mercantil_import_to') ||
                $r->filled('mercantil_parcela_min') || $r->filled('mercantil_parcela_max') ||
                $r->filled('mercantil_qtd_parcelas_min') || $r->filled('mercantil_qtd_parcelas_max');

            if ($situacao === 'sem_consulta') {
                $query->whereNull('ms.cpf');
            } else {
                if ($situacao === 'consultado' || $hasSnapshotScopedFilters) {
                    $query->whereNotNull('ms.cpf');
                }

                if (!empty($statuses)) {
                    $query->whereIn('ms.status', $statuses);
                }

                if ($r->filled('mercantil_consulta_from') || $r->filled('mercantil_consulta_to')) {
                    $from = $r->input('mercantil_consulta_from', '1900-01-01');
                    $to = $r->input('mercantil_consulta_to', now()->toDateString());
                    $query->whereBetween('ms.data_hora_origem', ["{$from} 00:00:00", "{$to} 23:59:59"]);
                }

                if ($r->filled('mercantil_import_from') || $r->filled('mercantil_import_to')) {
                    $from = $r->input('mercantil_import_from', '1900-01-01');
                    $to = $r->input('mercantil_import_to', now()->toDateString());
                    $query->whereBetween('ms.updated_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
                }

                self::range($query, $r, 'mercantil_parcela_min', 'mercantil_parcela_max', 'ms.valor_parcela');

                if ($r->filled('mercantil_qtd_parcelas_min') || $r->filled('mercantil_qtd_parcelas_max')) {
                    $min = (int) $r->input('mercantil_qtd_parcelas_min', 0);
                    $max = (int) $r->input('mercantil_qtd_parcelas_max', PHP_INT_MAX);
                    $query->whereBetween('ms.quantidade_parcelas', [$min, $max]);
                }
            }
        }

        // ======== FGTS OFF – filtros específicos ========
        if ($mode === 'fgts') {
            $fgtsStatus = self::normalizeFgtsStatus($r->input('fgts_status', null));
            $hasFgtsDateFilter = $r->filled('fgts_consulta_from') || $r->filled('fgts_consulta_to');

            if ($fgtsStatus === 'autorizado') {
                $query->where('fos.authorized', 1);
            } elseif ($fgtsStatus === 'nao_autorizado') {
                $query->where('fos.authorized', 0);
            } elseif ($fgtsStatus === 'nao_consultado') {
                $query->whereNull('fos.cpf');
            }

            if ($hasFgtsDateFilter) {
                $from = $r->input('fgts_consulta_from', '1900-01-01');
                $to = $r->input('fgts_consulta_to', now()->toDateString());
                $query->whereBetween('fos.updated_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
            }
        }

        // ordem final
        if ($exportMode) {
            return $query->orderBy('leads.id', 'asc');
        }

        if ($mode === 'mercantil') {
            return $query
                ->orderByDesc('ms.updated_at')
                ->orderByDesc('leads.updated_at');
        }

        return $query->orderByDesc('leads.updated_at');
    }

    private static function applyMassFilter(Builder $q, Request $r, string $key, array $columns): void
    {
        if (!$r->filled($key))
            return;

        $input = $r->input($key);
        $raw = is_array($input) ? $input : preg_split('/[\s,;]+/', (string) $input);

        $raw = array_values(array_filter(
            array_map(fn($v) => trim((string) $v), $raw),
            fn($v) => $v !== ''
        ));
        if (empty($raw))
            return;

        if ($key === 'names') {
            $values = [];
            foreach ($raw as $v) {
                $normalized = preg_replace('/\s+/', ' ', $v);
                if ($normalized === null)
                    continue;
                if (mb_strlen($normalized) < 2)
                    continue;
                $values[] = mb_substr($normalized, 0, 100);
            }

            $values = array_values(array_unique($values));
            if (empty($values))
                return;

            $maxTerms = self::maxMassFilterTerms($key);
            if (count($values) > $maxTerms) {
                $values = array_slice($values, 0, $maxTerms);
            }

            $chunkSize = max(1, (int) config('leads.filters.mass_filter.names_chunk_size', 20));

            $q->where(function (Builder $outer) use ($columns, $values, $chunkSize) {
                foreach (array_chunk($values, $chunkSize) as $chunk) {
                    $outer->orWhere(function (Builder $sub) use ($columns, $chunk) {
                        foreach ($columns as $col) {
                            foreach ($chunk as $v)
                                $sub->orWhere($col, 'like', "%{$v}%");
                        }
                    });
                }
            });
            return;
        }

        if ($key === 'cpf') {
            $normalized = [];
            foreach ($raw as $v) {
                $n = \App\Support\Cpf::normalize((string) $v);
                if ($n !== null)
                    $normalized[] = $n;
            }
            $values = array_values(array_unique($normalized));
        } elseif ($key === 'phones') {
            $normPhones = [];
            foreach ($raw as $v) {
                $d = preg_replace('/\D+/', '', (string) $v);
                if (strlen($d) > 11 && substr($d, 0, 2) === '55')
                    $d = substr($d, 2);
                if (strlen($d) === 10 || strlen($d) === 11)
                    $normPhones[] = $d;
            }
            $values = array_values(array_unique($normPhones));
        } else {
            $values = array_values(array_unique($raw));
        }

        if (empty($values))
            return;

        $maxTerms = self::maxMassFilterTerms($key);
        if (count($values) > $maxTerms) {
            $values = array_slice($values, 0, $maxTerms);
        }

        $chunkSize = 1000;
        $chunks = array_chunk($values, $chunkSize);

        $q->where(function ($sub) use ($columns, $chunks) {
            foreach ($columns as $col) {
                foreach ($chunks as $set)
                    $sub->orWhereIn($col, $set);
            }
        });
    }

    private static function applyBirthMonthFilter(Builder $query, array $birth): void
    {
        if (empty($birth))
            return;

        $months = array_values(array_unique(array_filter(
            array_map(fn($m) => ($m = (int) $m) >= 1 && $m <= 12 ? $m : null, $birth)
        )));
        sort($months);

        if (empty($months) || count($months) === 12)
            return;

        $query->whereNotNull('leads.data_nascimento')
            ->whereIn(DB::raw('MONTH(leads.data_nascimento)'), $months);
    }

    private static function maxMassFilterTerms(string $key): int
    {
        return match ($key) {
            'names' => max(1, (int) config('leads.filters.mass_filter.names_max_terms', 120)),
            'cpf' => max(1, (int) config('leads.filters.mass_filter.cpf_max_terms', 5000)),
            'phones' => max(1, (int) config('leads.filters.mass_filter.phones_max_terms', 5000)),
            default => max(1, (int) config('leads.filters.mass_filter.default_max_terms', 1000)),
        };
    }

    private static function addLatestOriginSelect(Builder $query, string $alias, string $type): void
    {
        $query->addSelect([
            $alias => self::latestOriginSubquery($type),
        ]);
    }

    private static function latestOriginSubquery(string $type): \Closure
    {
        if ($type === 'mercantil') {
            return function ($q) {
                $q->select('ij.origin')
                    ->from('mercantil_snapshots as ms')
                    ->join('import_jobs as ij', 'ij.id', '=', 'ms.job_id')
                    ->whereColumn('ms.cpf', 'leads.cpf')
                    ->where('ij.type', 'mercantil')
                    ->orderByDesc('ms.updated_at')
                    ->orderByDesc('ms.job_id')
                    ->limit(1);
            };
        }

        return function ($q) use ($type) {
            $q->select('ij.origin')
                ->from('lead_imports as li')
                ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                ->whereColumn('li.lead_id', 'leads.id')
                ->where('ij.type', $type)
                ->orderByDesc('li.created_at')
                ->orderByDesc('li.import_job_id')
                ->limit(1);
        };
    }

    private static function applyLatestOriginFilter(Builder $query, string $type, array $origins): void
    {
        if ($type === 'mercantil') {
            $query->whereExists(function ($sq) use ($origins) {
                $sq->selectRaw('1')
                    ->from('mercantil_snapshots as ms')
                    ->join('import_jobs as ij', 'ij.id', '=', 'ms.job_id')
                    ->whereColumn('ms.cpf', 'leads.cpf')
                    ->where('ij.type', 'mercantil')
                    ->whereIn('ij.origin', $origins);
            });
            return;
        }

        $query->whereIn('leads.id', function ($sq) use ($type, $origins) {
            $sq->select('li.lead_id')
                ->from('lead_imports as li')
                ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                ->where('ij.type', $type)
                ->whereIn('ij.origin', $origins)
                ->whereRaw(
                    'li.import_job_id = (
                        SELECT li2.import_job_id
                        FROM lead_imports li2
                        JOIN import_jobs ij2 ON ij2.id = li2.import_job_id AND ij2.type = ?
                        WHERE li2.lead_id = li.lead_id
                        ORDER BY li2.created_at DESC, li2.import_job_id DESC
                        LIMIT 1
                    )',
                    [$type]
                );
        });
    }

    private static function requestList(Request $r, string $key): array
    {
        if (!$r->filled($key)) {
            return [];
        }

        $value = $r->input($key);
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(
            array_map(fn($item) => trim((string) $item), $items),
            fn($item) => $item !== ''
        ));
    }

    private static function range($sq, Request $r, string $minKey, string $maxKey, string $column): void
    {
        $hasMin = $r->filled($minKey);
        $hasMax = $r->filled($maxKey);
        if (!$hasMin && !$hasMax)
            return;

        $min = $hasMin ? (float) str_replace(',', '.', (string) $r->input($minKey)) : 0.0;
        $max = $hasMax ? (float) str_replace(',', '.', (string) $r->input($maxKey)) : 1.0e18;
        $sq->whereBetween($column, [$min, $max]);
    }

    private static function yn($v): ?string
    {
        if ($v === null || $v === '')
            return null;
        $s = mb_strtolower(trim((string) $v));
        $s = str_replace(['ã', 'â', 'á', 'à', 'ä'], 'a', $s);
        if (in_array($s, ['sim', 's', '1', 'true', 't', 'yes', 'y'], true))
            return 'sim';
        if (in_array($s, ['nao', 'n', '0', 'false', 'f', 'no'], true))
            return 'nao';
        return null;
    }

    private static function normalizeFgtsStatus($v): ?string
    {
        if ($v === null || $v === '')
            return null;
        if (!is_string($v))
            return null;

        $s = trim(mb_strtolower($v));
        $s = str_replace(['ã', 'â', 'á', 'à', 'ä'], 'a', $s);
        $s = str_replace(['ê', 'é', 'è', 'ë'], 'e', $s);
        $s = str_replace(['î', 'í', 'ì', 'ï'], 'i', $s);
        $s = str_replace(['õ', 'ô', 'ó', 'ò', 'ö'], 'o', $s);
        $s = str_replace(['û', 'ú', 'ù', 'ü'], 'u', $s);
        $s = preg_replace('/[\s\-]+/u', '_', $s);

        $map = [
            'autorizado' => 'autorizado',
            'nao_autorizado' => 'nao_autorizado',
            'nao_consultado' => 'nao_consultado',
        ];

        return $map[$s] ?? null;
    }

    private static function normalizeCltSituacao($v): ?string
    {
        if ($v === null || $v === '')
            return null;
        if (!is_string($v))
            return null;

        $s = trim(mb_strtolower($v));
        $s = str_replace(['ã', 'â', 'á', 'à', 'ä'], 'a', $s);
        $s = str_replace(['ê', 'é', 'è', 'ë'], 'e', $s);
        $s = preg_replace('/[\s\-]+/u', '_', $s);

        $map = [
            'elegivel' => 'elegivel',
            'nao_elegivel' => 'nao_elegivel',
            'nao_encontrado' => 'nao_encontrado',
        ];

        return $map[$s] ?? null;
    }

    private static function normalizeMercantilSituacao($v): ?string
    {
        if ($v === null || $v === '')
            return null;
        if (!is_string($v))
            return null;

        $s = trim(mb_strtolower($v));
        $s = str_replace(['ã', 'â', 'á', 'à', 'ä'], 'a', $s);
        $s = str_replace(['ê', 'é', 'è', 'ë'], 'e', $s);
        $s = preg_replace('/[\s\-]+/u', '_', $s);

        $map = [
            'consultado' => 'consultado',
            'sem_consulta' => 'sem_consulta',
            // compatibilidade com valores antigos do front:
            'sucesso' => 'consultado',
            'com_erro' => 'consultado',
            'erro' => 'consultado',
        ];

        return $map[$s] ?? null;
    }
}
