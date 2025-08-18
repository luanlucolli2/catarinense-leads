<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class CltConsultExport implements FromArray, WithHeadings, ShouldAutoSize, WithEvents
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
        'tempoAdmissaoMeses',                 // "Meses de Admissão"
        'valorTotalVencimentos',              // "Valor da Renda"
        'valorBaseMargem',
        'valorMargemDisponivel',
        'valorMaximoPrestacao',               // "Valor Máximo da Prestação"
        'numeroVinculos',                     // Nº de Vínculos
        'nomeEmpregador',
        'numeroInscricaoEmpregador',
        'inscricaoEmpregador_descricao',
        'matricula',
        'dataDesligamento',
        'codigoMotivoDesligamento',
        'codigoCategoriaTrabalhador',
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
        'Nº de Vínculos',
        'Nome do Empregador',
        'Nº Inscrição do Empregador',
        'Tipo de Inscrição do Empregador',
        'Matrícula',
        'Data de Desligamento',
        'Motivo do Desligamento (código)',
        'Categoria do Trabalhador (código)',
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
        // Mapeia cada linha associativa para o array na ordem de COLS
        $out = [];
        foreach ($this->rows as $row) {
            $out[] = array_map(
                fn (string $key) => $row[$key] ?? null,
                self::COLS
            );
        }
        return $out;
    }

    /**
     * Centraliza horizontal e verticalmente todas as células
     * e deixa o cabeçalho (linha 1) em negrito.
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $highestColumn = $sheet->getHighestColumn();
                $highestRow    = $sheet->getHighestRow();

                // Estilo geral (todas as células)
                $fullRange = "A1:{$highestColumn}{$highestRow}";
                $sheet->getStyle($fullRange)
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT) 
                    ->setVertical(Alignment::VERTICAL_CENTER);

                // Cabeçalho em negrito
                $headerRange = "A1:{$highestColumn}1";
                $sheet->getStyle($headerRange)->getFont()->setBold(true);
            },
        ];
    }
}
