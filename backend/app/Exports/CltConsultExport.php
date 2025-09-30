<?php

namespace App\Exports;

use Generator;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\BeforeExport;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class CltConsultExport implements
    FromGenerator,
    WithHeadings,
    WithEvents,
    WithColumnFormatting
{
    /** Ordem exata das colunas */
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

    /** Cabeçalhos 1–1 com COLS */
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

    /** Lê direto do CSV spool (“;”) */
    public static function fromCsv(string $csvFullPath): self
    {
        return new self(function () use ($csvFullPath): Generator {
            $fh = fopen($csvFullPath, 'r');
            if ($fh === false) return;
            try {
                fgetcsv($fh, 0, ';'); // cabeçalho
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

    /** Constrói a partir de um generator custom */
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

    /** Mapeia a linha e aplica conversões (CPF/ Datas) */
    private static function mapRow(array $row): array
    {
        $out = [];
        foreach (self::COLS as $key) {
            $val = $row[$key] ?? null;

            // CPF → numérico (sem zeros à esquerda)
            if ($key === 'cpf') {
                $digits = preg_replace('/\D+/', '', (string) $val);
                $digits = ltrim($digits ?? '', '0');
                if ($digits === '') $digits = '0';
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
                } catch (\Throwable) {}
            }

            $out[] = $val;
        }
        return $out;
    }

    /** Somente formatos de coluna (sem estilos) */
    public function columnFormats(): array
    {
        return [
            'A' => '0',                                 // CPF inteiro
            'D' => NumberFormat::FORMAT_DATE_DDMMYYYY,  // dataNascimento
            'G' => NumberFormat::FORMAT_DATE_DDMMYYYY,  // dataAdmissao
            'S' => NumberFormat::FORMAT_DATE_DDMMYYYY,  // dataDesligamento
            'W' => NumberFormat::FORMAT_DATE_DDMMYYYY,  // dataInicioAtividadeEmpregador
        ];
    }

    /** Ativa inline strings no writer (menos SharedStrings = menos RAM) */
    public function registerEvents(): array
    {
        return [
            BeforeExport::class => function (BeforeExport $event) {
                $delegate = method_exists($event->writer, 'getDelegate')
                    ? $event->writer->getDelegate()
                    : null;

                if ($delegate instanceof \PhpOffice\PhpSpreadsheet\Writer\Xlsx
                    && method_exists($delegate, 'setUseInlineStrings')) {
                    $delegate->setUseInlineStrings(true);
                }
            },
        ];
    }
}
