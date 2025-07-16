<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Se já está protegido por Sanctum/Gate, basta retornar true
        return true;
    }

    public function rules(): array
    {
        return [
            'columns'       => ['required', 'array', 'min:1'],
            'columns.*'     => ['in:cpf,nome,fone1,fone2,fone3,fone4,classe_fone1,classe_fone2,classe_fone3,classe_fone4,status,consulta,saldo,libera,primeira_origem,data_atualizacao'],
            'search'        => ['nullable', 'string'],
            'status'        => ['nullable', 'in:todos,elegiveis,nao-elegiveis'],
            'motivos'       => ['nullable', 'string'],    // string CSV
            'origens'       => ['nullable', 'string'],
            'origens_hig'   => ['nullable', 'string'],
            'date_from'     => ['nullable', 'date_format:Y-m-d'],
            'date_to'       => ['nullable', 'date_format:Y-m-d'],
            'contract_from' => ['nullable', 'date_format:Y-m-d'],
            'contract_to'   => ['nullable', 'date_format:Y-m-d'],
            'cpf'           => ['nullable', 'string'],    // string CSV ou multi-line
            'names'         => ['nullable', 'string'],
            'phones'        => ['nullable', 'string'],
            'vendors'       => ['nullable', 'string'],
        ];
    }

    /** Preparar filtros em CSV para arrays */
    protected function prepareForValidation(): void
    {
        foreach (['motivos','origens','origens_hig','cpf','names','phones','vendors'] as $key) {
            if ($this->filled($key) && is_string($this->$key)) {
                // normaliza string CSV ou newline separado
                $this->merge([
                    $key => preg_replace('/[\r\n]+/', ',', $this->input($key)),
                ]);
            }
        }
    }
}
