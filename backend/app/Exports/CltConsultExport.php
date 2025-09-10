<?php

namespace App\Exports;

use Generator;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CltConsultExport implements
    FromGenerator,
    WithHeadings,
    ShouldAutoSize,
    WithEvents,
    WithColumnFormatting
{
    /**
     * Chaves canônicas (ordem exata dos dados exportados).
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

    /** @var callable():Generator */
    private $rowIteratorFactory;

    private function __construct(callable $rowIteratorFactory)
    {
        $this->rowIteratorFactory = $rowIteratorFactory;
    }

    /**
     * Lê diretamente de um CSV (spool) usando ';' como separador.
     */
    public static function fromCsv(string $csvFullPath): self
    {
        return new self(function () use ($csvFullPath): Generator {
            $fh = fopen($csvFullPath, 'r');
            if ($fh === false) {
                return;
            }
            try {
                // pula cabeçalho
                $header = fgetcsv($fh, 0, ';');
                while (($data = fgetcsv($fh, 0, ';')) !== false) {
                    $assoc = [];
                    foreach (self::COLS as $i => $key) {
                        $assoc[$key] = $data[$i] ?? null;
                    }
                    yield $assoc;
                }
            } finally {
                fclose($fh);
            }
        });
    }

    /**
     * Constrói a partir de um Generator custom (spool + pendentes).
     */
    public static function fromGenerator(callable $rowIteratorFactory): self
    {
        return new self($rowIteratorFactory);
    }

    public function headings(): array
    {
        return self::HEADERS;
    }

    public function generator(): Generator
    {
        $it = ($this->rowIteratorFactory)();
        foreach ($it as $row) {
            yield self::mapRow($row);
        }
    }

    /**
     * Converte linha associativa para a ordem do Excel + formatações.
     */
    private static function mapRow(array $row): array
    {
        $out = [];
        foreach (self::COLS as $key) {
            $val = $row[$key] ?? null;

            // CPF → numérico (sem zeros à esquerda)
            if ($key === 'cpf') {
                $digits = preg_replace('/\D+/', '', (string) $val);
                $digits = ltrim($digits ?? '', '0');
                if ($digits === '')
                    $digits = '0';
                $val = PHP_INT_SIZE >= 8 ? (int) $digits : (float) $digits;
            }

            // Datas dd/mm/yyyy → serial Excel
            if (
                in_array($key, ['dataNascimento', 'dataAdmissao', 'dataDesligamento', 'dataInicioAtividadeEmpregador'], true)
                && is_string($val)
                && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', trim($val))
            ) {
                try {
                    $dt = Carbon::createFromFormat('d/m/Y', trim($val));
                    if ($dt instanceof Carbon) {
                        $val = ExcelDate::PHPToExcel($dt->toDateTime());
                    }
                } catch (\Throwable) {
                    // mantém o valor original se falhar
                }
            }

            $out[] = $val;
        }
        return $out;
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
                $highestRow = $sheet->getHighestRow();

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
     * Colunas de data.
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
