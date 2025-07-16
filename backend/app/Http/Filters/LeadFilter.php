<?php

namespace App\Http\Filters;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Lead;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

class LeadFilter
{
    public static function apply(Request $r): Builder
    {
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
            if ($r->status === 'elegiveis') {
                $query->where('consulta', 'Saldo FACTA')
                      ->where('libera', '>', 0);
            } else {
                $query->where(function ($q) {
                    $q->where('consulta', '!=', 'Saldo FACTA')
                      ->orWhere('libera', '=', 0);
                });
            }
        }

        // 3) Motivos (consulta)
        if ($r->filled('motivos')) {
            $motivos = explode(',', $r->motivos);
            $query->whereIn('consulta', $motivos);
        }

        // 4) Origem cadastramento
        if ($r->filled('origens')) {
            $origens = explode(',', $r->origens);
            $query->whereHas('importJobs', function (Builder $q) use ($origens) {
                $q->whereIn('import_jobs.origin', $origens);
            });
        }

        // 4b) Origens de higienização
        if ($r->filled('origens_hig')) {
            $origHig = explode(',', $r->origens_hig);
            $query->whereHas('importJobs', function (Builder $q) use ($origHig) {
                $q->where('import_jobs.type', 'higienizacao')
                  ->whereIn('import_jobs.origin', $origHig);
            });
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

        // 7) Filtros massivos: CPF, nomes e telefones
        self::applyMassFilter($query, $r, 'cpf',   ['cpf']);
        self::applyMassFilter($query, $r, 'names', ['nome']);
        self::applyMassFilter($query, $r, 'phones',['fone1','fone2','fone3','fone4']);

        // 8) Filtro por vendors (se existir)
        if ($r->filled('vendors')) {
            $vendors = explode(',', $r->vendors);
            $clean   = array_map(fn($n) => Vendor::clean($n), $vendors);
            $query->whereHas('contracts.vendor', function (Builder $q) use ($clean) {
                $q->whereIn('name_clean', $clean);
            });
        }

        return $query->latest('updated_at');
    }

    private static function applyMassFilter(Builder $q, Request $r, string $key, array $columns): void
    {
        if (! $r->filled($key)) {
            return;
        }

        $values = array_values(array_filter(array_unique(
            preg_split('/[\s,;]+/', $r->input($key))
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
