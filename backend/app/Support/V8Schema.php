<?php

namespace App\Support;

final class V8Schema
{
    public const COLS = [
        'cpf',
        'nome',
        'data_nascimento',
        'consult_id',
        'status',
        'available_margin_value',
        'id_simulation',
        'installment_value',
        'number_of_installments',
        'operation_amount',
        'issue_amount',
        'disbursement_option_iof_amount',
        'iof_amount',
        'monthly_interest_rate',
        'disbursed_issue_amount',
        'disbursement_amount',
        'first_installment_date',
        'is_insured',
        'insurance_amount',
        'mensagem',
    ];

    public const TITLES = [
        'CPF',
        'Nome',
        'Data de Nascimento',
        'Consult ID',
        'Status',
        'Margem Disponível',
        'ID da Simulação',
        'Valor da Parcela',
        'Nº de Parcelas',
        'Valor da Operação',
        'Valor da Emissão',
        'IOF (Opção de Desembolso)',
        'IOF',
        'Taxa de Juros Mensal',
        'Valor Liberado (Issue)',
        'Valor de Desembolso',
        '1ª Parcela',
        'Possui Seguro',
        'Valor do Seguro',
        'Mensagem',
    ];

    public static function headerCsvLine(string $sep = ';'): string
    {
        return implode($sep, self::TITLES);
    }
}
