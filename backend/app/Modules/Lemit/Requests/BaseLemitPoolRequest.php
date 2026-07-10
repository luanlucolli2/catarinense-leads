<?php

declare(strict_types=1);

namespace App\Modules\Lemit\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class BaseLemitPoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return [
            'selected_banks' => ['nullable', 'array'],
            'selected_banks.*' => ['required', 'string', 'distinct', Rule::in(['facta', 'mercantil', 'uy3'])],
            'bank_combination_mode' => ['required', 'string', Rule::in(['all', 'any'])],
            'with_phones' => ['nullable', 'boolean'],
            'without_phones' => ['nullable', 'boolean'],

            'facta' => ['nullable', 'array'],
            'facta.facta_situacao' => ['nullable', 'string', Rule::in(['aprovado', 'nao_aprovado'])],
            'facta.facta_consulta_from' => ['nullable', 'date_format:Y-m-d'],
            'facta.facta_consulta_to' => ['nullable', 'date_format:Y-m-d'],
            'facta.facta_meses_admissao_min' => ['nullable', 'integer', 'min:0'],
            'facta.facta_meses_admissao_max' => ['nullable', 'integer', 'min:0'],
            'facta.facta_margem_min' => ['nullable', 'numeric', 'min:0'],
            'facta.facta_margem_max' => ['nullable', 'numeric', 'min:0'],
            'facta.facta_numero_parcelas_min' => ['nullable', 'integer', 'min:0'],
            'facta.facta_numero_parcelas_max' => ['nullable', 'integer', 'min:0'],

            'mercantil' => ['nullable', 'array'],
            'mercantil.mercantil_situacao' => ['nullable', 'string', Rule::in(['aprovado', 'nao_aprovado'])],
            'mercantil.mercantil_consulta_from' => ['nullable', 'date_format:Y-m-d'],
            'mercantil.mercantil_consulta_to' => ['nullable', 'date_format:Y-m-d'],
            'mercantil.mercantil_valor_parcela_min' => ['nullable', 'numeric', 'min:0'],
            'mercantil.mercantil_valor_parcela_max' => ['nullable', 'numeric', 'min:0'],
            'mercantil.mercantil_numero_parcelas_min' => ['nullable', 'integer', 'min:0'],
            'mercantil.mercantil_numero_parcelas_max' => ['nullable', 'integer', 'min:0'],

            'uy3' => ['nullable', 'array'],
            'uy3.uy3_situacao' => ['nullable', 'string', Rule::in(['aprovado', 'nao_aprovado'])],
            'uy3.uy3_consulta_from' => ['nullable', 'date_format:Y-m-d'],
            'uy3.uy3_consulta_to' => ['nullable', 'date_format:Y-m-d'],
            'uy3.uy3_meses_admissao_min' => ['nullable', 'integer', 'min:0'],
            'uy3.uy3_meses_admissao_max' => ['nullable', 'integer', 'min:0'],
            'uy3.uy3_margem_min' => ['nullable', 'numeric', 'min:0'],
            'uy3.uy3_margem_max' => ['nullable', 'numeric', 'min:0'],
            'uy3.uy3_valor_liberado_min' => ['nullable', 'numeric', 'min:0'],
            'uy3.uy3_valor_liberado_max' => ['nullable', 'numeric', 'min:0'],
            'uy3.uy3_numero_parcelas_min' => ['nullable', 'integer', 'min:0'],
            'uy3.uy3_numero_parcelas_max' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();
        $payload['bank_combination_mode'] = strtolower(trim((string) ($payload['bank_combination_mode'] ?? 'all')));

        $selectedBanks = Arr::wrap($payload['selected_banks'] ?? []);
        $payload['selected_banks'] = array_values(array_filter(array_map(
            fn(mixed $value): string => strtolower(trim((string) $value)),
            $selectedBanks
        )));

        foreach ([
            'facta.facta_margem_min',
            'facta.facta_margem_max',
            'mercantil.mercantil_valor_parcela_min',
            'mercantil.mercantil_valor_parcela_max',
            'uy3.uy3_margem_min',
            'uy3.uy3_margem_max',
            'uy3.uy3_valor_liberado_min',
            'uy3.uy3_valor_liberado_max',
        ] as $path) {
            $value = data_get($payload, $path);
            if (is_string($value)) {
                data_set($payload, $path, str_replace(',', '.', trim($value)));
            }
        }

        foreach ([
            'facta.facta_situacao',
            'mercantil.mercantil_situacao',
            'uy3.uy3_situacao',
        ] as $path) {
            $value = data_get($payload, $path);
            if (is_string($value)) {
                data_set($payload, $path, strtolower(trim($value)));
            }
        }

        $this->replace($payload);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->boolean('with_phones') && $this->boolean('without_phones')) {
                $validator->errors()->add('with_phones', 'Selecione apenas um status de telefone por vez.');
            }

            foreach ($this->normalizedSelectedBanks() as $bank) {
                if (! $this->bankHasOwnFilter($bank)) {
                    $validator->errors()->add(
                        $bank,
                        sprintf('Preencha ao menos um filtro no bloco %s.', $this->bankLabel($bank))
                    );
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'selected_banks' => $this->normalizedSelectedBanks(),
            'bank_combination_mode' => (string) ($validated['bank_combination_mode'] ?? 'all'),
            'with_phones' => $this->boolean('with_phones'),
            'without_phones' => $this->boolean('without_phones'),
            'facta' => (array) ($validated['facta'] ?? []),
            'mercantil' => (array) ($validated['mercantil'] ?? []),
            'uy3' => (array) ($validated['uy3'] ?? []),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function normalizedSelectedBanks(): array
    {
        $selected = Arr::wrap($this->input('selected_banks', []));

        return array_values(array_unique(array_filter(array_map(
            fn(mixed $value): string => strtolower(trim((string) $value)),
            $selected
        ))));
    }

    protected function bankHasOwnFilter(string $bank): bool
    {
        return match ($bank) {
            'facta' => $this->hasAnyFilledField('facta', [
                'facta_situacao',
                'facta_consulta_from',
                'facta_consulta_to',
                'facta_meses_admissao_min',
                'facta_meses_admissao_max',
                'facta_margem_min',
                'facta_margem_max',
                'facta_numero_parcelas_min',
                'facta_numero_parcelas_max',
            ]),
            'mercantil' => $this->hasAnyFilledField('mercantil', [
                'mercantil_situacao',
                'mercantil_consulta_from',
                'mercantil_consulta_to',
                'mercantil_valor_parcela_min',
                'mercantil_valor_parcela_max',
                'mercantil_numero_parcelas_min',
                'mercantil_numero_parcelas_max',
            ]),
            'uy3' => $this->hasAnyFilledField('uy3', [
                'uy3_situacao',
                'uy3_consulta_from',
                'uy3_consulta_to',
                'uy3_meses_admissao_min',
                'uy3_meses_admissao_max',
                'uy3_margem_min',
                'uy3_margem_max',
                'uy3_valor_liberado_min',
                'uy3_valor_liberado_max',
                'uy3_numero_parcelas_min',
                'uy3_numero_parcelas_max',
            ]),
            default => false,
        };
    }

    /**
     * @param array<int, string> $fields
     */
    protected function hasAnyFilledField(string $root, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->filled($root . '.' . $field)) {
                return true;
            }
        }

        return false;
    }

    protected function bankLabel(string $bank): string
    {
        return match ($bank) {
            'facta' => 'Facta CLT',
            'mercantil' => 'CLT Mercantil',
            'uy3' => 'CLT UY3',
            default => strtoupper($bank),
        };
    }
}
