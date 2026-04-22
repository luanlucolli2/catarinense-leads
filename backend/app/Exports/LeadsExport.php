<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Export orientado a CSV:
 * - Datas como strings "dd/MM/yyyy"
 * - Números normalizados (ponto decimal)
 * - CPF como dígitos SEM zeros à esquerda (string)
 */
class LeadsExport implements FromQuery, WithHeadings, WithMapping
{
    protected Builder $query;
    protected array $columns;

    public function __construct(Builder $query, array $columns)
    {
        $this->query   = $query;
        $this->columns = $columns;
    }

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        $map = [
            // básicos
            'id'                         => 'ID',
            'cpf'                        => 'CPF',
            'nome'                       => 'Nome',
            'data_nascimento'            => 'Data de Nascimento',
            'fone1'                      => 'Telefone 1',
            'fone2'                      => 'Telefone 2',
            'fone3'                      => 'Telefone 3',
            'fone4'                      => 'Telefone 4',
            'classe_fone1'               => 'Classe 1',
            'classe_fone2'               => 'Classe 2',
            'classe_fone3'               => 'Classe 3',
            'classe_fone4'               => 'Classe 4',

            // FGTS
            'consulta'                   => 'Motivo (Consulta)',
            'saldo'                      => 'Saldo',
            'libera'                     => 'Libera',
            'ultima_origem_cadastral'    => 'Última Origem (Cadastral)',
            'ultima_origem_higienizacao' => 'Última Origem (Higienização)',
            'data_atualizacao'           => 'Data de Atualização',
            'contracts_count'            => 'Qtde de Contratos',
            'vendedor'                   => 'Vendedor',
            'data_contrato_recente'      => 'Data de Contrato (mais recente)',

            // FGTS OFF
            'fgts_off_authorized'        => 'FGTS OFF Autorizado',
            'fgts_off_consultado_em'     => 'FGTS OFF Consultado em',

            // CLT
            'elegivel'                          => 'CLT Elegível',
            'idade'                             => 'CLT Idade',
            'sexo'                              => 'CLT Sexo',
            'data_admissao'                     => 'CLT Data de Admissão',
            'meses_admissao'                    => 'CLT Tempo de Casa (meses)',
            'valor_renda'                       => 'CLT Renda Total',
            'valor_base_margem'                 => 'CLT Base de Margem',
            'margem_disponivel'                 => 'CLT Margem Disponível',
            'valor_max_prestacao'               => 'CLT Valor Máx. Prestação',
            'categoria_trabalhador_codigo'      => 'CLT Categoria do Trabalhador',
            'inicio_atividade_empregador'       => 'CLT Início Atividade (Empregador)',
            'qtd_emprestimos_ativos_suspensos'  => 'CLT Qtde Empréstimos Ativos/Suspensos',
            'emprestimos_legados'               => 'CLT Empréstimos Legados',
            'not_found'                         => 'CLT Não Encontrado',
            'clt_consultado_em'                 => 'CLT Data consulta',
            'clt_dados_atualizados_em'          => 'CLT Data dados',
        ];

        return array_map(static fn($c) => $map[$c] ?? $c, $this->columns);
    }

    public function map($lead): array
    {
        $row = [];

        foreach ($this->columns as $col) {
            switch ($col) {
                case 'cpf':
                    $row[] = $this->cpfDigits($lead->cpf);
                    break;

                // datas FGTS
                case 'data_atualizacao':
                case 'data_nascimento':
                case 'data_contrato_recente':
                    $row[] = $this->formatDate($lead->{$col}, in_array($col, ['data_nascimento','data_contrato_recente'], true));
                    break;

                // números FGTS
                case 'saldo':
                case 'libera':
                    $row[] = $this->toFloat($lead->{$col});
                    break;

                case 'contracts_count':
                    $row[] = isset($lead->contracts_count) ? (int) $lead->contracts_count : null;
                    break;

                // FGTS OFF
                case 'fgts_off_authorized':
                    $val   = $lead->fgts_off_authorized;
                    $row[] = $val === null ? null : ($val ? 'Sim' : 'Não');
                    break;
                case 'fgts_off_consultado_em':
                    $row[] = $this->formatDate($lead->fgts_off_consultado_em);
                    break;

                // ===== CLT =====
                case 'elegivel':
                case 'not_found':
                case 'emprestimos_legados':
                    $v     = $lead->{$col};
                    $row[] = $v === null ? null : ($v ? 'Sim' : 'Não');
                    break;

                case 'data_admissao':
                case 'inicio_atividade_empregador':
                case 'clt_consultado_em':
                case 'clt_dados_atualizados_em':
                    $row[] = $this->formatDate($lead->{$col}, true);
                    break;

                case 'valor_renda':
                case 'valor_base_margem':
                case 'margem_disponivel':
                case 'valor_max_prestacao':
                    $row[] = $this->toFloat($lead->{$col});
                    break;

                case 'meses_admissao':
                case 'idade':
                case 'qtd_emprestimos_ativos_suspensos':
                    $row[] = isset($lead->{$col}) ? (int) $lead->{$col} : null;
                    break;

                default:
                    $row[] = $lead->{$col};
            }
        }

        return $row;
    }

    private function formatDate($value, bool $isDateOnly = false): ?string
    {
        if (empty($value)) return null;

        try {
            $dt = $value instanceof \DateTimeInterface
                ? Carbon::instance($value)
                : Carbon::parse((string) $value);

            if ($isDateOnly) $dt = $dt->startOfDay();
            return $dt->format('d/m/Y');
        } catch (\Throwable) {
            return null;
        }
    }

    private function toFloat($val): ?float
    {
        if ($val === null || $val === '') return null;

        $s = preg_replace('/[^0-9.,-]/', '', (string)$val);
        if ($s === '') return null;

        $lastDot   = strrpos($s, '.');
        $lastComma = strrpos($s, ',');

        if ($lastDot === false && $lastComma === false) {
            return is_numeric($s) ? (float)$s : null;
        }

        $decimalSep  = ($lastDot !== false && $lastComma !== false)
            ? (($lastDot > $lastComma) ? '.' : ',')
            : (($lastDot !== false) ? '.' : ',');

        $thousandSep = ($decimalSep === '.') ? ',' : '.';

        $normalized = str_replace($thousandSep, '', $s);
        $normalized = str_replace($decimalSep, '.', $normalized);

        if (substr_count($normalized, '.') > 1) {
            $normalized = preg_replace('/\.(?=.*\.)/', '', $normalized);
        }

        return is_numeric($normalized) ? (float)$normalized : null;
    }

    private function cpfDigits($val): ?string
    {
        if ($val === null || $val === '') return null;

        $digits = preg_replace('/\D+/', '', (string)$val) ?? '';
        $digits = ltrim($digits, '0');
        if ($digits === '') $digits = '0';

        // retorna string para evitar notação científica ao abrir no Excel
        return $digits;
    }
}
