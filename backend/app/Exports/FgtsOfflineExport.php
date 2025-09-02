<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class FgtsOfflineExport implements FromArray, WithHeadings, ShouldAutoSize, WithEvents
{
    /**
     * Linhas vindas do Job (associativas).
     * @var array<int, array<string, string|int|float|bool|null>>
     */
    private array $rows;

    /**
     * Ordem canônica das colunas geradas.
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

                // CPF como número (sem zeros à esquerda)
                if ($key === 'cpf') {
                    $digits = preg_replace('/\D+/', '', (string) $val);
                    $digits = ltrim($digits ?? '', '0');
                    if ($digits === '') {
                        $digits = '0';
                    }
                    // int em 64-bit; float em 32-bit
                    if (PHP_INT_SIZE >= 8) {
                        $val = (int) $digits;
                    } else {
                        $val = (float) $digits;
                    }
                }

                // Autorizado como "Sim"/"Não" (se vier bool ou 0/1)
                if ($key === 'autorizado') {
                    if (is_bool($val)) {
                        $val = $val ? 'Sim' : 'Não';
                    } elseif ($val === 1 || $val === '1') {
                        $val = 'Sim';
                    } elseif ($val === 0 || $val === '0') {
                        $val = 'Não';
                    }
                    // caso venha string já legível, mantém
                }

                $mapped[] = $val;
            }
            $out[] = $mapped;
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
