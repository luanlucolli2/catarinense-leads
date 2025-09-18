<?php

namespace App\Http\Filters;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use App\Models\Lead;
use App\Models\Vendor;

class LeadFilter
{
    public static function apply(Request $r): Builder
    {
        // Extrai número de 'libera' (TEXT) removendo símbolos, trocando vírgula por ponto e CAST para DECIMAL.
        $liberaExpr = "CAST(REPLACE(REGEXP_REPLACE(COALESCE(leads.libera, ''), '[^0-9.,-]', ''), ',', '.') AS DECIMAL(20,2))";

        $consultaSaldoFacta = "TRIM(leads.consulta) = 'Saldo FACTA'";
        $isElegivel = "CASE WHEN ($consultaSaldoFacta AND $liberaExpr > 0) THEN 1 ELSE 0 END";

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

        // 1) Pesquisa livre
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

        // 2) Status (elegíveis / não-elegíveis)
        if ($r->filled('status') && $r->status !== 'todos') {
            $query->whereRaw($r->status === 'elegiveis' ? "$isElegivel = 1" : "$isElegivel = 0");
        }

        // 3) Motivos (consulta)
        if ($r->filled('motivos')) {
            $motivos = self::toArray($r->input('motivos'));
            if ($motivos) {
                $query->whereIn('consulta', $motivos);
            }
        }

        // 4) Origem cadastramento
        if ($r->filled('origens')) {
            $origens = self::toArray($r->input('origens'));
            if ($origens) {
                $query->whereHas('importJobs', function (Builder $q) use ($origens) {
                    $q->whereIn('import_jobs.origin', $origens);
                });
            }
        }

        // 4b) Origens de higienização
        if ($r->filled('origens_hig')) {
            $origHig = self::toArray($r->input('origens_hig'));
            if ($origHig) {
                $query->whereHas('importJobs', function (Builder $q) use ($origHig) {
                    $q->where('import_jobs.type', 'higienizacao')
                        ->whereIn('import_jobs.origin', $origHig);
                });
            }
        }

        // 5) Data de atualização FGTS
        if ($r->filled('date_from') || $r->filled('date_to')) {
            $from = $r->input('date_from', '1900-01-01');
            $to   = $r->input('date_to', now()->toDateString());
            $query->whereBetween('data_atualizacao', ["{$from} 00:00:00", "{$to} 23:59:59"]);
        }

        // 6) Período de contratos
        if ($r->filled('contract_from') || $r->filled('contract_to')) {
            $from = $r->input('contract_from', '1900-01-01');
            $to   = $r->input('contract_to', now()->toDateString());
            $query->whereHas('contracts', function (Builder $q) use ($from, $to) {
                $q->whereBetween('data_contrato', [$from, $to]);
            });
        }

        // 7) Filtros massivos: CPF, nomes e telefones (agora aceitam array OU string)
        self::applyMassFilter($query, $r, 'cpf',   ['cpf']);
        self::applyMassFilter($query, $r, 'names', ['nome']);
        self::applyMassFilter($query, $r, 'phones',['fone1','fone2','fone3','fone4']);

        // 8) Filtro por vendors
        if ($r->filled('vendors')) {
            $vendors = self::toArray($r->input('vendors'));
            if ($vendors) {
                $clean = array_map(fn($n) => Vendor::clean($n), $vendors);
                $query->whereHas('contracts.vendor', function (Builder $q) use ($clean) {
                    $q->whereIn('name_clean', $clean);
                });
            }
        }

        // 9) 🎂 Mês de aniversário (aceita string "3,9,12" OU array [3,9,12])
        if ($r->filled('birth_month')) {
            $monthsRaw = $r->input('birth_month');
            $months = is_array($monthsRaw) ? $monthsRaw : explode(',', (string)$monthsRaw);
            $months = array_values(array_filter(array_map(function ($m) {
                $m = (int) trim((string)$m);
                return ($m >= 1 && $m <= 12) ? $m : null;
            }, $months)));

            if ($months) {
                $query->whereIn(DB::raw('MONTH(leads.data_nascimento)'), $months);
            }
        }

        return $query->latest('updated_at');
    }

    /** Converte string CSV OU array (com quebras, vírgulas, ; ) em array limpo */
    private static function toArray($value): array
    {
        if (is_array($value)) {
            $arr = $value;
        } else {
            $str = (string) $value;
            $arr = preg_split('/[\s,;]+/', $str);
        }

        return array_values(array_filter(array_map(fn($v) => trim((string)$v), $arr), fn($v) => $v !== ''));
    }

    private static function applyMassFilter(Builder $q, Request $r, string $key, array $columns): void
    {
        if (!$r->filled($key)) {
            return;
        }

        $raw = $r->input($key);

        // aceita array OU string
        $values = is_array($raw)
            ? $raw
            : preg_split('/[\s,;]+/', (string)$raw);

        $values = array_values(array_filter(array_unique(array_map(
            fn($v) => trim((string)$v),
            $values
        ))));

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
