<?php

namespace App\Modules\Leads\Imports\Exceptions;

use RuntimeException;

class ImportHeaderException extends RuntimeException
{
    /** @param array<int, string> $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Cabeçalhos ausentes: ' . implode(', ', $missing));
    }
}
