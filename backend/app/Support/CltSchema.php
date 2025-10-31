<?php

namespace App\Support;

final class CltSchema
{
    /**
     * Chaves canônicas (ordem das colunas no spool/CSV).
     * Mantém exatamente a mesma ordem que era usada no COLS do export antigo.
     */
    public const COLS = [
        'cpf',
        'nome',
        'elegivel',
        'dataNascimento',
        'idade',
        'sexo_descricao',
        'dataAdmissao',
        'tempoAdmissaoMeses',
        'valorTotalVencimentos',
        'valorBaseMargem',
        'valorMargemDisponivel',
        'valorMaximoPrestacao',
        'codigoCategoriaTrabalhador',
        'numeroVinculos',
        'nomeEmpregador',
        'numeroInscricaoEmpregador',
        'inscricaoEmpregador_descricao',
        'matricula',
        'dataDesligamento',
        'codigoMotivoDesligamento',
        'cbo_descricao',
        'cnae_descricao',
        'dataInicioAtividadeEmpregador',
        'possuiAlertas',
        'qtdEmprestimosAtivosSuspensos',
        'emprestimosLegados',
        'pessoaExpostaPoliticamente_descricao',
        'status_code',
        'mensagem',
    ];

    /**
     * Títulos normalizados/capitalizados para cabeçalho (1–1 com COLS).
     */
    public const TITLES = [
        'CPF',
        'Nome',
        'Elegível',
        'Data de Nascimento',
        'Idade (anos)',
        'Sexo',
        'Data de Admissão',
        'Meses de Admissão',
        'Valor da Renda',
        'Valor Base da Margem',
        'Margem Disponível',
        'Valor Máximo da Prestação',
        'Categoria do Trabalhador (código)',
        'Nº de Vínculos',
        'Nome do Empregador',
        'Nº Inscrição do Empregador',
        'Tipo de Inscrição do Empregador',
        'Matrícula',
        'Data de Desligamento',
        'Motivo do Desligamento (código)',
        'CBO (descrição)',
        'CNAE (descrição)',
        'Início da Atividade do Empregador',
        'Possui Alertas',
        'Qtde Empréstimos Ativos/Suspensos',
        'Empréstimos Legados',
        'Pessoa Exposta Politicamente',
        'Status Code',
        'Mensagem',
    ];

    public static function headerCsvLine(string $sep = ';'): string
    {
        return implode($sep, self::TITLES);
    }
}
