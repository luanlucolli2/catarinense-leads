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

class FgtsOfflineExport implements
    FromGenerator,
    WithHeadings,
    ShouldAutoSize,
    WithEvents,
    WithColumnFormatting
{
    /**
     * Ordem canônica das colunas geradas (e também cabeçalho do CSV spool).
     */
    public const COLS = [
        'cpf',
        'autorizado',
        'autorizadoAte',
        'mensagem',
        'status',        // ← HTTP status do header
        'consultadoEm',  // ← data/hora da consulta
    ];

    /**
     * Cabeçalhos visíveis no Excel (um-para-um com COLS).
     */
    private const HEADERS = [
        'CPF',
        'Autorizado',
        'Autorizado até',
        'Mensagem',
        'Status',
        'Consultado em',
    ];

    /** @var callable():Generator */
    private $rowIteratorFactory;

    private function __construct(callable $rowIteratorFactory)
    {
        $this->rowIteratorFactory = $rowIteratorFactory;
    }

    /**
     * Constrói um export que lê diretamente de um CSV (spool) usando ';' como separador.
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
                    yield $assoc; // mapping acontece em generator()
                }
            } finally {
                fclose($fh);
            }
        });
    }

    /**
     * Constrói um export a partir de um gerador custom (ex.: spool + pendentes).
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
     * Converte uma linha associativa para a ordem do Excel + formatações.
     * Retorna array indexado na mesma ordem do headings().
     */
    private static function mapRow(array $row): array
    {
        $out = [];
        foreach (self::COLS as $key) {
            $val = $row[$key] ?? null;

            // CPF como número (sem zeros à esquerda)
            if ($key === 'cpf') {
                $digits = preg_replace('/\D+/', '', (string) $val);
                $digits = ltrim($digits ?? '', '0');
                if ($digits === '') {
                    $digits = '0';
                }
                if (PHP_INT_SIZE >= 8) {
                    $val = (int) $digits;
                } else {
                    $val = (float) $digits;
                }
            }

            // Autorizado como "Sim"/"Não"
            if ($key === 'autorizado') {
                $norm = is_string($val) ? mb_strtolower(trim($val), 'UTF-8') : $val;
                if ($val === true || $val === 1 || $norm === '1' || $norm === 'sim' || $norm === 'true') {
                    $val = 'Sim';
                } elseif ($val === false || $val === 0 || $norm === '0' || $norm === 'nao' || $norm === 'não' || $norm === 'false') {
                    $val = 'Não';
                }
            }

            // ===== Datas → converter para serial numérico do Excel =====
            // C) autorizadoAte: dd/mm/yyyy
            if ($key === 'autorizadoAte' && is_string($val) && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', trim($val))) {
                try {
                    $dt = Carbon::createFromFormat('d/m/Y', trim($val));
                    if ($dt instanceof Carbon) {
                        $val = ExcelDate::PHPToExcel($dt->toDateTime());
                    }
                } catch (\Throwable) {
                    // mantém valor original se não conseguir converter
                }
            }

            // F) consultadoEm: dd/mm/yyyy HH:ii:ss
            if ($key === 'consultadoEm' && is_string($val) && preg_match('/^\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}:\d{2}$/', trim($val))) {
                try {
                    $dt = Carbon::createFromFormat('d/m/Y H:i:s', trim($val), 'America/Sao_Paulo');
                    if ($dt instanceof Carbon) {
                        // Mantemos o horário local; Excel armazena apenas um serial
                        $val = ExcelDate::PHPToExcel($dt->toDateTime());
                    }
                } catch (\Throwable) {
                    // mantém valor original se não conseguir converter
                }
            }

            $out[] = $val;
        }
        return $out;
    }

    /**
     * Estilos: alinhamento geral, cabeçalho em negrito
     * e coluna A (CPF) como número inteiro.
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

                // Coluna A (CPF) como inteiro (evita notação científica/zeros à esquerda)
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
     * Formatação de colunas (aplicada quando as células têm valor numérico/serial Excel).
     */
    public function columnFormats(): array
    {
        return [
            // C: autorizadoAte → dd/mm/yyyy
            'C' => NumberFormat::FORMAT_DATE_DDMMYYYY,
            // F: consultadoEm → dd/mm/yyyy hh:mm:ss
            'F' => 'dd/mm/yyyy hh:mm:ss',
        ];
    }
}
