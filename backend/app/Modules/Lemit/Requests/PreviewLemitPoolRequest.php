<?php

declare(strict_types=1);

namespace App\Modules\Lemit\Requests;

class PreviewLemitPoolRequest extends BaseLemitPoolRequest
{
    public function rules(): array
    {
        return $this->baseRules();
    }
}
