<?php

namespace App\Modules\Leads\Requests;

use App\Modules\Leads\Support\LeadExportColumns;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['nullable', Rule::in(['fgts', 'clt', 'mercantil'])],

            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['required', 'string', 'distinct', Rule::in(LeadExportColumns::allowed())],

            // filtros gerais
            'search' => ['nullable', 'string'],
            'motivos' => ['nullable'],
            'origens' => ['nullable'],
            'origens_hig' => ['nullable'],
            'vendors' => ['nullable'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'contract_from' => ['nullable', 'date_format:Y-m-d'],
            'contract_to' => ['nullable', 'date_format:Y-m-d'],
            'cpf' => ['nullable'],
            'names' => ['nullable'],
            'phones' => ['nullable'],
            'birth_month' => ['nullable'],

            // FGTS OFF
            'fgts_status' => ['nullable', 'in:autorizado,nao_autorizado,nao_consultado'],
            'fgts_consulta_from' => ['nullable', 'date_format:Y-m-d'],
            'fgts_consulta_to' => ['nullable', 'date_format:Y-m-d'],

            // ===== CLT: filtros =====
            'clt_situacao' => ['nullable', 'in:elegivel,nao_elegivel,nao_encontrado'],
            'clt_elegivel' => ['nullable', 'in:sim,nao'],
            'clt_not_found' => ['nullable', 'in:sim,nao'],

            'clt_consultado' => ['nullable', 'in:sim,nao'],
            'clt_consulta_from' => ['nullable', 'date_format:Y-m-d'],
            'clt_consulta_to' => ['nullable', 'date_format:Y-m-d'],

            // Vínculo
            'clt_admissao_from' => ['nullable', 'date_format:Y-m-d'],
            'clt_admissao_to' => ['nullable', 'date_format:Y-m-d'],
            'clt_meses_min' => ['nullable', 'integer', 'min:0'],
            'clt_meses_max' => ['nullable', 'integer', 'min:0'],
            'clt_inicio_empregador_from' => ['nullable', 'date_format:Y-m-d'],
            'clt_inicio_empregador_to' => ['nullable', 'date_format:Y-m-d'],
            'clt_categoria_codigos' => ['nullable'],

            // Perfil
            'clt_idade_min' => ['nullable', 'integer', 'min:0'],
            'clt_idade_max' => ['nullable', 'integer', 'min:0'],
            'clt_sexo' => ['nullable'],

            // Renda e margem
            'clt_renda_min' => ['nullable'],
            'clt_renda_max' => ['nullable'],
            'clt_base_min' => ['nullable'],
            'clt_base_max' => ['nullable'],
            'clt_margem_min' => ['nullable'],
            'clt_margem_max' => ['nullable'],
            'clt_prestacao_min' => ['nullable'],
            'clt_prestacao_max' => ['nullable'],

            // Histórico crédito
            'clt_ativos_min' => ['nullable', 'integer', 'min:0'],
            'clt_ativos_max' => ['nullable', 'integer', 'min:0'],
            'clt_tem_ativos' => ['nullable', 'in:sim,nao'],
            'clt_tem_legados' => ['nullable', 'in:sim,nao'],

            // ===== MERCANTIL: filtros =====
            'mercantil_situacao' => ['nullable', 'in:consultado,sem_consulta'],
            'mercantil_status' => ['nullable'],
            'mercantil_consulta_from' => ['nullable', 'date_format:Y-m-d'],
            'mercantil_consulta_to' => ['nullable', 'date_format:Y-m-d'],
            'mercantil_import_from' => ['nullable', 'date_format:Y-m-d'],
            'mercantil_import_to' => ['nullable', 'date_format:Y-m-d'],
            'mercantil_parcela_min' => ['nullable'],
            'mercantil_parcela_max' => ['nullable'],
            'mercantil_qtd_parcelas_min' => ['nullable', 'integer', 'min:0'],
            'mercantil_qtd_parcelas_max' => ['nullable', 'integer', 'min:0'],
            'mercantil_origens' => ['nullable'],
        ];
    }

    /** Arrays -> CSV; quebras de linha -> vírgulas */
    protected function prepareForValidation(): void
    {
        foreach ([
            'motivos',
            'origens',
            'origens_hig',
            'vendors',
            'cpf',
            'names',
            'phones',
            'birth_month',
            'clt_categoria_codigos',
            'clt_sexo',
            'mercantil_status',
            'mercantil_origens',
        ] as $key) {
            if ($this->filled($key)) {
                $val = $this->input($key);
                if (is_array($val))
                    $val = implode(',', $val);
                $val = preg_replace('/[\r\n]+/', ',', (string) $val);
                $this->merge([$key => $val]);
            }
        }
    }
}
