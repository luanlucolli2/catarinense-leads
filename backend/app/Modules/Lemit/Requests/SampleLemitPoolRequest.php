<?php

declare(strict_types=1);

namespace App\Modules\Lemit\Requests;

class SampleLemitPoolRequest extends BaseLemitPoolRequest
{
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'quantity' => ['required', 'integer', 'min:1', 'max:' . $this->maxQuantity()],
        ]);
    }

    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }

    private function maxQuantity(): int
    {
        return max(1, (int) config('lemit.sample.max_quantity', 5000));
    }
}
