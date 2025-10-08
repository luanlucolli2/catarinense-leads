<?php

namespace App\Http\Filters;

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
        $liberaExpr = "CAST(REPLACE(REGEXP_REPLACE(COALESCE(leads.libera, ''), '[^0-9.,-]', ''), ',', '.') AS DECIMAL(20,2))";
        $consultaSaldoFacta = "TRIM(leads.consulta) = 'Saldo FACTA'";
        $isElegivel = "CASE WHEN ($consultaSaldoFacta AND $liberaExpr > 0) THEN 1 ELSE 0 END";

        $exportMode = is_array($columnsForExport);

        // ---- FGTS OFF: colunas a projetar (só quando necessário) ----
        $needFgtsAuthorizedCol = $exportMode
            ? in_array('fgts_off_authorized', $columnsForExport, true)
            : true; // no modo lista expomos para o front

        $needFgtsConsultadoCol = $exportMode
            ? in_array('fgts_off_consultado_em', $columnsForExport, true)
            : true;

        if ($exportMode) {
            $select = ['leads.id'];
            $allowedLeadCols = [
                'cpf','nome','data_nascimento',
                'fone1','fone2','fone3','fone4',
                'classe_fone1','classe_fone2','classe_fone3','classe_fone4',
                'consulta','saldo','libera','data_atualizacao',
            ];
            foreach ($columnsForExport as $col) {
                if (in_array($col, $allowedLeadCols, true)) $select[] = "leads.$col";
                if ($col === 'id' && !in_array('leads.id', $select, true)) $select[] = 'leads.id';
            }
            $query = Lead::query()->select($select);

            // ===== novas colunas de origem (últimas) =====
            if (in_array('ultima_origem_cadastral', $columnsForExport, true)) {
                $query->addSelect(['ultima_origem_cadastral' => function ($q) {
                    $q->select('ij.origin')
                      ->from('lead_imports as li')
                      ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                      ->whereColumn('li.lead_id', 'leads.id')
                      ->where('ij.type', 'cadastral')
                      ->orderByDesc('li.created_at')
                      ->orderByDesc('li.import_job_id')
                      ->limit(1);
                }]);
            }

            if (in_array('ultima_origem_higienizacao', $columnsForExport, true)) {
                $query->addSelect(['ultima_origem_higienizacao' => function ($q) {
                    $q->select('ij.origin')
                      ->from('lead_imports as li')
                      ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                      ->whereColumn('li.lead_id', 'leads.id')
                      ->where('ij.type', 'higienizacao')
                      ->orderByDesc('li.created_at')
                      ->orderByDesc('li.import_job_id')
                      ->limit(1);
                }]);
            }

            if (in_array('status', $columnsForExport, true)) {
                if (!in_array('leads.consulta', $select, true)) $query->addSelect('leads.consulta');
                if (!in_array('leads.libera', $select, true))   $query->addSelect('leads.libera');
                $query->addSelect(DB::raw("$isElegivel AS status_flag"));
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

            // Subselects do snapshot FGTS OFF apenas se requeridos/filtros
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
            $query = Lead::query()
                ->select('leads.*')
                ->withCount('contracts')
                // ===== novas colunas por padrão na lista =====
                ->addSelect(['ultima_origem_cadastral' => function ($q) {
                    $q->select('ij.origin')
                      ->from('lead_imports as li')
                      ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                      ->whereColumn('li.lead_id', 'leads.id')
                      ->where('ij.type', 'cadastral')
                      ->orderByDesc('li.created_at')
                      ->orderByDesc('li.import_job_id')
                      ->limit(1);
                }])
                ->addSelect(['ultima_origem_higienizacao' => function ($q) {
                    $q->select('ij.origin')
                      ->from('lead_imports as li')
                      ->join('import_jobs as ij', 'ij.id', '=', 'li.import_job_id')
                      ->whereColumn('li.lead_id', 'leads.id')
                      ->where('ij.type', 'higienizacao')
                      ->orderByDesc('li.created_at')
                      ->orderByDesc('li.import_job_id')
                      ->limit(1);
                }]);

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
        }

        // ----- filtros de busca -----
        if ($r->filled('search')) {
            $termRaw  = (string) $r->input('search');
            $termLike = '%' . $termRaw . '%';
            $digits   = preg_replace('/\D+/', '', $termRaw) ?: '';

            $query->where(function (Builder $q) use ($termLike, $digits) {
                $q->where('nome','like',$termLike)
                  ->orWhere('fone1','like',$termLike)
                  ->orWhere('fone2','like',$termLike)
                  ->orWhere('fone3','like',$termLike)
                  ->orWhere('fone4','like',$termLike);

                if ($digits !== '') {
                    $norm = Cpf::normalize($digits);
                    if ($norm) $q->orWhere('cpf', $norm);
                    $q->orWhere('cpf', 'like', '%'.$digits.'%');
                } else {
                    $q->orWhere('cpf','like',$termLike);
                }
            });
        }

        if ($r->filled('status') && $r->status !== 'todos') {
            $query->whereRaw($r->status === 'elegiveis' ? "$isElegivel = 1" : "$isElegivel = 0");
        }

        $motivos = $r->filled('motivos') ? (is_array($r->motivos) ? $r->motivos : explode(',', (string)$r->motivos)) : [];
        if ($motivos) $query->whereIn('consulta', $motivos);

        // ======= NOVO: filtros de origem baseados na ÚLTIMA por tipo =======
        $origensCad = $r->filled('origens') ? (is_array($r->origens) ? $r->origens : explode(',', (string)$r->origens)) : [];
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
                        JOIN import_jobs ij2 ON ij2.id = li2.import_job_id AND ij2.type = "cadastral"
                        WHERE li2.lead_id = li.lead_id
                        ORDER BY li2.created_at DESC, li2.import_job_id DESC
                        LIMIT 1
                   )');
            });
        }

        $origHig = $r->filled('origens_hig') ? (is_array($r->origens_hig) ? $r->origens_hig : explode(',', (string)$r->origens_hig)) : [];
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
                        JOIN import_jobs ij2 ON ij2.id = li2.import_job_id AND ij2.type = "higienizacao"
                        WHERE li2.lead_id = li.lead_id
                        ORDER BY li2.created_at DESC, li2.import_job_id DESC
                        LIMIT 1
                   )');
            });
        }

        // datas e demais
        if ($r->filled('date_from') || $r->filled('date_to')) {
            $from = $r->input('date_from', '1900-01-01');
            $to   = $r->input('date_to',   now()->toDateString());
            $query->whereBetween('data_atualizacao', ["{$from} 00:00:00","{$to} 23:59:59"]);
        }

        if ($r->filled('contract_from') || $r->filled('contract_to')) {
            $from = $r->input('contract_from', '1900-01-01');
            $to   = $r->input('contract_to',   now()->toDateString());
            $query->whereHas('contracts', fn(Builder $q) => $q->whereBetween('data_contrato', [$from, $to]));
        }

        self::applyMassFilter($query, $r, 'cpf',    ['cpf']);
        self::applyMassFilter($query, $r, 'names',  ['nome']);
        self::applyMassFilter($query, $r, 'phones', ['fone1','fone2','fone3','fone4']);

        $vendors = $r->filled('vendors') ? (is_array($r->vendors) ? $r->vendors : explode(',', (string)$r->vendors)) : [];
        if ($vendors) {
            $clean = array_map(fn($n) => Vendor::clean($n), $vendors);
            $query->whereHas('contracts.vendor', fn(Builder $q) => $q->whereIn('name_clean', $clean));
        }

        $birth = $r->filled('birth_month') ? (is_array($r->birth_month) ? $r->birth_month : explode(',', (string)$r->birth_month)) : [];
        if ($birth) {
            $months = array_values(array_filter(array_map(fn($m) => ($m = (int)$m) >= 1 && $m <= 12 ? $m : null, $birth)));
            if ($months) $query->whereIn(DB::raw('MONTH(leads.data_nascimento)'), $months);
        }

        // ======== FGTS OFF ========
        $fgtsStatus        = self::normalizeFgtsStatus($r->input('fgts_status', null));
        $hasFgtsDateFilter = $r->filled('fgts_consulta_from') || $r->filled('fgts_consulta_to');

        if ($fgtsStatus === 'autorizado') {
            $query->whereIn('leads.cpf', function ($sq) {
                $sq->select('cpf')->from('fgts_off_snapshots')->where('authorized', 1);
            });
        } elseif ($fgtsStatus === 'nao_autorizado') {
            $query->whereIn('leads.cpf', function ($sq) {
                $sq->select('cpf')->from('fgts_off_snapshots')->where('authorized', 0);
            });
        } elseif ($fgtsStatus === 'nao_consultado') {
            $query->whereNotIn('leads.cpf', function ($sq) {
                $sq->select('cpf')->from('fgts_off_snapshots');
            });
        }

        if ($hasFgtsDateFilter) {
            $from = $r->input('fgts_consulta_from', '1900-01-01');
            $to   = $r->input('fgts_consulta_to',   now()->toDateString());
            $query->whereIn('leads.cpf', function ($sq) use ($from, $to) {
                $sq->select('cpf')
                   ->from('fgts_off_snapshots')
                   ->whereBetween('updated_at', ["{$from} 00:00:00","{$to} 23:59:59"]);
            });
        }

        return $query->latest('updated_at');
    }

    private static function applyMassFilter(Builder $q, Request $r, string $key, array $columns): void
    {
        if (!$r->filled($key)) return;

        $input = $r->input($key);
        $raw = is_array($input)
            ? $input
            : preg_split('/[\s,;]+/', (string) $input);

        $raw = array_values(array_filter($raw, fn($v) => $v !== '' && $v !== null));
        if (empty($raw)) return;

        if ($key === 'names') {
            $values = array_values(array_unique($raw));
            $q->where(function ($sub) use ($columns, $values) {
                foreach ($columns as $col) {
                    foreach ($values as $v) $sub->orWhere($col, 'like', "%{$v}%");
                }
            });
            return;
        }

        if ($key === 'cpf') {
            $normalized = [];
            foreach ($raw as $v) {
                $n = \App\Support\Cpf::normalize((string)$v);
                if ($n !== null) $normalized[] = $n;
            }
            $values = array_values(array_unique($normalized));
        } elseif ($key === 'phones') {
            $normPhones = [];
            foreach ($raw as $v) {
                $d = preg_replace('/\D+/', '', (string)$v);
                if (strlen($d) > 11 && substr($d, 0, 2) === '55') $d = substr($d, 2);
                if (strlen($d) === 10 || strlen($d) === 11) $normPhones[] = $d;
            }
            $values = array_values(array_unique($normPhones));
        } else {
            $values = array_values(array_unique($raw));
        }

        if (empty($values)) return;

        $chunkSize = 1000;
        $chunks = array_chunk($values, $chunkSize);

        $q->where(function ($sub) use ($columns, $chunks) {
            foreach ($columns as $col) {
                foreach ($chunks as $set) $sub->orWhereIn($col, $set);
            }
        });
    }

    private static function normalizeFgtsStatus($v): ?string
    {
        if ($v === null || $v === '') return null;
        if (!is_string($v)) return null;

        $s = trim(mb_strtolower($v));
        $s = str_replace(['ã','â','á','à','ä'], 'a', $s);
        $s = str_replace(['ê','é','è','ë'], 'e', $s);
        $s = str_replace(['î','í','ì','ï'], 'i', $s);
        $s = str_replace(['õ','ô','ó','ò','ö'], 'o', $s);
        $s = str_replace(['û','ú','ù','ü'], 'u', $s);
        $s = preg_replace('/[\s\-]+/u', '_', $s);

        $map = [
            'autorizado'      => 'autorizado',
            'nao_autorizado'  => 'nao_autorizado',
            'nao_consultado'  => 'nao_consultado',
        ];

        return $map[$s] ?? null;
    }
}
