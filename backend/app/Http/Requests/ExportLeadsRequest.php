<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportLeadsRequest extends FormRequest
{
    /**
     * Colunas permitidas para exportação (mantém backend e frontend em sincronia).
     */
    private const ALLOWED_COLUMNS = [
        'id',
        'cpf',
        'nome',
        'data_nascimento',
        'fone1', 'fone2', 'fone3', 'fone4',
        'classe_fone1', 'classe_fone2', 'classe_fone3', 'classe_fone4',
        'status',
        'consulta',
        'saldo',
        'libera',
        'primeira_origem',
        'data_atualizacao',
        'contracts_count',
        // ➕ novos campos
        'vendedor',
        'data_contrato_recente',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'columns'   => ['required', 'array', 'min:1'],
            'columns.*' => ['required', 'string', 'distinct', Rule::in(self::ALLOWED_COLUMNS)],

            // filtros
            'search'        => ['nullable', 'string'],
            'status'        => ['nullable', 'in:todos,elegiveis,nao-elegiveis'],
            'motivos'       => ['nullable'],
            'origens'       => ['nullable'],
            'origens_hig'   => ['nullable'],
            'vendors'       => ['nullable'],

            'date_from'     => ['nullable', 'date_format:Y-m-d'],
            'date_to'       => ['nullable', 'date_format:Y-m-d'],
            'contract_from' => ['nullable', 'date_format:Y-m-d'],
            'contract_to'   => ['nullable', 'date_format:Y-m-d'],

            'cpf'           => ['nullable'],
            'names'         => ['nullable'],
            'phones'        => ['nullable'],

            // 🎂 meses de aniversário
            'birth_month'   => ['nullable'],
        ];
    }

    /** Arrays -> CSV; quebras de linha -> vírgulas */
    protected function prepareForValidation(): void
    {
        foreach (['motivos','origens','origens_hig','vendors','cpf','names','phones','birth_month'] as $key) {
            if ($this->filled($key)) {
                $val = $this->input($key);

                if (is_array($val)) {
                    $val = implode(',', $val);
                } else {
                    $val = (string) $val;
                }

                $val = preg_replace('/[\r\n]+/', ',', $val);

                $this->merge([$key => $val]);
            }
        }
    }
}
