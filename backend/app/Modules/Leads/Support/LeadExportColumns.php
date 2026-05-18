<?php

namespace App\Modules\Leads\Support;

final class LeadExportColumns
{
    /**
     * @return array<string, array{label: string, formatter: string}>
     */
    public static function definitions(): array
    {
        return [
            'id' => ['label' => 'ID', 'formatter' => 'raw'],
            'cpf' => ['label' => 'CPF', 'formatter' => 'cpf_digits'],
            'nome' => ['label' => 'Nome', 'formatter' => 'raw'],
            'created_at' => ['label' => 'Criado em (Lead)', 'formatter' => 'datetime'],
            'updated_at' => ['label' => 'Atualizado em (Lead)', 'formatter' => 'datetime'],
            'data_nascimento' => ['label' => 'Data de Nascimento', 'formatter' => 'date_only'],
            'fone1' => ['label' => 'Telefone 1', 'formatter' => 'raw'],
            'fone2' => ['label' => 'Telefone 2', 'formatter' => 'raw'],
            'fone3' => ['label' => 'Telefone 3', 'formatter' => 'raw'],
            'fone4' => ['label' => 'Telefone 4', 'formatter' => 'raw'],
            'classe_fone1' => ['label' => 'Classe 1', 'formatter' => 'raw'],
            'classe_fone2' => ['label' => 'Classe 2', 'formatter' => 'raw'],
            'classe_fone3' => ['label' => 'Classe 3', 'formatter' => 'raw'],
            'classe_fone4' => ['label' => 'Classe 4', 'formatter' => 'raw'],
            'consulta' => ['label' => 'Motivo (Consulta)', 'formatter' => 'raw'],
            'saldo' => ['label' => 'Saldo (R$)', 'formatter' => 'float'],
            'libera' => ['label' => 'Valor liberado (R$)', 'formatter' => 'float'],
            'ultima_origem_cadastral' => ['label' => 'Última Origem (Cadastral)', 'formatter' => 'raw'],
            'ultima_origem_higienizacao' => ['label' => 'Última Origem (Higienização)', 'formatter' => 'raw'],
            'data_atualizacao' => ['label' => 'Data de Atualização', 'formatter' => 'date'],
            'contracts_count' => ['label' => 'Qtd. de contratos', 'formatter' => 'int'],
            'vendedor' => ['label' => 'Vendedor', 'formatter' => 'raw'],
            'data_contrato_recente' => ['label' => 'Data de Contrato (mais recente)', 'formatter' => 'date_only'],
            'fgts_off_authorized' => ['label' => 'FGTS OFF Autorizado', 'formatter' => 'bool_ptbr'],
            'fgts_off_consultado_em' => ['label' => 'FGTS OFF Consultado em', 'formatter' => 'date'],
            'elegivel' => ['label' => 'CLT Elegível', 'formatter' => 'bool_ptbr'],
            'idade' => ['label' => 'CLT Idade', 'formatter' => 'int'],
            'sexo' => ['label' => 'CLT Sexo', 'formatter' => 'raw'],
            'data_admissao' => ['label' => 'CLT Data de Admissão', 'formatter' => 'date_only'],
            'meses_admissao' => ['label' => 'CLT Tempo de Casa (meses)', 'formatter' => 'int'],
            'valor_renda' => ['label' => 'CLT Renda total (R$)', 'formatter' => 'float'],
            'valor_base_margem' => ['label' => 'CLT Base de margem (R$)', 'formatter' => 'float'],
            'margem_disponivel' => ['label' => 'CLT Margem disponível (R$)', 'formatter' => 'float'],
            'valor_max_prestacao' => ['label' => 'CLT Valor máx. prestação (R$)', 'formatter' => 'float'],
            'categoria_trabalhador_codigo' => ['label' => 'CLT Categoria do Trabalhador', 'formatter' => 'raw'],
            'matricula' => ['label' => 'CLT Matrícula', 'formatter' => 'raw'],
            'inicio_atividade_empregador' => ['label' => 'CLT Início Atividade (Empregador)', 'formatter' => 'date_only'],
            'qtd_emprestimos_ativos_suspensos' => ['label' => 'CLT Qtd. empréstimos ativos/suspensos (qtd)', 'formatter' => 'int'],
            'emprestimos_legados' => ['label' => 'CLT Empréstimos Legados', 'formatter' => 'bool_ptbr'],
            'not_found' => ['label' => 'CLT Não Encontrado', 'formatter' => 'bool_ptbr'],
            'clt_consultado_em' => ['label' => 'CLT Data consulta', 'formatter' => 'date_only'],
            'clt_dados_atualizados_em' => ['label' => 'CLT Data dados', 'formatter' => 'date_only'],
            'politica_credito_aprovado' => ['label' => 'CLT Política de crédito aprovada', 'formatter' => 'bool_ptbr'],
            'politica_credito_mensagem' => ['label' => 'CLT Política de crédito mensagem', 'formatter' => 'raw'],
            'politica_credito_valor_maximo_disponivel' => ['label' => 'CLT Política de crédito valor máximo disponível (R$)', 'formatter' => 'float'],
            'politica_credito_prazo_maximo_disponivel' => ['label' => 'CLT Política de crédito prazo máximo disponível', 'formatter' => 'int'],
            'politica_credito_data_consulta' => ['label' => 'CLT Política de crédito data consulta', 'formatter' => 'date'],
            'politica_credito_tabela_aprovada' => ['label' => 'CLT Política de crédito tabela aprovada', 'formatter' => 'raw'],

            'mercantil_status' => ['label' => 'Mercantil Status', 'formatter' => 'raw'],
            'mercantil_mensagem_erro' => ['label' => 'Mercantil Mensagem', 'formatter' => 'raw'],
            'mercantil_data_hora_origem' => ['label' => 'Mercantil Data/Hora consulta', 'formatter' => 'raw'],
            'mercantil_valor_financiado' => ['label' => 'Mercantil Valor financiado (R$)', 'formatter' => 'float'],
            'mercantil_valor_iof' => ['label' => 'Mercantil Valor IOF (R$)', 'formatter' => 'float'],
            'mercantil_data_primeiro_vencimento' => ['label' => 'Mercantil Data 1º vencimento', 'formatter' => 'date_only'],
            'mercantil_valor_emprestimo' => ['label' => 'Mercantil Valor empréstimo (R$)', 'formatter' => 'float'],
            'mercantil_quantidade_parcelas' => ['label' => 'Mercantil Qtd. parcelas (qtd)', 'formatter' => 'int'],
            'mercantil_valor_liberado' => ['label' => 'Mercantil Valor liberado (R$)', 'formatter' => 'float'],
            'mercantil_taxa_juros_mes' => ['label' => 'Mercantil Taxa juros (% a.m.)', 'formatter' => 'float'],
            'mercantil_valor_parcela' => ['label' => 'Mercantil Valor parcela (R$)', 'formatter' => 'float'],
            'ultima_origem_mercantil' => ['label' => 'Última Origem (Mercantil)', 'formatter' => 'raw'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allowed(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];
        foreach (self::definitions() as $column => $meta) {
            $labels[$column] = $meta['label'];
        }

        return $labels;
    }

    public static function formatterFor(string $column): string
    {
        return self::definitions()[$column]['formatter'] ?? 'raw';
    }
}
