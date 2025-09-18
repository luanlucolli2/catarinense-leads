<?php

namespace App\Http\Filters;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Models\Lead;
use App\Models\Vendor;

class LeadFilter
{
    /**
     * @param Request     $r
     * @param array|null  $columnsForExport  Quando informado, ativamos seleção mínima p/ export
     */
    public static function apply(Request $r, ?array $columnsForExport = null): Builder
    {
        $liberaExpr = "CAST(REPLACE(REGEXP_REPLACE(COALESCE(leads.libera, ''), '[^0-9.,-]', ''), ',', '.') AS DECIMAL(20,2))";
        $consultaSaldoFacta = "TRIM(leads.consulta) = 'Saldo FACTA'";
        $isElegivel = "CASE WHEN ($consultaSaldoFacta AND $liberaExpr > 0) THEN 1 ELSE 0 END";

        $exportMode = is_array($columnsForExport);

        if ($exportMode) {
            // base mínima
            $select = ['leads.id'];

            // colunas exportáveis diretamente da tabela leads
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
                if (in_array($col, $allowedLeadCols, true)) {
                    $select[] = "leads.$col";
                }
                if ($col === 'id' && !in_array('leads.id', $select, true)) {
                    $select[] = 'leads.id';
                }
            }

            $query = Lead::query()->select($select);

            // primeira origem (só se solicitada)
            if (in_array('primeira_origem', $columnsForExport, true)) {
                $query->addSelect([
                    'primeira_origem' => function ($q) {
                        $q->select('origin')
                            ->from('import_jobs')
                            ->join('lead_imports', 'import_jobs.id', '=', 'lead_imports.import_job_id')
                            ->whereColumn('lead_imports.lead_id', 'leads.id')
                            ->orderBy('lead_imports.created_at')
                            ->limit(1);
                    },
                ]);
            }

            // status flag SQL (só se solicitada a coluna 'status')
            if (in_array('status', $columnsForExport, true)) {
                // garante que consulta/libera estão disponíveis (p/ fallback no map)
                if (!in_array('leads.consulta', $select, true))
                    $query->addSelect('leads.consulta');
                if (!in_array('leads.libera', $select, true))
                    $query->addSelect('leads.libera');

                $query->addSelect(DB::raw("$isElegivel AS status_flag"));
            }

            // contagem de contratos (só se solicitada)
            if (in_array('contracts_count', $columnsForExport, true)) {
                $query->withCount('contracts');
            }
        } else {
            // comportamento do dashboard
            $query = Lead::query()
                ->select('leads.*')
                ->withCount('contracts')
                ->addSelect([
                    'primeira_origem' => function ($q) {
                        $q->select('origin')
                            ->from('import_jobs')
                            ->join('lead_imports', 'import_jobs.id', '=', 'lead_imports.import_job_id')
                            ->whereColumn('lead_imports.lead_id', 'leads.id')
                            ->orderBy('lead_imports.created_at')
                            ->limit(1);
                    },
                ]);
        }

        // ---------- Filtros ----------
        if ($r->filled('search')) {
            $term = '%' . $r->input('search') . '%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('nome', 'like', $term)
                    ->orWhere('cpf', 'like', $term)
                    ->orWhere('fone1', 'like', $term)
                    ->orWhere('fone2', 'like', $term)
                    ->orWhere('fone3', 'like', $term)
                    ->orWhere('fone4', 'like', $term);
            });
        }

        if ($r->filled('status') && $r->status !== 'todos') {
            if ($r->status === 'elegiveis') {
                $query->whereRaw("$isElegivel = 1");
            } else {
                $query->whereRaw("$isElegivel = 0");
            }
        }

        if ($r->filled('motivos')) {
            $motivos = explode(',', (string) $r->motivos);
            $query->whereIn('consulta', $motivos);
        }

        if ($r->filled('origens')) {
            $origens = explode(',', (string) $r->origens);
            $query->whereHas('importJobs', function (Builder $q) use ($origens) {
                $q->whereIn('import_jobs.origin', $origens);
            });
        }

        if ($r->filled('origens_hig')) {
            $origHig = explode(',', (string) $r->origens_hig);
            $query->whereHas('importJobs', function (Builder $q) use ($origHig) {
                $q->where('import_jobs.type', 'higienizacao')
                    ->whereIn('import_jobs.origin', $origHig);
            });
        }

        if ($r->filled('date_from') || $r->filled('date_to')) {
            $from = $r->input('date_from', '1900-01-01');
            $to = $r->input('date_to', now()->toDateString());
            $query->whereBetween('data_atualizacao', ["{$from} 00:00:00", "{$to} 23:59:59"]);
        }

        if ($r->filled('contract_from') || $r->filled('contract_to')) {
            $from = $r->input('contract_from', '1900-01-01');
            $to = $r->input('contract_to', now()->toDateString());
            $query->whereHas('contracts', function (Builder $q) use ($from, $to) {
                $q->whereBetween('data_contrato', [$from, $to]);
            });
        }

        self::applyMassFilter($query, $r, 'cpf', ['cpf']);
        self::applyMassFilter($query, $r, 'names', ['nome']);
        self::applyMassFilter($query, $r, 'phones', ['fone1', 'fone2', 'fone3', 'fone4']);

        if ($r->filled('vendors')) {
            $vendors = explode(',', (string) $r->vendors);
            $clean = array_map(fn($n) => Vendor::clean($n), $vendors);
            $query->whereHas('contracts.vendor', function (Builder $q) use ($clean) {
                $q->whereIn('name_clean', $clean);
            });
        }

        // 🎂 Mês de aniversário
        if ($r->filled('birth_month')) {
            $months = array_values(array_filter(array_map(function ($m) {
                $m = (int) trim((string) $m);
                return ($m >= 1 && $m <= 12) ? $m : null;
            }, explode(',', (string) $r->input('birth_month')))));

            if (!empty($months)) {
                $query->whereIn(DB::raw('MONTH(leads.data_nascimento)'), $months);
            }
        }

        return $query->latest('updated_at');
    }

    private static function applyMassFilter(Builder $q, Request $r, string $key, array $columns): void
    {
        if (!$r->filled($key)) {
            return;
        }

        $values = array_values(array_filter(array_unique(
            preg_split('/[\s,;]+/', (string) $r->input($key))
        )));

        if (empty($values)) {
            return;
        }

        $q->where(function ($sub) use ($columns, $values, $key) {
            foreach ($columns as $col) {
                if ($key === 'names') {
                    foreach ($values as $v) {
                        $sub->orWhere($col, 'like', "%{$v}%");
                    }
                } else {
                    $sub->orWhereIn($col, $values);
                }
            }
        });
    }
}
