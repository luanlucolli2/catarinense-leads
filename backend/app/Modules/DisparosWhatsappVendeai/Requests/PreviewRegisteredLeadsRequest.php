<?php

declare(strict_types=1);

namespace App\Modules\DisparosWhatsappVendeai\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreviewRegisteredLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'selected_banks' => ['required', 'array', 'min:1'],
            'selected_banks.*' => ['required', 'string', 'distinct', Rule::in(['facta', 'mercantil', 'uy3'])],
            'combination_mode' => ['required', 'string', Rule::in(['all', 'any'])],
            'birth_month' => ['nullable', 'array'],
            'birth_month.*' => ['integer', 'between:1,12', 'distinct'],

            'facta' => ['nullable', 'array'],
            'facta.situacao' => ['nullable', 'string', Rule::in(['aprovado', 'nao_aprovado'])],
            'facta.consulta_from' => ['nullable', 'date_format:Y-m-d'],
            'facta.consulta_to' => ['nullable', 'date_format:Y-m-d'],
            'facta.meses_admissao_min' => ['nullable', 'integer', 'min:0'],
            'facta.meses_admissao_max' => ['nullable', 'integer', 'min:0'],
            'facta.margem_min' => ['nullable', 'numeric', 'min:0'],
            'facta.margem_max' => ['nullable', 'numeric', 'min:0'],
            'facta.parcelas_min' => ['nullable', 'integer', 'min:0'],
            'facta.parcelas_max' => ['nullable', 'integer', 'min:0'],

            'mercantil' => ['nullable', 'array'],
            'mercantil.situacao' => ['nullable', 'string', Rule::in(['aprovado', 'nao_aprovado'])],
            'mercantil.consulta_from' => ['nullable', 'date_format:Y-m-d'],
            'mercantil.consulta_to' => ['nullable', 'date_format:Y-m-d'],
            'mercantil.valor_parcela_min' => ['nullable', 'numeric', 'min:0'],
            'mercantil.valor_parcela_max' => ['nullable', 'numeric', 'min:0'],
            'mercantil.parcelas_min' => ['nullable', 'integer', 'min:0'],
            'mercantil.parcelas_max' => ['nullable', 'integer', 'min:0'],

            'uy3' => ['nullable', 'array'],
            'uy3.situacao' => ['nullable', 'string', Rule::in(['aprovado', 'nao_aprovado'])],
            'uy3.consulta_from' => ['nullable', 'date_format:Y-m-d'],
            'uy3.consulta_to' => ['nullable', 'date_format:Y-m-d'],
            'uy3.meses_admissao_min' => ['nullable', 'integer', 'min:0'],
            'uy3.meses_admissao_max' => ['nullable', 'integer', 'min:0'],
            'uy3.margem_min' => ['nullable', 'numeric', 'min:0'],
            'uy3.margem_max' => ['nullable', 'numeric', 'min:0'],
            'uy3.valor_liberado_min' => ['nullable', 'numeric', 'min:0'],
            'uy3.valor_liberado_max' => ['nullable', 'numeric', 'min:0'],
            'uy3.parcelas_min' => ['nullable', 'integer', 'min:0'],
            'uy3.parcelas_max' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->all();
        $payload['combination_mode'] = strtolower(trim((string) ($payload['combination_mode'] ?? 'any')));
        $payload['selected_banks'] = array_values(array_filter(array_map(
            fn (mixed $value): string => strtolower(trim((string) $value)),
            Arr::wrap($payload['selected_banks'] ?? []),
        )));
        $payload['birth_month'] = array_values(array_unique(array_map(
            fn (mixed $value): int => (int) $value,
            Arr::wrap($payload['birth_month'] ?? []),
        )));

        foreach ([
            'facta.margem_min', 'facta.margem_max',
            'mercantil.valor_parcela_min', 'mercantil.valor_parcela_max',
            'uy3.margem_min', 'uy3.margem_max',
            'uy3.valor_liberado_min', 'uy3.valor_liberado_max',
        ] as $path) {
            $value = data_get($payload, $path);
            if (is_string($value)) {
                data_set($payload, $path, str_replace(',', '.', trim($value)));
            }
        }

        foreach (['facta.situacao', 'mercantil.situacao', 'uy3.situacao'] as $path) {
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
            foreach ($this->selectedBanks() as $bank) {
                if (! $this->bankHasOwnFilter($bank)) {
                    $validator->errors()->add($bank, 'Preencha ao menos um filtro para este banco.');
                }
            }

            foreach (['facta', 'mercantil', 'uy3'] as $bank) {
                $this->validateRange($validator, $bank, 'consulta_from', 'consulta_to', 'A data final deve ser igual ou posterior à data inicial.');
            }

            foreach ([
                'facta' => [['meses_admissao_min', 'meses_admissao_max'], ['margem_min', 'margem_max'], ['parcelas_min', 'parcelas_max']],
                'mercantil' => [['valor_parcela_min', 'valor_parcela_max'], ['parcelas_min', 'parcelas_max']],
                'uy3' => [['meses_admissao_min', 'meses_admissao_max'], ['margem_min', 'margem_max'], ['valor_liberado_min', 'valor_liberado_max'], ['parcelas_min', 'parcelas_max']],
            ] as $bank => $ranges) {
                foreach ($ranges as [$minField, $maxField]) {
                    $this->validateRange($validator, $bank, $minField, $maxField, 'O valor máximo deve ser maior ou igual ao mínimo.');
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
            'selected_banks' => $this->selectedBanks(),
            'combination_mode' => (string) $validated['combination_mode'],
            'birth_month' => array_values(array_map('intval', $validated['birth_month'] ?? [])),
            'facta' => (array) ($validated['facta'] ?? []),
            'mercantil' => (array) ($validated['mercantil'] ?? []),
            'uy3' => (array) ($validated['uy3'] ?? []),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function selectedBanks(): array
    {
        return array_values(array_unique(Arr::wrap($this->input('selected_banks', []))));
    }

    private function bankHasOwnFilter(string $bank): bool
    {
        return match ($bank) {
            'facta' => $this->hasFilled($bank, ['situacao', 'consulta_from', 'consulta_to', 'meses_admissao_min', 'meses_admissao_max', 'margem_min', 'margem_max', 'parcelas_min', 'parcelas_max']),
            'mercantil' => $this->hasFilled($bank, ['situacao', 'consulta_from', 'consulta_to', 'valor_parcela_min', 'valor_parcela_max', 'parcelas_min', 'parcelas_max']),
            'uy3' => $this->hasFilled($bank, ['situacao', 'consulta_from', 'consulta_to', 'meses_admissao_min', 'meses_admissao_max', 'margem_min', 'margem_max', 'valor_liberado_min', 'valor_liberado_max', 'parcelas_min', 'parcelas_max']),
            default => false,
        };
    }

    /** @param array<int, string> $fields */
    private function hasFilled(string $root, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->filled("{$root}.{$field}")) {
                return true;
            }
        }

        return false;
    }

    private function validateRange(Validator $validator, string $root, string $minField, string $maxField, string $message): void
    {
        $min = $this->input("{$root}.{$minField}");
        $max = $this->input("{$root}.{$maxField}");
        if ($min !== null && $min !== '' && $max !== null && $max !== '' && $max < $min) {
            $validator->errors()->add("{$root}.{$maxField}", $message);
        }
    }
}
