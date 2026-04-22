<?php

namespace App\Modules\Presenca\Support;

final class PresencaSchema
{
    public const COLS = [
        'cpf',
        'nome',
        'margem_data_nascimento',
        'margem_nome_mae',
        'margem_sexo',
        'margem_registro_empregaticio',
        'margem_cnpj_empregador',
        'margem_data_admissao',
        'vinculo_elegivel',
        'margem_valor_disponivel',
        'margem_valor_base',
        'margem_valor_total_devido',
        'simulacao_taxa_juros',
        'simulacao_valor_liberado',
        'simulacao_valor_parcela',
        'simulacao_taxa_seguro',
        'simulacao_valor_seguro',
        'simulacao_id',
        'simulacao_nome',
        'simulacao_prazo',
        'simulacao_tipo_credito',
        'simulacao_type',
        'status',
        'mensagem',
        'status_code',
        'consulted_at',
    ];

    public const TITLES = [
        'CPF',
        'Nome',
        'Data Nascimento',
        'Nome Mae',
        'Sexo',
        'Registro Empregaticio',
        'CNPJ Empregador',
        'Data Admissao',
        'Vinculo Elegivel',
        'Margem Disponivel',
        'Margem Base',
        'Margem Total Devido',
        'Simulacao Taxa Juros',
        'Simulacao Valor Liberado',
        'Simulacao Valor Parcela',
        'Simulacao Taxa Seguro',
        'Simulacao Valor Seguro',
        'Simulacao ID',
        'Simulacao Nome',
        'Simulacao Prazo',
        'Simulacao Tipo Credito',
        'Simulacao Type',
        'Status',
        'Mensagem',
        'Status Code',
        'Consultado em',
    ];

    public static function headerCsvLine(string $sep = ';'): string
    {
        return implode($sep, self::TITLES);
    }
}
