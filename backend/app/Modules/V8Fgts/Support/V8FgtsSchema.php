<?php

namespace App\Modules\V8Fgts\Support;

final class V8FgtsSchema
{
    public const COLS = [
        'cpf',
        'status',
        'mensagem',
        'provider',
        'balance_id',
        'balance_amount',
        'periods_summary',
        'simulation_fee_label',
        'simulation_fee_id',
        'simulation_id',
        'available_balance',
        'emission_amount',
        'total_balance',
        'total_installments',
        'tax',
        'cet',
        'annual_cet',
        'iof',
        'tc',
        'finished_at',
        'balance_start_response_body',
    ];

    public const TITLES = [
        'CPF',
        'Status',
        'Mensagem',
        'Provider',
        'Balance ID',
        'Saldo Consultado',
        'Periodos',
        'Tabela',
        'Tabela ID',
        'Simulacao ID',
        'Valor Liquido',
        'Valor Emissao',
        'Valor Total',
        'Total Parcelas',
        'Taxa',
        'CET',
        'CET Anual',
        'IOF',
        'TC',
        'Finalizado em',
        'Contexto Erro API',
    ];

    public static function headerCsvLine(string $sep = ';'): string
    {
        return implode($sep, self::TITLES);
    }
}
