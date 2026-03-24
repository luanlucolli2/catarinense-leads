<?php

declare(strict_types=1);

namespace App\Modules\Uy3\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportUy3PostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(['all', '24h', '7d', '30d', '90d'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['received_at', 'id'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
