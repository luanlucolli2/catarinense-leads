<?php

namespace App\Support;

final class FgtsOffSchema
{
    /**
     * Chaves canônicas (ordem das colunas no spool/CSV).
     */
    public const COLS = [
        'cpf',
        'situacao',
        'consultadoEm',
    ];

    /**
     * Títulos normalizados/capitalizados para cabeçalho.
     * Mesma ordem de COLS.
     */
    public const TITLES = [
        'CPF',
        'Situação',
        'Consultado em',
    ];

    /**
     * Linha CSV do cabeçalho já pronta (com separador customizável).
     */
    public static function headerCsvLine(string $sep = ';'): string
    {
        return implode($sep, self::TITLES);
    }
}
