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
        $columns = $request->input('columns');
        $query   = LeadFilter::apply($request);

        // gera e retorna o download em tempo real
        return Excel::download(
            new LeadsExport($query, $columns),
            'leads_export.xlsx'
        );
    }
}
