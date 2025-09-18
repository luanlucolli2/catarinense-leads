<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportLeadsRequest;
use App\Exports\LeadsExport;
use App\Http\Filters\LeadFilter;
use Maatwebsite\Excel\Facades\Excel;

class LeadExportController extends Controller
{
    public function export(ExportLeadsRequest $request)
    {
        // 🔧 garantir que não haverá timeout nem falta de memória durante export
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
            @ini_set('memory_limit', '-1');
        }

        $columns = $request->input('columns', []);
        $query   = LeadFilter::apply($request);

        return Excel::download(
            new LeadsExport($query, $columns),
            'leads_export.xlsx'
        );
    }
}
