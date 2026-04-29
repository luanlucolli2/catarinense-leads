<?php

namespace App\Modules\CLT\Support;

final class CltSchema
{
    private const DECIMAL_FORMATTED_COLS = [
        'valorTotalVencimentos',
        'valorBaseMargem',
        'valorMargemDisponivel',
        'valorMaximoPrestacao',
        'politicaCreditoValorMaximoDisponivel',
    ];

    private const INTEGER_FORMATTED_COLS = [
        'idade',
        'tempoAdmissaoMeses',
        'politicaCreditoPrazoMaximoDisponivel',
        'numeroVinculos',
        'mesesEmpresaEmpregador',
        'qtdEmprestimosAtivosSuspensos',
    ];

    private const BOOLEAN_TEXT_COLS = [
        'elegivel',
    ];

    /**
     * Chaves canônicas (ordem das colunas no spool/CSV).
     * Mantém exatamente a mesma ordem que era usada no COLS do export antigo.
     */
    public const COLS = [
        'cpf',
        'nome',
        'elegivel',
        'politicaCreditoAprovado',
        'politicaCreditoMensagem',
        'politicaCreditoValorMaximoDisponivel',
        'politicaCreditoPrazoMaximoDisponivel',
        'politicaCreditoDataConsulta',
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
        'mesesEmpresaEmpregador',
        'possuiAlertas',
        'qtdEmprestimosAtivosSuspensos',
        'emprestimosLegados',
        'pessoaExpostaPoliticamente_descricao',
        'status_code',
        'mensagem',
        // NOVAS COLUNAS DE DATA
        'updated_at',
        'consulted_at',
        'fonteConsulta',
        'politicaCreditoTabelaAprovada',
    ];

    /**
     * Títulos normalizados/capitalizados para cabeçalho (1–1 com COLS).
     */
    public const TITLES = [
        'CPF',
        'Nome',
        'Elegível',
        'Política de Crédito Aprovado',
        'Política de Crédito Mensagem',
        'Política de Crédito Valor Máximo Disponível',
        'Política de Crédito Prazo Máximo Disponível',
        'Política de Crédito Data da Consulta',
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
        'Meses da Empresa (Empregador)',
        'Possui Alertas',
        'Qtde Empréstimos Ativos/Suspensos',
        'Empréstimos Legados',
        'Pessoa Exposta Politicamente',
        'Status Code',
        'Mensagem',
        'Data de Atualização (Origem)',
        'Data da Consulta',
        'Fonte da Consulta',
        'Política de Crédito Tabela Aprovada',
    ];

    public static function headerCsvLine(string $sep = ';'): string
    {
        return implode($sep, self::TITLES);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function normalizeAssocRowForCsv(array $row): array
    {
        foreach (self::numericColumnFormatMap() as $col => $type) {
            if (array_key_exists($col, $row)) {
                $row[$col] = self::normalizeNumericCsvValue($row[$col], $type);
            }
        }

        foreach (self::BOOLEAN_TEXT_COLS as $col) {
            if (array_key_exists($col, $row)) {
                $row[$col] = self::normalizeBooleanCsvValue($row[$col]);
            }
        }

        return $row;
    }

    /**
     * @param array<int,mixed> $row
     * @return array<int,mixed>
     */
    public static function normalizeOrderedRowForCsv(array $row): array
    {
        foreach (self::numericColumnIndexFormatMap() as $idx => $type) {
            if (array_key_exists($idx, $row)) {
                $row[$idx] = self::normalizeNumericCsvValue($row[$idx], $type);
            }
        }

        foreach (self::booleanTextColumnIndexes() as $idx) {
            if (array_key_exists($idx, $row)) {
                $row[$idx] = self::normalizeBooleanCsvValue($row[$idx]);
            }
        }

        return $row;
    }

    /**
     * @return array<int,int>
     */
    private static function numericColumnIndexFormatMap(): array
    {
        static $indexMap = null;

        if (is_array($indexMap)) {
            return $indexMap;
        }

        $lookup = array_flip(self::COLS);
        $indexMap = [];

        foreach (self::numericColumnFormatMap() as $col => $type) {
            if (isset($lookup[$col])) {
                $indexMap[(int) $lookup[$col]] = $type;
            }
        }

        return $indexMap;
    }

    /**
     * @return array<int,int>
     */
    private static function booleanTextColumnIndexes(): array
    {
        static $indexes = null;

        if (is_array($indexes)) {
            return $indexes;
        }

        $lookup = array_flip(self::COLS);
        $indexes = [];

        foreach (self::BOOLEAN_TEXT_COLS as $col) {
            if (isset($lookup[$col])) {
                $indexes[] = (int) $lookup[$col];
            }
        }

        return $indexes;
    }

    /**
     * @return array<string,string>
     */
    private static function numericColumnFormatMap(): array
    {
        static $map = null;

        if (is_array($map)) {
            return $map;
        }

        $map = [];
        foreach (self::DECIMAL_FORMATTED_COLS as $col) {
            $map[$col] = 'decimal';
        }
        foreach (self::INTEGER_FORMATTED_COLS as $col) {
            $map[$col] = 'integer';
        }

        return $map;
    }

    private static function normalizeNumericCsvValue(mixed $value, string $type): mixed
    {
        $number = self::parseNumericLikeValue($value);
        if ($number === null) {
            return $value;
        }

        if (abs($number) < 0.000000001) {
            return '0';
        }

        if ($type === 'integer') {
            return (string) (int) round($number);
        }

        return str_replace('.', ',', number_format(round($number, 2), 2, '.', ''));
    }

    private static function parseNumericLikeValue(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_float($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $candidate = preg_replace('/[^\d,.\-+]/', '', $trimmed);
        if (!is_string($candidate) || $candidate === '' || $candidate === '-' || $candidate === '+') {
            return null;
        }

        $lastComma = strrpos($candidate, ',');
        $lastDot = strrpos($candidate, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $candidate = str_replace('.', '', $candidate);
                $candidate = str_replace(',', '.', $candidate);
            } else {
                $candidate = str_replace(',', '', $candidate);
            }
        } elseif ($lastComma !== false) {
            $candidate = str_replace('.', '', $candidate);
            $candidate = str_replace(',', '.', $candidate);
        } else {
            $candidate = str_replace(',', '', $candidate);
        }

        if (!is_numeric($candidate)) {
            return null;
        }

        return (float) $candidate;
    }

    private static function normalizeBooleanCsvValue(mixed $value): mixed
    {
        $bool = self::parseBooleanLikeValue($value);
        if ($bool === null) {
            return $value;
        }

        return $bool ? 'SIM' : 'NÃO';
    }

    private static function parseBooleanLikeValue(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
            $number = (int) $value;
            if ($number === 1) {
                return true;
            }
            if ($number === 0) {
                return false;
            }
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $upper = function_exists('mb_strtoupper')
            ? mb_strtoupper($trimmed, 'UTF-8')
            : strtoupper($trimmed);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $upper);
        if ($ascii === false || $ascii === null) {
            $ascii = $upper;
        }
        $ascii = preg_replace('/\s+/', '', $ascii);

        if (in_array($ascii, ['SIM', 'S', 'TRUE', 'T', 'YES', 'Y', '1'], true)) {
            return true;
        }

        if (in_array($ascii, ['NAO', 'N', 'FALSE', 'F', 'NO', '0'], true)) {
            return false;
        }

        return null;
    }
}
