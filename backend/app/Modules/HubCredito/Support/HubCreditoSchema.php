<?php

namespace App\Modules\HubCredito\Support;

final class HubCreditoSchema
{
    public const COLS = [
        'cpf',
        'nome',
        'data_nascimento',
        'situacao',
        'pre_simulacao_id',
        'pre_simulacao_status',
        'mensagem',
        'valor_solicitado',
        'parcelas_solicitadas',
        'valor_liberado',
        'valor_desembolso_total',
        'valor_parcela',
        'parcelas_oferta',
        'taxa_juros',
        'valor_seguro',
        'com_seguro',
        'finalizado_em',
    ];

    public const TITLES = [
        'CPF',
        'Nome',
        'Data de Nascimento',
        'Situação',
        'ID Pré-Simulação',
        'Status Pré-Simulação',
        'Mensagem',
        'Valor Solicitado',
        'Parcelas Solicitadas',
        'Valor Liberado',
        'Valor Desembolso Total',
        'Valor Parcela',
        'Parcelas Oferta',
        'Taxa de Juros',
        'Valor Seguro',
        'Com Seguro',
        'Finalizado em',
    ];

    public static function headerCsvLine(string $sep = ';'): string
    {
        return implode($sep, self::TITLES);
    }
}
