<?php

namespace App\Exports;

use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class FgtsOfflineExport implements FromGenerator, WithHeadings, ShouldAutoSize, WithEvents
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
                    // ❌ yield self::mapRow($assoc);
                    // ✅ deixe o mapping para generator()
                    yield $assoc;
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
}
