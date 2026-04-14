<?php

namespace App\Modules\Presenca\Support;

final class PresencaSchema
{
    public const COLS = [
        'cpf',
        'nome',
        'vinculo_elegivel',
        'margem_valor_disponivel',
        'margem_valor_base',
        'margem_valor_total_devido',
        'margem_registro_empregaticio',
        'margem_cnpj_empregador',
        'margem_data_admissao',
        'margem_data_nascimento',
        'margem_nome_mae',
        'margem_sexo',
        'simulacao_id',
        'simulacao_nome',
        'simulacao_prazo',
        'simulacao_taxa_juros',
        'simulacao_valor_liberado',
        'simulacao_valor_parcela',
        'simulacao_tipo_credito',
        'simulacao_type',
        'simulacao_taxa_seguro',
        'simulacao_valor_seguro',
        'status',
        'status_code',
        'mensagem',
        'consulted_at',
    ];

    public const TITLES = [
        'CPF',
        'Nome',
        'Vinculo Elegivel',
        'Margem Disponivel',
        'Margem Base',
        'Margem Total Devido',
        'Registro Empregaticio',
        'CNPJ Empregador',
        'Data Admissao',
        'Data Nascimento',
        'Nome Mae',
        'Sexo',
        'Simulacao ID',
        'Simulacao Nome',
        'Simulacao Prazo',
        'Simulacao Taxa Juros',
        'Simulacao Valor Liberado',
        'Simulacao Valor Parcela',
        'Simulacao Tipo Credito',
        'Simulacao Type',
        'Simulacao Taxa Seguro',
        'Simulacao Valor Seguro',
        'Status',
        'Status Code',
        'Mensagem',
        'Consultado em',
    ];

    public static function headerCsvLine(string $sep = ';'): string
    {
        return implode($sep, self::TITLES);
    }
}
