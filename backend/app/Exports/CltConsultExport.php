<?php

namespace App\Exports;

use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CltConsultExport implements FromArray, WithHeadings, ShouldAutoSize, WithEvents, WithColumnFormatting
{
    /**
     * Linhas vindas do Job (associativas).
     * @var array<int, array<string, string|int|float|null>>
     */
    private array $rows;

    /**
     * Chaves canônicas (ordem exata dos dados exportados).
     * Nomes das chaves devem bater com o que o Job preenche.
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
     * Cabeçalhos visíveis no Excel (um-para-um com COLS).
     */
    private const HEADERS = [
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

    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function headings(): array
    {
        return self::HEADERS;
    }

    public function array(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $mapped = [];
            foreach (self::COLS as $key) {
                $val = $row[$key] ?? null;

                // CPF → numérico (sem zeros à esquerda)
                if ($key === 'cpf') {
                    $digits = preg_replace('/\D+/', '', (string) $val);
                    $digits = ltrim($digits ?? '', '0');
                    if ($digits === '') {
                        $digits = '0';
                    }
                    $val = PHP_INT_SIZE >= 8 ? (int) $digits : (float) $digits;
                }

                // Datas → converter para serial Excel
                if (in_array($key, ['dataNascimento', 'dataAdmissao', 'dataDesligamento', 'dataInicioAtividadeEmpregador'], true)) {
                    $val = $this->toExcelDate($val);
                }

                $mapped[] = $val;
            }
            $out[] = $mapped;
        }
        return $out;
    }

    /**
     * Converte strings "dd/mm/yyyy" ou objetos DateTime/Carbon em serial Excel.
     * Retorna null se inválido.
     *
     * @param mixed $value
     * @return float|int|null
     */
    private function toExcelDate(mixed $value): float|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Já veio como DateTime/Carbon?
        if ($value instanceof \DateTimeInterface) {
            return ExcelDate::PHPToExcel($value);
        }

        // String "dd/mm/yyyy"
        if (is_string($value)) {
            $v = trim($value);
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $v)) {
                try {
                    $dt = Carbon::createFromFormat('d/m/Y', $v);
                    if ($dt instanceof Carbon) {
                        return ExcelDate::PHPToExcel($dt->toDateTime());
                    }
                } catch (\Throwable) {
                    // deixa cair para null
                }
            }
        }

        return null;
    }

    /**
     * Estilos gerais.
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $highestColumn = $sheet->getHighestColumn();
                $highestRow    = $sheet->getHighestRow();

                // Estilo geral
                $fullRange = "A1:{$highestColumn}{$highestRow}";
                $sheet->getStyle($fullRange)
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                    ->setVertical(Alignment::VERTICAL_CENTER);

                // Cabeçalho em negrito
                $headerRange = "A1:{$highestColumn}1";
                $sheet->getStyle($headerRange)->getFont()->setBold(true);

                // Coluna A (CPF) como inteiro
                if ($highestRow >= 2) {
                    $cpfRange = "A2:A{$highestRow}";
                    $sheet->getStyle($cpfRange)
                        ->getNumberFormat()
                        ->setFormatCode('0');
                }
            },
        ];
    }

    /**
     * Formatação das colunas de data.
     * D = dataNascimento
     * G = dataAdmissao
     * S = dataDesligamento
     * W = dataInicioAtividadeEmpregador
     */
    public function columnFormats(): array
    {
        return [
            'D' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'G' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'S' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            'W' => NumberFormat::FORMAT_DATE_DDMMYYYY,
        ];
    }
}
