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
        $mode = strtolower((string) $r->input('mode', 'fgts')); // 'fgts' | 'clt'

        // ---- FGTS OFF: colunas projetadas (quando necessário) ----
        $needFgtsAuthorizedCol = $exportMode
            ? in_array('fgts_off_authorized', $columnsForExport, true)
            : ($mode === 'fgts');

        $needFgtsConsultadoCol = $exportMode
            ? in_array('fgts_off_consultado_em', $columnsForExport, true)
            : ($mode === 'fgts');

        if ($exportMode) {
            $select = ['leads.id'];
            $allowedLeadCols = [
                'cpf',
                'nome',
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

            if (in_array('ultima_origem_cadastral', $columnsForExport, true)) {
                $query->addSelect([
                    'ultima_origem_cadastral' => function ($q) {
                        $q->select('ij.origin')
                            ->from('lead_imports as li')
                            ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                            ->whereColumn('li.lead_id', 'leads.id')
                            ->where('ij.type', 'cadastral')
                            ->orderByDesc('li.created_at')
                            ->orderByDesc('li.import_job_id')
                            ->limit(1);
                    }
                ]);
            }

            if (in_array('ultima_origem_higienizacao', $columnsForExport, true)) {
                $query->addSelect([
                    'ultima_origem_higienizacao' => function ($q) {
                        $q->select('ij.origin')
                            ->from('lead_imports as li')
                            ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                            ->whereColumn('li.lead_id', 'leads.id')
                            ->where('ij.type', 'higienizacao')
                            ->orderByDesc('li.created_at')
                            ->orderByDesc('li.import_job_id')
                            ->limit(1);
                    }
                ]);
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
                $query->addSelect([
                    'fgts_off_authorized' => DB::table('fgts_off_snapshots as fos')
                        ->select('authorized')
                        ->whereColumn('fos.cpf', 'leads.cpf')
                        ->limit(1),
                ]);
            }
            if ($needFgtsConsultadoCol) {
                $query->addSelect([
                    'fgts_off_consultado_em' => DB::table('fgts_off_snapshots as fos')
                        ->select('updated_at')
                        ->whereColumn('fos.cpf', 'leads.cpf')
                        ->limit(1),
                ]);
            }

            // CLT no export (se pedido explicitamente)
            $cltFields = [
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
                'not_found'
            ];
            $needAnyClt = (bool) array_intersect($columnsForExport, array_merge($cltFields, ['clt_consultado_em', 'clt_dados_atualizados_em']));
            if ($needAnyClt) {
                foreach ($cltFields as $f) {
                    if (in_array($f, $columnsForExport, true)) {
                        $query->addSelect([$f => DB::table('clt_snapshots as cs')->select($f)->whereColumn('cs.cpf', 'leads.cpf')->limit(1)]);
                    }
                }
                if (in_array('clt_consultado_em', $columnsForExport, true)) {
                    $query->addSelect(['clt_consultado_em' => DB::table('clt_snapshots as cs')->select('consulted_at')->whereColumn('cs.cpf', 'leads.cpf')->limit(1)]);
                }
                if (in_array('clt_dados_atualizados_em', $columnsForExport, true)) {
                    $query->addSelect(['clt_dados_atualizados_em' => DB::table('clt_snapshots as cs')->select('updated_at')->whereColumn('cs.cpf', 'leads.cpf')->limit(1)]);
                }
            }
        } else {
            // ====== LISTA (API) ======
            $query = Lead::query()->select('leads.*');

            if ($mode === 'fgts') {
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
                    'ultima_origem_cadastral' => function ($q) {
                        $q->select('ij.origin')
                            ->from('lead_imports as li')
                            ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                            ->whereColumn('li.lead_id', 'leads.id')
                            ->where('ij.type', 'cadastral')
                            ->orderByDesc('li.created_at')
                            ->orderByDesc('li.import_job_id')
                            ->limit(1);
                    }
                ]);

                $query->addSelect([
                    'ultima_origem_higienizacao' => function ($q) {
                        $q->select('ij.origin')
                            ->from('lead_imports as li')
                            ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                            ->whereColumn('li.lead_id', 'leads.id')
                            ->where('ij.type', 'higienizacao')
                            ->orderByDesc('li.created_at')
                            ->orderByDesc('li.import_job_id')
                            ->limit(1);
                    }
                ]);

                if ($needFgtsAuthorizedCol) {
                    $query->addSelect([
                        'fgts_off_authorized' => DB::table('fgts_off_snapshots as fos')
                            ->select('authorized')
                            ->whereColumn('fos.cpf', 'leads.cpf')
                            ->limit(1),
                    ]);
                }
                if ($needFgtsConsultadoCol) {
                    $query->addSelect([
                        'fgts_off_consultado_em' => DB::table('fgts_off_snapshots as fos')
                            ->select('updated_at')
                            ->whereColumn('fos.cpf', 'leads.cpf')
                            ->limit(1),
                    ]);
                }
            } else {
                // ===== MODO CLT
                $query->addSelect([
                    'elegivel' => DB::table('clt_snapshots as cs')->select('elegivel')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'idade' => DB::table('clt_snapshots as cs')->select('idade')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'sexo' => DB::table('clt_snapshots as cs')->select('sexo')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'data_admissao' => DB::table('clt_snapshots as cs')->select('data_admissao')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'meses_admissao' => DB::table('clt_snapshots as cs')->select('meses_admissao')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'valor_renda' => DB::table('clt_snapshots as cs')->select('valor_renda')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'valor_base_margem' => DB::table('clt_snapshots as cs')->select('valor_base_margem')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'margem_disponivel' => DB::table('clt_snapshots as cs')->select('margem_disponivel')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'valor_max_prestacao' => DB::table('clt_snapshots as cs')->select('valor_max_prestacao')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'categoria_trabalhador_codigo' => DB::table('clt_snapshots as cs')->select('categoria_trabalhador_codigo')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'inicio_atividade_empregador' => DB::table('clt_snapshots as cs')->select('inicio_atividade_empregador')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'qtd_emprestimos_ativos_suspensos' => DB::table('clt_snapshots as cs')->select('qtd_emprestimos_ativos_suspensos')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'emprestimos_legados' => DB::table('clt_snapshots as cs')->select('emprestimos_legados')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'not_found' => DB::table('clt_snapshots as cs')->select('not_found')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),

                    // datas separadas
                    'clt_consultado_em' => DB::table('clt_snapshots as cs')->select('consulted_at')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),
                    'clt_dados_atualizados_em' => DB::table('clt_snapshots as cs')->select('updated_at')->whereColumn('cs.cpf', 'leads.cpf')->limit(1),

                    'ultima_origem_cadastral' => function ($q) {
                        $q->select('ij.origin')
                            ->from('lead_imports as li')
                            ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                            ->whereColumn('li.lead_id', 'leads.id')
                            ->where('ij.type', 'cadastral')
                            ->orderByDesc('li.created_at')
                            ->orderByDesc('li.import_job_id')
                            ->limit(1);
                    },
                ]);
            }
        }

        // ----- busca geral -----
        if ($r->filled('search')) {
            $termRaw = (string) $r->input('search');
            $termLike = '%' . $termRaw . '%';
            $digits = preg_replace('/\D+/', '', $termRaw) ?: '';

            $query->where(function (Builder $q) use ($termLike, $digits) {
                $q->where('nome', 'like', $termLike)
                    ->orWhere('fone1', 'like', $termLike)
                    ->orWhere('fone2', 'like', $termLike)
                    ->orWhere('fone3', 'like', $termLike)
                    ->orWhere('fone4', 'like', $termLike);

                if ($digits !== '') {
                    $norm = Cpf::normalize($digits);
                    if ($norm)
                        $q->orWhere('cpf', $norm);
                    $q->orWhere('cpf', 'like', '%' . $digits . '%');
                } else {
                    $q->orWhere('cpf', 'like', $termLike);
                }
            });
        }

        // ===== filtros FGTS (não se aplicam ao CLT) =====
        if ($mode === 'fgts') {
            $motivos = $r->filled('motivos') ? (is_array($r->motivos) ? $r->motivos : explode(',', (string) $r->motivos)) : [];
            if ($motivos)
                $query->whereIn('consulta', $motivos);

            $origHig = $r->filled('origens_hig') ? (is_array($r->origens_hig) ? $r->origens_hig : explode(',', (string) $r->origens_hig)) : [];
            if ($origHig) {
                $query->whereIn('leads.id', function ($sq) use ($origHig) {
                    $sq->select('li.lead_id')
                        ->from('lead_imports as li')
                        ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                        ->where('ij.type', 'higienizacao')
                        ->whereIn('ij.origin', $origHig)
                        ->whereRaw('li.import_job_id = (
                            SELECT li2.import_job_id
                            FROM lead_imports li2
                            JOIN import_jobs ij2 ON ij2.id = li2.import_job_id AND ij2.type = \'higienizacao\'
                            WHERE li2.lead_id = li.lead_id
                            ORDER BY li2.created_at DESC, li2.import_job_id DESC
                            LIMIT 1
                       )');
                });
            }

            if ($r->filled('contract_from') || $r->filled('contract_to')) {
                $from = $r->input('contract_from', '1900-01-01');
                $to = $r->input('contract_to', now()->toDateString());
                $query->whereHas('contracts', fn(Builder $q) => $q->whereBetween('data_contrato', [$from, $to]));
            }

            $vendors = $r->filled('vendors') ? (is_array($r->vendors) ? $r->vendors : explode(',', (string) $r->vendors)) : [];
            if ($vendors) {
                $clean = array_map(fn($n) => Vendor::clean($n), $vendors);
                $query->whereHas('contracts.vendor', fn(Builder $q) => $q->whereIn('name_clean', $clean));
            }
        }

        // filtros de origem cadastral (válido para ambos)
        $origensCad = $r->filled('origens') ? (is_array($r->origens) ? $r->origens : explode(',', (string) $r->origens)) : [];
        if ($origensCad) {
            $query->whereIn('leads.id', function ($sq) use ($origensCad) {
                $sq->select('li.lead_id')
                    ->from('lead_imports as li')
                    ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                    ->where('ij.type', 'cadastral')
                    ->whereIn('ij.origin', $origensCad)
                    ->whereRaw('li.import_job_id = (
                        SELECT li2.import_job_id
                        FROM lead_imports li2
                        JOIN import_jobs ij2 ON ij2.id = li2.import_job_id AND ij2.type = \'cadastral\'
                        WHERE li2.lead_id = li.lead_id
                        ORDER BY li2.created_at DESC, li2.import_job_id DESC
                        LIMIT 1
                   )');
            });
        }

        // datas gerais de atualização do LEAD
        if ($r->filled('date_from') || $r->filled('date_to')) {
            $from = $r->input('date_from', '1900-01-01');
            $to = $r->input('date_to', now()->toDateString());
            $query->whereBetween('data_atualizacao', ["{$from} 00:00:00", "{$to} 23:59:59"]);
        }

        self::applyMassFilter($query, $r, 'cpf', ['cpf']);
        self::applyMassFilter($query, $r, 'names', ['nome']);
        self::applyMassFilter($query, $r, 'phones', ['fone1', 'fone2', 'fone3', 'fone4']);

        $birth = $r->filled('birth_month') ? (is_array($r->birth_month) ? $r->birth_month : explode(',', (string) $r->birth_month)) : [];
        if ($birth) {
            $months = array_values(array_filter(array_map(fn($m) => ($m = (int) $m) >= 1 && $m <= 12 ? $m : null, $birth)));
            if ($months)
                $query->whereIn(DB::raw('MONTH(leads.data_nascimento)'), $months);
        }

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
                $query->whereNotIn('leads.cpf', function ($sq) {
                    $sq->select('cpf')->from('clt_snapshots');
                });
            } elseif ($consultado === 'sim' || $hasCltFilters) {
                $query->whereIn('leads.cpf', function ($sq) use ($r, $situacao) {
                    $sq->from('clt_snapshots as cs')->select('cpf');

                    if ($situacao !== null) {
                        if ($situacao === 'elegivel') {
                            $sq->where('cs.not_found', 0)->where('cs.elegivel', 1);
                        } elseif ($situacao === 'nao_elegivel') {
                            $sq->where('cs.not_found', 0)
                                ->where(function ($q) {
                                    $q->where('cs.elegivel', 0)->orWhereNull('cs.elegivel'); });
                        } elseif ($situacao === 'nao_encontrado') {
                            $sq->where('cs.not_found', 1);
                        }
                    } else {
                        if (($v = self::yn($r->input('clt_elegivel'))) !== null) {
                            $sq->where('cs.elegivel', $v === 'sim' ? 1 : 0);
                        }
                        if (($v = self::yn($r->input('clt_not_found'))) !== null) {
                            $sq->where('cs.not_found', $v === 'sim' ? 1 : 0);
                        }
                    }

                    // AGORA filtra por quando NÓS consultamos
                    if ($r->filled('clt_consulta_from') || $r->filled('clt_consulta_to')) {
                        $from = $r->input('clt_consulta_from', '1900-01-01');
                        $to = $r->input('clt_consulta_to', now()->toDateString());
                        $sq->whereBetween('cs.consulted_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
                    }

                    if ($r->filled('clt_admissao_from') || $r->filled('clt_admissao_to')) {
                        $from = $r->input('clt_admissao_from', '1900-01-01');
                        $to = $r->input('clt_admissao_to', now()->toDateString());
                        $sq->whereBetween('cs.data_admissao', [$from, $to]);
                    }
                    if ($r->filled('clt_meses_min') || $r->filled('clt_meses_max')) {
                        $min = (int) $r->input('clt_meses_min', 0);
                        $max = (int) $r->input('clt_meses_max', PHP_INT_MAX);
                        $sq->whereBetween('cs.meses_admissao', [$min, $max]);
                    }
                    if ($r->filled('clt_inicio_empregador_from') || $r->filled('clt_inicio_empregador_to')) {
                        $from = $r->input('clt_inicio_empregador_from', '1900-01-01');
                        $to = $r->input('clt_inicio_empregador_to', now()->toDateString());
                        $sq->whereBetween('cs.inicio_atividade_empregador', [$from, $to]);
                    }
                    if ($r->filled('clt_categoria_codigos')) {
                        $raw = is_array($r->clt_categoria_codigos)
                            ? $r->clt_categoria_codigos
                            : preg_split('/[\s,;]+/', (string) $r->clt_categoria_codigos);
                        $codes = array_values(array_filter(array_unique(array_map('trim', $raw)), fn($v) => $v !== ''));
                        if ($codes)
                            $sq->whereIn('cs.categoria_trabalhador_codigo', $codes);
                    }

                    if ($r->filled('clt_idade_min') || $r->filled('clt_idade_max')) {
                        $min = (int) $r->input('clt_idade_min', 0);
                        $max = (int) $r->input('clt_idade_max', 200);
                        $sq->whereBetween('cs.idade', [$min, $max]);
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
                        if ($flat)
                            $sq->whereIn('cs.sexo', $flat);
                    }

                    self::range($sq, $r, 'clt_renda_min', 'clt_renda_max', 'cs.valor_renda');
                    self::range($sq, $r, 'clt_base_min', 'clt_base_max', 'cs.valor_base_margem');
                    self::range($sq, $r, 'clt_margem_min', 'clt_margem_max', 'cs.margem_disponivel');
                    self::range($sq, $r, 'clt_prestacao_min', 'clt_prestacao_max', 'cs.valor_max_prestacao');

                    if ($r->filled('clt_ativos_min') || $r->filled('clt_ativos_max')) {
                        $min = (int) $r->input('clt_ativos_min', 0);
                        $max = (int) $r->input('clt_ativos_max', PHP_INT_MAX);
                        $sq->whereBetween('cs.qtd_emprestimos_ativos_suspensos', [$min, $max]);
                    }
                    if (($v = self::yn($r->input('clt_tem_ativos'))) !== null) {
                        if ($v === 'sim')
                            $sq->where('cs.qtd_emprestimos_ativos_suspensos', '>', 0);
                        else
                            $sq->where(function ($q) {
                                $q->whereNull('cs.qtd_emprestimos_ativos_suspensos')->orWhere('cs.qtd_emprestimos_ativos_suspensos', '=', 0); });
                    }
                    if (($v = self::yn($r->input('clt_tem_legados'))) !== null) {
                        if ($v === 'sim') {
                            $sq->where('cs.emprestimos_legados', '=', 1);
                        } else {
                            $sq->where(function ($q) {
                                $q->whereNull('cs.emprestimos_legados')->orWhere('cs.emprestimos_legados', '=', 0); });
                        }
                    }
                });
            }
        }

        // ======== FGTS OFF – filtros específicos ========
        if ($mode === 'fgts') {
            $fgtsStatus = self::normalizeFgtsStatus($r->input('fgts_status', null));
            $hasFgtsDateFilter = $r->filled('fgts_consulta_from') || $r->filled('fgts_consulta_to');

            if ($fgtsStatus === 'autorizado') {
                $query->whereIn('leads.cpf', function ($sq) {
                    $sq->select('cpf')->from('fgts_off_snapshots')->where('authorized', 1); });
            } elseif ($fgtsStatus === 'nao_autorizado') {
                $query->whereIn('leads.cpf', function ($sq) {
                    $sq->select('cpf')->from('fgts_off_snapshots')->where('authorized', 0); });
            } elseif ($fgtsStatus === 'nao_consultado') {
                $query->whereNotIn('leads.cpf', function ($sq) {
                    $sq->select('cpf')->from('fgts_off_snapshots'); });
            }

            if ($hasFgtsDateFilter) {
                $from = $r->input('fgts_consulta_from', '1900-01-01');
                $to = $r->input('fgts_consulta_to', now()->toDateString());
                $query->whereIn('leads.cpf', function ($sq) use ($from, $to) {
                    $sq->select('cpf')->from('fgts_off_snapshots')->whereBetween('updated_at', ["{$from} 00:00:00", "{$to} 23:59:59"]);
                });
            }
        }

        // ordem final
        return $exportMode
            ? $query->orderBy('leads.id', 'asc')
            : $query->latest('updated_at');
    }

    private static function applyMassFilter(Builder $q, Request $r, string $key, array $columns): void
    {
        if (!$r->filled($key))
            return;

        $input = $r->input($key);
        $raw = is_array($input) ? $input : preg_split('/[\s,;]+/', (string) $input);

        $raw = array_values(array_filter($raw, fn($v) => $v !== '' && $v !== null));
        if (empty($raw))
            return;

        if ($key === 'names') {
            $values = array_values(array_unique($raw));
            $q->where(function ($sub) use ($columns, $values) {
                foreach ($columns as $col) {
                    foreach ($values as $v)
                        $sub->orWhere($col, 'like', "%{$v}%");
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

        $chunkSize = 1000;
        $chunks = array_chunk($values, $chunkSize);

        $q->where(function ($sub) use ($columns, $chunks) {
            foreach ($columns as $col) {
                foreach ($chunks as $set)
                    $sub->orWhereIn($col, $set);
            }
        });
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
}
