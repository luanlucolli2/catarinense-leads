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
        // 🔧 garantir que não haverá timeout/memória
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
            @ini_set('memory_limit', '-1');
        }

        // ⚙️ ajustes de performance do PhpSpreadsheet/Maatwebsite
        // - chunk maior reduz ida/volta ao DB
        // - sem pré-cálculo de fórmulas (não usamos fórmulas)
        // - caminho de arquivos temporários em storage rápido
        Config::set('excel.exports.chunk_size', 5000);
        Config::set('excel.exports.pre_calculate_formulas', false);
        Config::set('excel.temporary_files.local_path', storage_path('framework/cache/excel-temp'));

        $columns = $request->input('columns', []);

        // ⤵️ usa o LeadFilter em "modo export", que seleciona só o essencial
        $query   = LeadFilter::apply($request, $columns);

        return Excel::download(
            new LeadsExport($query, $columns),
            'leads_export.xlsx'
        );
    }
}
