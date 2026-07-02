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
            'mode' => ['nullable', Rule::in(['base', 'fgts', 'clt', 'mercantil', 'uy3', '360'])],

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
            'with_phones' => ['nullable', 'boolean'],
            'without_phones' => ['nullable', 'boolean'],
            'birth_month' => ['nullable'],
            'selected_banks' => ['nullable'],
            'bank_combination_mode' => ['nullable', 'in:any,all'],

            // FGTS OFF
            'fgts_status' => ['nullable', 'in:autorizado,nao_autorizado,nao_consultado'],
            'fgts_consulta_from' => ['nullable', 'date_format:Y-m-d'],
            'fgts_consulta_to' => ['nullable', 'date_format:Y-m-d'],

            // ===== CLT: filtros =====
            'clt_situacao' => ['nullable', 'in:elegivel,nao_elegivel,nao_encontrado,aprovado,nao_aprovado'],
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
            'clt_numero_parcelas_min' => ['nullable', 'integer', 'min:0'],
            'clt_numero_parcelas_max' => ['nullable', 'integer', 'min:0'],
            'clt_prestacao_min' => ['nullable'],
            'clt_prestacao_max' => ['nullable'],

            // Histórico crédito
            'clt_ativos_min' => ['nullable', 'integer', 'min:0'],
            'clt_ativos_max' => ['nullable', 'integer', 'min:0'],
            'clt_tem_ativos' => ['nullable', 'in:sim,nao'],
            'clt_tem_legados' => ['nullable', 'in:sim,nao'],

            // ===== MERCANTIL: filtros =====
            'mercantil_situacao' => ['nullable', 'in:consultado,sem_consulta,aprovado,nao_aprovado'],
            'mercantil_status' => ['nullable'],
            'mercantil_consulta_from' => ['nullable', 'date_format:Y-m-d'],
            'mercantil_consulta_to' => ['nullable', 'date_format:Y-m-d'],
            'mercantil_import_from' => ['nullable', 'date_format:Y-m-d'],
            'mercantil_import_to' => ['nullable', 'date_format:Y-m-d'],
            'mercantil_valor_parcela_min' => ['nullable'],
            'mercantil_valor_parcela_max' => ['nullable'],
            'mercantil_numero_parcelas_min' => ['nullable', 'integer', 'min:0'],
            'mercantil_numero_parcelas_max' => ['nullable', 'integer', 'min:0'],
            'mercantil_parcela_min' => ['nullable'],
            'mercantil_parcela_max' => ['nullable'],
            'mercantil_qtd_parcelas_min' => ['nullable', 'integer', 'min:0'],
            'mercantil_qtd_parcelas_max' => ['nullable', 'integer', 'min:0'],
            'mercantil_origens' => ['nullable'],

            // ===== UY3: filtros =====
            'uy3_situacao' => ['nullable', 'in:aprovado,nao_aprovado'],
            'uy3_consulta_from' => ['nullable', 'date_format:Y-m-d'],
            'uy3_consulta_to' => ['nullable', 'date_format:Y-m-d'],
            'uy3_meses_admissao_min' => ['nullable', 'integer', 'min:0'],
            'uy3_meses_admissao_max' => ['nullable', 'integer', 'min:0'],
            'uy3_margem_min' => ['nullable'],
            'uy3_margem_max' => ['nullable'],
            'uy3_valor_liberado_min' => ['nullable'],
            'uy3_valor_liberado_max' => ['nullable'],
            'uy3_numero_parcelas_min' => ['nullable', 'integer', 'min:0'],
            'uy3_numero_parcelas_max' => ['nullable', 'integer', 'min:0'],
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
            'selected_banks',
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
