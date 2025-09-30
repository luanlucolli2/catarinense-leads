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
    // ↓ NOVO layout (compacto): remove "autorizadoAte", "mensagem", "status" e renomeia "autorizado" → "situacao"
    public const COLS = [
        'cpf',
        'situacao',
        'consultadoEm',
    ];

    private const HEADERS = [
        'CPF',
        'Situação',
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
            if ($fh === false)
                return;
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
                if ($digits === '')
                    $digits = '0';
                $val = PHP_INT_SIZE >= 8 ? (int) $digits : (float) $digits; // mantém performance e compatibilidade
            }

            if ($key === 'consultadoEm' && is_string($val) && preg_match('/^\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}:\d{2}$/', trim($val))) {
                try {
                    $dt = Carbon::createFromFormat('d/m/Y H:i:s', trim($val), 'America/Sao_Paulo');
                    if ($dt instanceof Carbon) {
                        $val = ExcelDate::PHPToExcel($dt->toDateTime());
                    }
                } catch (\Throwable) {
                }
            }

            // 'situacao' já vem como string final ("Autorizado", "Não autorizado", "Não autorizado - ...")
            $out[] = $val;
        }
        return $out;
    }

    public function columnFormats(): array
    {
        // A=CPF (inteiro), B=Situação (texto), C=Data/Hora
        return [
            'A' => '0',
            'C' => 'dd/mm/yyyy hh:mm:ss',
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

                if (
                    $delegate instanceof \PhpOffice\PhpSpreadsheet\Writer\Xlsx
                    && method_exists($delegate, 'setUseInlineStrings')
                ) {
                    $delegate->setUseInlineStrings(true);
                }
            },
        ];
    }
}
