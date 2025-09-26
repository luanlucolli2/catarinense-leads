<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportLeadsRequest;
use App\Exports\LeadsExport;
use App\Http\Filters\LeadFilter;
use Illuminate\Support\Facades\Config;
use Maatwebsite\Excel\Facades\Excel;

class LeadExportController extends Controller
{
    public function export(ExportLeadsRequest $request)
    {
        // Sem fila neste momento
        if (function_exists('set_time_limit')) { @set_time_limit(0); }
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
            // não usar -1 em 2GB; manter limites e forçar cache em disco
            @ini_set('memory_limit', '512M');
        }

        // Preferir chunks menores para reduzir RAM do writer
        Config::set('excel.exports.chunk_size', 1000);
        Config::set('excel.exports.pre_calculate_formulas', false);

        // Garantir temporários e cache em disco rápido
        Config::set('excel.cache.driver', 'illuminate');
        Config::set('excel.cache.illuminate.store', null); // file
        Config::set('excel.cache.batch.memory_limit', 32768); // 32 MB por batch
        Config::set('excel.temporary_files.local_path', storage_path('framework/cache/excel-temp'));

        $columns = $request->input('columns', []);

        // Usa LeadFilter em modo export (seleção mínima + projeções necessárias)
        $query = LeadFilter::apply($request, $columns);

        return Excel::download(
            new LeadsExport($query, $columns),
            'leads_export.xlsx'
        );
    }
}
