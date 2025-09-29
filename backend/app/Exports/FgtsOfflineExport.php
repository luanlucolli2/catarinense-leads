<?php

namespace App\Exports;

use Generator;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\BeforeExport;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class FgtsOfflineExport implements FromGenerator, WithHeadings, WithColumnFormatting, WithEvents
{
    public const COLS = [
        'cpf',
        'autorizado',
        'autorizadoAte',
        'mensagem',
        'status',
        'consultadoEm',
    ];

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

    private static function mapRow(array $row): array
    {
        $out = [];
        foreach (self::COLS as $key) {
            $val = $row[$key] ?? null;

            if ($key === 'cpf') {
                $digits = preg_replace('/\D+/', '', (string) $val);
                $digits = ltrim($digits ?? '', '0');
                if ($digits === '') $digits = '0';
                $val = PHP_INT_SIZE >= 8 ? (int) $digits : (float) $digits;
            }

            if ($key === 'autorizado') {
                $norm = is_string($val) ? mb_strtolower(trim($val), 'UTF-8') : $val;
                if ($val === true || $val === 1 || $norm === '1' || $norm === 'sim' || $norm === 'true') {
                    $val = 'Sim';
                } elseif ($val === false || $val === 0 || $norm === '0' || $norm === 'nao' || $norm === 'não' || $norm === 'false') {
                    $val = 'Não';
                }
            }

            if ($key === 'autorizadoAte' && is_string($val) && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', trim($val))) {
                try {
                    $dt = Carbon::createFromFormat('d/m/Y', trim($val));
                    if ($dt instanceof Carbon) {
                        $val = ExcelDate::PHPToExcel($dt->toDateTime());
                    }
                } catch (\Throwable) {}
            }

            if ($key === 'consultadoEm' && is_string($val) && preg_match('/^\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}:\d{2}$/', trim($val))) {
                try {
                    $dt = Carbon::createFromFormat('d/m/Y H:i:s', trim($val), 'America/Sao_Paulo');
                    if ($dt instanceof Carbon) {
                        $val = ExcelDate::PHPToExcel($dt->toDateTime());
                    }
                } catch (\Throwable) {}
            }

            $out[] = $val;
        }
        return $out;
    }

    public function columnFormats(): array
    {
        return [
            'A' => '0',                                 // CPF como inteiro
            'C' => NumberFormat::FORMAT_DATE_DDMMYYYY,  // autorizadoAte
            'F' => 'dd/mm/yyyy hh:mm:ss',               // consultadoEm
        ];
    }

    /** Habilita inline strings no writer (reduz SharedStrings). */
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
