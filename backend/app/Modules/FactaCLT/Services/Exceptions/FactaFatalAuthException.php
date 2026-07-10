<?php

namespace App\Modules\FactaCLT\Services\Exceptions;

use RuntimeException;
use Throwable;

class FactaFatalAuthException extends RuntimeException
{
    /** @var array<int,string> */
    private array $pendingCpfs;
    private string $abortCsvMessage;

    /**
     * @param array<int,string> $pendingCpfs
     */
    public function __construct(
        string $message,
        array $pendingCpfs = [],
        ?Throwable $previous = null,
        ?string $abortCsvMessage = null
    )
    {
        parent::__construct($message, 0, $previous);

        $normalized = [];
        foreach ($pendingCpfs as $cpf) {
            $digits = preg_replace('/\D+/', '', (string) $cpf);
            if (is_string($digits) && strlen($digits) === 11) {
                $normalized[] = $digits;
            }
        }

        $this->pendingCpfs = array_values(array_unique($normalized));
        $msg = trim((string) ($abortCsvMessage ?? ''));
        $this->abortCsvMessage = $msg !== ''
            ? $msg
            : 'Processamento abortado: Usuário ou senha inválida.';
    }

    /**
     * @return array<int,string>
     */
    public function pendingCpfs(): array
    {
        return $this->pendingCpfs;
    }

    public function abortCsvMessage(): string
    {
        return $this->abortCsvMessage;
    }
}
